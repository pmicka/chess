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

require_once __DIR__ . '/../db.php';

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
$promotion = strtolower(trim($data['promotion'] ?? 'q'));
$move = trim($data['move'] ?? '');
$lastKnownUpdatedAt = trim($data['last_known_updated_at'] ?? '');
$clientFen = isset($data['client_fen']) ? trim($data['client_fen']) : null;

if (isset($data['fen']) || isset($data['pgn'])) {
    http_response_code(400);
    echo json_encode(['error' => 'FEN/PGN are server-managed. Submit coordinates only.']);
    exit;
}

if ($tokenValue === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Token missing']);
    exit;
}

$db = null;

try {
    $db = get_db();
    $db->beginTransaction();

    $tokenRow = fetch_valid_host_token($db, $tokenValue);
    if (!$tokenRow) {
        $db->rollBack();
        http_response_code(401);
        echo json_encode(['error' => 'Invalid or expired token']);
        exit;
    }

    if ($action === 'resend') {
        $stmt = $db->prepare("
            SELECT id, host_color, visitor_color, turn_color, status, last_move_san
            FROM games
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $tokenRow['game_id']]);
        $game = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$game) {
            $db->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'No active game found.']);
            exit;
        }

        if (($game['status'] ?? '') !== 'active') {
            $db->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'Game is not active.']);
            exit;
        }

        if ($game['turn_color'] !== $game['host_color']) {
            $db->rollBack();
            http_response_code(400);
            echo json_encode(['error' => 'It is not the host turn.']);
            exit;
        }

        $db->commit();

        $expiry = $tokenRow['expires_at_dt'] ?? null;
        $emailResult = send_host_turn_email((int)$game['id'], $tokenValue, $expiry, $game['last_move_san'] ?? 'n/a');

        $response = [
            'ok' => ($emailResult['ok'] ?? false) === true,
            'message' => ($emailResult['ok'] ?? false) === true ? 'Sent.' : 'Failed.',
        ];

        if (($emailResult['ok'] ?? false) !== true && !empty($emailResult['warning'])) {
            $response['error'] = $emailResult['warning'];
        }

        echo json_encode($response);
        exit;
    }

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

    $stmt = $db->prepare("
        SELECT id, host_color, visitor_color, turn_color, status, pgn, fen, updated_at
        FROM games
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $tokenRow['game_id']]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$game) {
        $db->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'No active game found.']);
        exit;
    }

    if (($game['status'] ?? '') !== 'active') {
        $db->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'Game is not active.']);
        exit;
    }

    if ($game['turn_color'] !== $game['host_color']) {
        $db->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'It is not the host turn.']);
        exit;
    }

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
        echo json_encode(['error' => 'Illegal move or invalid coordinates.']);
        exit;
    }

    $newPgn = append_pgn_move($game['pgn'] ?? '', $game['host_color'], $move, $newFen);

    // Flip turn to visitors after saving the host move.
    $nextTurn = $game['host_color'] === 'white' ? 'black' : 'white';

    $update = $db->prepare("
        UPDATE games
        SET fen = :fen,
            pgn = :pgn,
            last_move_san = :move,
            turn_color = CASE host_color WHEN 'white' THEN 'black' ELSE 'white' END,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");

    $update->execute([
        ':fen' => $newFen,
        ':pgn' => $newPgn,
        ':move' => $move,
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
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save move.']);
}
