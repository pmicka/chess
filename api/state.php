<?php
/**
 * /api/state.php — Read-only game state endpoint
 *
 * Intent:
 * - Provide the public canonical state of the current game as JSON:
 *   - current FEN (board position + turn)
 *   - PGN (move history)
 *   - host color and visitor color
 *   - whose turn it is
 *   - status (active/finished)
 *
 * Consumers:
 * - index.php (public visitors)
 * - my_move.php (host token page)
 *
 * Rules:
 * - No secrets in output.
 * - No writes to DB.
 * - Should set Content-Type: application/json.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../db.php';

try {
    $db = get_db();
    $stmt = $db->query("
        SELECT id, host_color AS you_color, visitor_color, turn_color, status, fen, pgn, last_move_san, updated_at
        FROM games
        ORDER BY updated_at DESC
        LIMIT 1
    ");
    $game = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$game) {
        echo json_encode(['error' => 'No game found. Run init_db.php.']);
        exit;
    }

    // Cast id to int for cleaner JSON.
    $game['id'] = (int)$game['id'];

    echo json_encode($game);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Unable to load game state.']);
}
