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
 * Data accepted: token, coordinates, explicit promotion when needed, game/revision.
 * Client notation is ignored; FEN/PGN inputs are rejected. The server validates
 * legality and history, generates SAN/state, and records terminal results.
 * A separate purpose-bound capability authorizes the next-game endpoint.
 *
 * Security:
 * - No CAPTCHA required here; the token is the gate.
 * - Token should be marked used immediately on success.
 */

header('X-Robots-Tag: noindex, nofollow', true);
header('Content-Type: application/json');
header('Referrer-Policy: no-referrer');
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/host_recovery.php';

try {
    require_once __DIR__ . '/../db.php';
    require_once __DIR__ . '/../lib/score.php';
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
        return ['ok' => false, 'unavailable' => true];
    }

    $now = time();
    $data = [];

    if (flock($fp, LOCK_EX)) {
        $raw = stream_get_contents($fp);
        if ($raw === false) {
            flock($fp, LOCK_UN);
            fclose($fp);
            return ['ok' => false, 'unavailable' => true];
        }
        if (strlen($raw) > 0) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = $decoded;
            } else {
                flock($fp, LOCK_UN);
                fclose($fp);
                return ['ok' => false, 'unavailable' => true];
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
        $encoded = json_encode($data);
        $saved = $encoded !== false && ftruncate($fp, 0)
            && fwrite($fp, $encoded) === strlen($encoded) && fflush($fp);
        flock($fp, LOCK_UN);
        if (!$saved) {
            fclose($fp);
            return ['ok' => false, 'unavailable' => true];
        }
    } else {
        fclose($fp);
        return ['ok' => false, 'unavailable' => true];
    }

    fclose($fp);
    return ['ok' => true];
}

$action = strtolower(clean_string($data['action'] ?? 'submit', 16)) ?: 'submit';
$tokenValue = clean_string($data['token'] ?? '', 256);
$from = clean_square($data['from'] ?? '');
$to = clean_square($data['to'] ?? '');
$rawPromotion = array_key_exists('promotion', $data) ? $data['promotion'] : '';
$promotion = is_string($rawPromotion) ? strtolower(trim($rawPromotion)) : null;
$gameId = clean_nonnegative_int($data['game_id'] ?? null);
$expectedRevision = clean_nonnegative_int($data['expected_revision'] ?? null);
$submissionId = submission_id($data['submission_id'] ?? null);
$receiptRequest = ['game_id'=>$gameId,'expected_revision'=>$expectedRevision,'from'=>$from,'to'=>$to,'promotion'=>$promotion];

if (isset($data['fen']) || isset($data['pgn'])) {
    respond_json(400, ['ok' => false, 'error' => 'FEN/PGN are server-managed. Submit coordinates only.', 'code' => 'server_managed_fields']);
}

if ($tokenValue === '') {
    respond_json(401, ['ok' => false, 'error' => 'Token missing', 'code' => 'AUTH_MISSING']);
}

$db = null;

try {
    $db = get_db();
    require_phase4($db);
    if ($action === 'receipt') {
        $receipt = host_action_receipt($db, $tokenValue);
        respond_json($receipt === null ? 404 : 200, $receipt ?? ['ok'=>false,'code'=>'receipt_missing']);
    }
    if (!in_array($action, ['submit','resend'], true)) respond_json(400, ['ok'=>false,'code'=>'action_invalid']);

    if ($action === 'resend') {
        begin_write_transaction($db);

        $validation = validate_host_recovery_token($db, $tokenValue);
        if (($validation['ok'] ?? false) !== true) {
            $db->rollBack();
            $expired = ($validation['code'] ?? '') === 'expired';
            respond_json($expired ? 410 : 403, [
                'ok' => false,
                'error' => 'This link cannot request recovery. Open your latest host email or contact the site operator.',
                'code' => $expired ? 'AUTH_EXPIRED' : 'AUTH_INVALID',
            ]);
        }
        $game = $validation['game'];

        $cooldown = enforce_resend_cooldown((int)$game['id'], 60);
        if (($cooldown['ok'] ?? false) !== true) {
            $db->rollBack();
            if (!empty($cooldown['unavailable'])) {
                respond_json(503, [
                    'ok' => false, 'error' => 'Link recovery is temporarily unavailable.',
                    'code' => 'resend_unavailable',
                ]);
            }
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
        $freshToken = insert_host_move_token($db, (int)$game['id'], $expiry, $validation['row']['purpose']);
        $notificationId = enqueue_host_notification($db, (int)$game['id'], $freshToken);
        $db->commit();
        $notification = try_dispatch_notification($db, $notificationId);
        respond_json(200, ['ok'=>true, 'notification'=>$notification, 'code'=>$notification === 'accepted' ? 'sent' : 'queued',
            'message'=>$notification === 'accepted' ? 'Email submitted. Open the new email to continue.' : 'Email queued for retry. Your game is unchanged.']);
    }

    begin_write_transaction($db);
    $receipt = move_receipt($db, 'host', $submissionId, $tokenValue, $receiptRequest);
    if ($receipt !== null) { $db->rollBack(); respond_json(200, $receipt); }

    $validation = validate_host_token($db, $tokenValue);
    if (($validation['ok'] ?? false) !== true) {
        $db->rollBack();
        $code = $validation['code'] ?? 'invalid';
        $http = $code === 'expired' ? 410 : 403;
        error_log(sprintf(
            'my_move_submit auth token_present=1 valid=0 code=%s path=%s',
            $code,
            request_log_path()
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
        request_log_path()
    ));

    if ($from === '' || $to === '' || $gameId === null || $gameId < 1 || $expectedRevision === null) {
        $db->rollBack();
        respond_json(400, ['ok' => false, 'error' => 'Missing required fields (from, to, game_id, expected_revision).', 'code' => 'missing_fields']);
    }

    $allowedPromotions = ['q', 'r', 'b', 'n'];
    if ($promotion === null || ($promotion !== '' && !in_array($promotion, $allowedPromotions, true))) {
        $db->rollBack();
        respond_json(400, ['ok' => false, 'error' => 'Invalid promotion piece. Use q, r, b, or n.', 'code' => 'promotion_invalid']);
    }

    $stmt = $db->prepare("
        SELECT id, host_color, visitor_color, turn_color, status, pgn, fen, revision, updated_at
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

    if ((int)$game['id'] !== $gameId || (int)$game['revision'] !== $expectedRevision) {
        $db->rollBack();
        respond_json(409, ['ok' => false, 'error' => 'Server state changed. Refresh and try again.', 'code' => 'stale_state']);
    }

    if (($game['status'] ?? '') !== 'active') {
        $db->rollBack();
        respond_json(409, ['ok' => false, 'error' => 'Game is over.', 'code' => 'GAME_OVER']);
    }
    $validated = validate_game_move($game, $from, $to, $promotion);
    $currentStatus = $validated['before'];
    if (($currentStatus['ok'] ?? false) && ($currentStatus['over'] ?? false)) {
        finish_game($db, (int)$game['id']);
        record_detected_result($db, $game, $currentStatus);
        $completionExpiry = default_host_token_expiry();
        $completionToken = insert_host_move_token($db, (int)$game['id'], $completionExpiry, 'next_game');
        $notificationId = enqueue_host_notification($db, (int)$game['id'], $completionToken);
        $db->commit();
        try_dispatch_notification($db, $notificationId);
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


    $newFen = $validated['fen'];
    $newPgn = $validated['pgn'];
    $newStatus = $validated['after'];
    $isOver = $newStatus['over'];
    $move = $validated['move']['san'];
    $nextTurn = $validated['turn_color'];

    $update = $db->prepare("
        UPDATE games
        SET fen = :fen,
            pgn = :pgn,
            last_move_san = :move,
            turn_color = :turn_color,
            status = :status,
            revision = revision + 1,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id AND revision = :expected_revision
    ");

    $update->execute([
        ':fen' => $newFen,
        ':pgn' => $newPgn,
        ':move' => $move,
        ':turn_color' => $nextTurn,
        ':status' => $isOver ? 'finished' : 'active',
        ':id' => $game['id'],
        ':expected_revision' => $expectedRevision,
    ]);
    if ($update->rowCount() !== 1) throw new RuntimeException('Revision conflict during move update.');

    $markUsed = $db->prepare("
        UPDATE tokens
        SET used = 1, used_at = CURRENT_TIMESTAMP
        WHERE token = :token
    ");
    $markUsed->execute([':token' => $tokenValue]);
    revoke_game_tokens($db, (int)$game['id']);
    $nextGameToken = null;
    $notificationId = null;
    if ($isOver) {
        record_detected_result($db, $game, $newStatus);
        $nextGameToken = insert_host_move_token($db, (int)$game['id'], default_host_token_expiry(), 'next_game');
        $notificationId = enqueue_host_notification($db, (int)$game['id'], $nextGameToken);
    }

    // Clear visitor move lock so the next visitor turn can acquire it.
    $db->prepare("DELETE FROM locks WHERE game_id = :id")->execute([':id' => $game['id']]);

    $response = ['ok'=>true, 'game_id'=>(int)$game['id'], 'next_turn'=>$nextTurn,
        'revision'=>$expectedRevision + 1, 'next_game_token'=>$nextGameToken,
        'completion_required'=>$isOver, 'message'=>$isOver ? 'Move accepted. Open the completion email to start the next game.' : 'Move accepted. Visitors may move now.'];
    save_move_receipt($db, 'host', $submissionId, $tokenValue, $receiptRequest, $response, (int)$tokenRow['id']);
    $db->commit();
    $response['notification'] = try_dispatch_notification($db, $notificationId);

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

    respond_json(200, $response);
} catch (Phase4MigrationRequired $e) {
    log_event('phase4_migration_required');
    respond_json(503, ['ok'=>false, 'error'=>'Game updates are temporarily unavailable. Please try again later.', 'code'=>'maintenance']);
} catch (ChessStateException $e) {
    if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
    respond_json($e->httpStatus, ['ok' => false, 'error' => $e->getMessage(), 'code' => $e->responseCode]);
} catch (ChessEngineUnavailable $e) {
    if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
    log_event('chess_engine_unavailable');
    respond_json(503, ['ok' => false, 'error' => $e->getMessage(), 'code' => 'chess_unavailable']);
} catch (PDOException $e) {
    if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
    log_event('database_write_error', ['busy' => database_busy($e) ? 1 : 0]);
    if (database_busy($e)) header('Retry-After: 1');
    respond_json(database_busy($e) ? 503 : 500, ['ok' => false, 'error' => 'Database write unavailable. Retry after refreshing.',
        'code' => database_busy($e) ? 'database_busy' : 'database_error']);
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
