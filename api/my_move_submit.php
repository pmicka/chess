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

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../db.php';
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
log_db_path_info('my_move_submit');

// MVP: no token required. We only accept POST requests with JSON (or form) payload.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);
if (!is_array($data)) {
    $data = [];
}

$action = strtolower(trim($data['action'] ?? 'submit')) ?: 'submit';
$tokenValue = trim($data['token'] ?? '');
$from = strtolower(trim($data['from'] ?? ''));
$to = strtolower(trim($data['to'] ?? ''));
$promotion = strtolower(trim($data['promotion'] ?? ''));
$move = trim($data['move'] ?? '');
$lastKnownUpdatedAt = trim($data['last_known_updated_at'] ?? '');
$clientFen = isset($data['client_fen']) ? trim($data['client_fen']) : null;

if (isset($data['fen']) || isset($data['pgn'])) {
    http_response_code(400);
    echo json_encode(['error' => 'FEN/PGN are server-managed. Submit coordinates only.']);
    exit;
}

if ($tokenValue === '' && $action !== 'resend') {
    http_response_code(401);
    echo json_encode(['error' => 'Token missing', 'code' => 'AUTH_MISSING']);
    exit;
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
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'No active host turn available.']);
            exit;
        }

        $expiry = default_host_token_expiry();
        $freshToken = insert_host_move_token($db, (int)$game['id'], $expiry);

        $db->commit();

        $emailResult = send_host_turn_email((int)$game['id'], $freshToken, $expiry, $game['last_move_san'] ?? 'n/a');

        $response = [
            'ok' => ($emailResult['ok'] ?? false) === true,
            'token' => $freshToken,
            'message' => ($emailResult['ok'] ?? false) === true ? 'Sent.' : 'Email failed.',
        ];

        if (($emailResult['ok'] ?? false) !== true) {
            $response['error'] = $emailResult['warning'] ?? 'Failed to send email.';
        }

        echo json_encode($response);
        exit;
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
        http_response_code($http);
        echo json_encode([
            'error' => $code === 'expired' ? 'Token expired' : 'Invalid token',
            'code' => $code === 'expired' ? 'AUTH_EXPIRED' : 'AUTH_INVALID',
        ]);
        exit;
    }

    $tokenRow = $validation['row'];
    error_log(sprintf(
        'my_move_submit auth token_present=1 valid=1 code=%s path=%s',
        $validation['code'] ?? 'ok',
        $_SERVER['REQUEST_URI'] ?? 'n/a'
    ));

    if ($from === '' || $to === '' || $lastKnownUpdatedAt === '') {
        $db->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'Missing required fields (from, to, last_known_updated_at).']);
        exit;
    }

    if ($move === '') {
        $db->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'Move notation is required.']);
        exit;
    }

    $allowedPromotions = ['q', 'r', 'b', 'n'];
    if ($promotion !== '' && !in_array($promotion, $allowedPromotions, true)) {
        $db->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'Invalid promotion piece. Use q, r, b, or n.']);
        exit;
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
        http_response_code(400);
        echo json_encode(['error' => 'No active game found.']);
        exit;
    }

    $currentStatus = detect_game_over_from_fen($game['fen']);
    if (($game['status'] ?? '') !== 'active' || (($currentStatus['ok'] ?? false) && ($currentStatus['over'] ?? false))) {
        finish_game($db, (int)$game['id']);
        $db->commit();
        http_response_code(409);
        echo json_encode(['error' => 'Game is over. Waiting for next game.', 'code' => 'GAME_OVER']);
        exit;
    }

    if ($game['turn_color'] !== $game['host_color']) {
        $db->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'It is not the host turn.']);
        exit;
    }

    log_event('host_move_attempt', [
        'ip' => client_ip(),
        'game_id' => $game['id'] ?? null,
        'turn' => $game['turn_color'] ?? null,
        'token_suffix' => token_suffix($tokenValue),
        'from' => $from,
        'to' => $to,
        'client_fen_len' => $clientFen !== null ? strlen($clientFen) : null,
    ]);

    error_log(sprintf(
        'my_move_submit game_found=1 game_id=%d fen_length=%d',
        (int)$game['id'],
        isset($game['fen']) ? strlen((string)$game['fen']) : 0
    ));

    if ($lastKnownUpdatedAt === '' || $lastKnownUpdatedAt !== ($game['updated_at'] ?? '')) {
        $db->rollBack();
        http_response_code(409);
        echo json_encode(['error' => 'Server state changed. Refresh and try again.']);
        exit;
    }

    try {
        $newFen = apply_move_to_fen($game['fen'], $from, $to, $promotion, $game['turn_color']);
    } catch (Throwable $e) {
        $db->rollBack();
        http_response_code(400);
        $msg = $e->getMessage();
        $clientMessage = stripos((string)$msg, 'promotion') !== false ? $msg : 'Illegal move or invalid coordinates.';
        echo json_encode(['error' => $clientMessage]);
        exit;
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
        'my_move_submit write game=%d fen_len=%d client_fen_len=%s',
        $game['id'],
        strlen($newFen),
        $clientFen !== null ? strlen($clientFen) : 'n/a'
    ));

    echo json_encode([
        'ok' => true,
        'game_id' => (int)$game['id'],
        'next_turn' => $nextTurn,
        'message' => 'Move accepted. Visitors may move now.',
    ]);
} catch (RuntimeException $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(503);
    echo json_encode(['error' => $e->getMessage()]);
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save move.']);
}
