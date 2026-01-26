<?php
/**
 * /api/my_move_submit.php — Accept a host move (token-gated)
 *
 * Intent:
 * - Allow the host to submit their move using a single-use token.
 * - Update game state, flip turn back to visitors.
 *
 * Required checks (server-side):
 * - Token MUST exist, match purpose, not be used, not be expired.
 * - Game MUST be active and must match token's game_id.
 * - It MUST be the host's turn.
 *
 * Data accepted (MVP):
 * - token
 * - updated FEN/PGN
 * - move notation (SAN or coordinate)
 *
 * Optional behavior:
 * - "End game" action (later: checkmate/stalemate/resign) creates a new game
 *   and flips host color for the next game.
 *
 * Security:
 * - No CAPTCHA required here; the token is the gate.
 * - Token should be marked used immediately on success.
 */

header('X-Robots-Tag: noindex, nofollow', true);
header('Content-Type: application/json');
require_once __DIR__ . '/../lib/http.php';

try {
    require_once __DIR__ . '/../db.php';
} catch (Throwable $e) {
    respond_json(503, ['ok' => false, 'error' => $e->getMessage(), 'code' => 'config']);
}
log_db_path_info('my_move_submit');
require_post();

$input = file_get_contents('php://input');
$data = json_decode($input, true);
if (!is_array($data)) {
    $data = [];
}

function resend_throttle_path(): string
{
    return __DIR__ . '/../data/resend_throttle.json';
}

function enforce_resend_cooldown(int $gameId, int $cooldownSeconds = 60): array
{
    $path = resend_throttle_path();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $fp = @fopen($path, 'c+');
    if ($fp === false) {
        return ['ok' => true];
    }

    $now = time();
    $data = [];

    if (flock($fp, LOCK_EX)) {
        $raw = stream_get_contents($fp);
        if ($raw !== false && strlen($raw) > 0) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        $key = (string)$gameId;
        $lastTs = isset($data[$key]['ts']) ? (int)$data[$key]['ts'] : 0;
        $elapsed = $lastTs > 0 ? ($now - $lastTs) : $cooldownSeconds + 1;
        if ($elapsed < $cooldownSeconds) {
            $remaining = max(1, $cooldownSeconds - $elapsed);
            flock($fp, LOCK_UN);
            fclose($fp);
            return ['ok' => false, 'remaining' => $remaining];
        }

        $data[$key] = ['ts' => $now];
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($data));
        flock($fp, LOCK_UN);
    }

    fclose($fp);
    return ['ok' => true];
}

$action = strtolower(clean_string($data['action'] ?? 'submit', 16)) ?: 'submit';
$tokenValue = clean_string($data['token'] ?? '', 256);
$from = clean_square($data['from'] ?? '');
$to = clean_square($data['to'] ?? '');
$promotion = clean_promotion($data['promotion'] ?? '');
$move = clean_string($data['move'] ?? '', 32);
$lastKnownUpdatedAt = clean_string($data['last_known_updated_at'] ?? '', 64);

if (isset($data['fen']) || isset($data['pgn'])) {
    respond_json(400, ['ok' => false, 'error' => 'FEN/PGN are server-managed. Submit coordinates only.', 'code' => 'server_managed_fields']);
}

if ($tokenValue === '' && $action !== 'resend') {
    respond_json(401, ['ok' => false, 'error' => 'Token missing', 'code' => 'AUTH_MISSING']);
}

$db = null;

try {
    $db = get_db();

    if ($action === 'resend') {
        $db->beginTransaction();

        $tokenRow = null;
        if ($tokenValue !== '') {
            $validation = validate_host_token($db, $tokenValue, true, true);
            if (($validation['ok'] ?? false) === true && isset($validation['row'])) {
                $tokenRow = $validation['row'];
            }
        }

        $game = null;
        if ($tokenRow) {
            $stmt = $db->prepare("
                SELECT id, host_color, visitor_color, turn_color, status, last_move_san
                FROM games
                WHERE id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $tokenRow['game_id']]);
            $game = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$game || ($game['status'] ?? '') !== 'active' || ($game['turn_color'] ?? null) !== ($game['host_color'] ?? null)) {
            $fallback = $db->query("
                SELECT id, host_color, visitor_color, turn_color, status, last_move_san
                FROM games
                WHERE status = 'active' AND host_color = turn_color
                ORDER BY updated_at DESC
                LIMIT 1
            ");
            $game = $fallback->fetch(PDO::FETCH_ASSOC);
        }

        if (!$game) {
            $db->rollBack();
            respond_json(400, ['ok' => false, 'error' => 'No active host turn available.', 'code' => 'host_turn_missing']);
        }

        $cooldown = enforce_resend_cooldown((int)$game['id'], 60);
        if (($cooldown['ok'] ?? false) !== true) {
            $db->rollBack();
            $remaining = isset($cooldown['remaining']) ? (int)$cooldown['remaining'] : 60;
            $message = sprintf('Please wait %ds before requesting another link.', $remaining);
            respond_json(429, [
                'ok' => false,
                'error' => $message,
                'message' => $message,
                'remaining_seconds' => $remaining,
                'code' => 'resend_cooldown',
            ]);
        }

        $expiry = default_host_token_expiry();
        $freshToken = insert_host_move_token($db, (int)$game['id'], $expiry);

        $db->commit();

        $emailResult = send_host_turn_email((int)$game['id'], $freshToken, $expiry, $game['last_move_san'] ?? 'n/a');

        $response = [
            'ok' => ($emailResult['ok'] ?? false) === true,
            'token' => $freshToken,
            'message' => ($emailResult['ok'] ?? false) === true ? 'Sent.' : 'Email failed.',
            'code' => ($emailResult['ok'] ?? false) === true ? 'sent' : 'email_failed',
        ];

        if (($emailResult['ok'] ?? false) !== true) {
            $response['error'] = $emailResult['warning'] ?? 'Failed to send email.';
        }

        $statusCode = ($emailResult['ok'] ?? false) === true ? 200 : 500;
        respond_json($statusCode, $response);
    }

    $db->beginTransaction();

    $validation = validate_host_token($db, $tokenValue);
    if (($validation['ok'] ?? false) !== true) {
        $db->rollBack();
        $code = $validation['code'] ?? 'invalid';
        $http = $code === 'expired' ? 410 : 403;
        error_log(sprintf(
            'my_move_submit auth token_present=1 valid=0 code=%s path=%s',
            $code,
            $_SERVER['REQUEST_URI'] ?? 'n/a'
        ));
        log_event('host_move_auth_fail', [
            'token_suffix' => token_suffix($tokenValue),
            'code' => $code,
            'ip' => client_ip(),
        ]);
        respond_json($http, [
            'error' => $code === 'expired' ? 'Token expired' : 'Invalid token',
            'code' => $code === 'expired' ? 'AUTH_EXPIRED' : 'AUTH_INVALID',
        ]);
    }

    $tokenRow = $validation['row'];
    error_log(sprintf(
        'my_move_submit auth token_present=1 valid=1 code=%s path=%s',
        $validation['code'] ?? 'ok',
        $_SERVER['REQUEST_URI'] ?? 'n/a'
    ));

    if ($from === '' || $to === '' || $lastKnownUpdatedAt === '') {
        $db->rollBack();
        respond_json(400, ['ok' => false, 'error' => 'Missing required fields (from, to, last_known_updated_at).', 'code' => 'missing_fields']);
    }

    if ($move === '') {
        $db->rollBack();
        respond_json(400, ['ok' => false, 'error' => 'Move notation is required.', 'code' => 'move_missing']);
    }

    $allowedPromotions = ['q', 'r', 'b', 'n'];
    if ($promotion !== '' && !in_array($promotion, $allowedPromotions, true)) {
        $db->rollBack();
        respond_json(400, ['ok' => false, 'error' => 'Invalid promotion piece. Use q, r, b, or n.', 'code' => 'promotion_invalid']);
    }

    $stmt = $db->prepare("
        SELECT id, host_color, visitor_color, turn_color, status, pgn, fen, updated_at
        FROM games
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $tokenRow['game_id']]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$game) {
        error_log('my_move_submit game_found=0');
        $db->rollBack();
        respond_json(400, ['ok' => false, 'error' => 'No active game found.', 'code' => 'game_missing']);
    }

    $currentStatus = detect_game_over_from_fen($game['fen']);
    if (($game['status'] ?? '') !== 'active' || (($currentStatus['ok'] ?? false) && ($currentStatus['over'] ?? false))) {
        finish_game($db, (int)$game['id']);
        $db->commit();
        respond_json(409, ['ok' => false, 'error' => 'Game is over. Waiting for next game.', 'code' => 'GAME_OVER']);
    }

    if ($game['turn_color'] !== $game['host_color']) {
        $db->rollBack();
        respond_json(400, ['ok' => false, 'error' => 'It is not the host turn.', 'code' => 'turn_mismatch']);
    }

    log_event('host_move_attempt', [
        'ip' => client_ip(),
        'game_id' => $game['id'] ?? null,
        'turn' => $game['turn_color'] ?? null,
        'token_suffix' => token_suffix($tokenValue),
        'from' => $from,
        'to' => $to,
    ]);

    error_log(sprintf(
        'my_move_submit game_found=1 game_id=%d fen_length=%d',
        (int)$game['id'],
        isset($game['fen']) ? strlen((string)$game['fen']) : 0
    ));

    if ($lastKnownUpdatedAt === '' || $lastKnownUpdatedAt !== ($game['updated_at'] ?? '')) {
        $db->rollBack();
        respond_json(409, ['ok' => false, 'error' => 'Server state changed. Refresh and try again.', 'code' => 'stale_state']);
    }

    try {
        $newFen = apply_move_to_fen($game['fen'], $from, $to, $promotion, $game['turn_color']);
    } catch (Throwable $e) {
        $db->rollBack();
        $msg = $e->getMessage();
        $clientMessage = stripos((string)$msg, 'promotion') !== false ? $msg : 'Illegal move or invalid coordinates.';
        respond_json(400, ['ok' => false, 'error' => $clientMessage, 'code' => 'illegal_move']);
    }

    $newPgn = append_pgn_move($game['pgn'] ?? '', $game['host_color'], $move, $newFen);

    // Flip turn to visitors after saving the host move.
    $nextTurn = $game['host_color'] === 'white' ? 'black' : 'white';

    $newStatus = detect_game_over_from_fen($newFen);
    $isOver = ($newStatus['ok'] ?? false) && ($newStatus['over'] ?? false);

    $update = $db->prepare("
        UPDATE games
        SET fen = :fen,
            pgn = :pgn,
            last_move_san = :move,
            turn_color = CASE host_color WHEN 'white' THEN 'black' ELSE 'white' END,
            status = :status,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");

    $update->execute([
        ':fen' => $newFen,
        ':pgn' => $newPgn,
        ':move' => $move,
        ':status' => $isOver ? 'finished' : 'active',
        ':id' => $game['id'],
    ]);

    $markUsed = $db->prepare("
        UPDATE tokens
        SET used = 1, used_at = CURRENT_TIMESTAMP
        WHERE token = :token
    ");
    $markUsed->execute([':token' => $tokenValue]);

    // Clear visitor move lock so the next visitor turn can acquire it.
    $db->prepare("DELETE FROM locks WHERE game_id = :id")->execute([':id' => $game['id']]);

    $db->commit();

    error_log(sprintf(
        'my_move_submit write game=%d fen_len=%d',
        $game['id'],
        strlen($newFen)
    ));

    log_event('host_move_commit', [
        'game_id' => $game['id'],
        'turn' => $game['turn_color'],
        'to' => $to,
        'promotion' => $promotion ?: '-',
        'status' => $isOver ? 'finished' : 'active',
        'token_suffix' => token_suffix($tokenValue),
    ]);

    respond_json(200, [
        'ok' => true,
        'game_id' => (int)$game['id'],
        'next_turn' => $nextTurn,
        'message' => 'Move accepted. Visitors may move now.',
    ]);
} catch (RuntimeException $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    log_event('host_move_runtime_error', ['error' => $e->getMessage()]);
    respond_json(503, ['ok' => false, 'error' => $e->getMessage(), 'code' => 'runtime']);
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    log_event('host_move_error', ['error' => $e->getMessage()]);
    respond_json(500, ['ok' => false, 'error' => 'Failed to save move.', 'code' => 'server_error']);
}
