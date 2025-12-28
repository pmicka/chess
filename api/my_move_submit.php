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

$tokenValue = trim($data['token'] ?? '');
$fen = trim($data['fen'] ?? '');
$pgn = trim($data['pgn'] ?? '');
$move = trim($data['move'] ?? '');

if ($tokenValue === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Token missing']);
    exit;
}

if ($fen === '' || $pgn === '' || $move === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields (fen, pgn, move).']);
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

    $stmt = $db->prepare("
        SELECT id, host_color, visitor_color, turn_color, status
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
        ':fen' => $fen,
        ':pgn' => $pgn,
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
