<?php
/**
 * /api/host_next_game.php — Host-controlled start of next game after game over.
 */

header('Content-Type: application/json');

function respond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

try {
    require_once __DIR__ . '/../db.php';
} catch (Throwable $e) {
    respond(503, ['ok' => false, 'error' => $e->getMessage(), 'code' => 'config']);
}
log_db_path_info('host_next_game');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'POST required']);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = [];
}

$tokenValue = trim($data['token'] ?? '');
if ($tokenValue === '') {
    respond(401, ['ok' => false, 'error' => 'Token missing', 'code' => 'AUTH_MISSING']);
}

$db = null;

try {
    $db = get_db();
    $db->beginTransaction();

    $validation = validate_host_token($db, $tokenValue, true, false);
    if (($validation['ok'] ?? false) !== true) {
        $db->rollBack();
        $code = $validation['code'] ?? 'invalid';
        $http = $code === 'expired' ? 410 : 403;
        respond($http, ['ok' => false, 'error' => 'Invalid token', 'code' => $code]);
    }

    $tokenRow = $validation['row'];
    $stmt = $db->prepare("
        SELECT id, host_color, visitor_color, turn_color, status, fen, pgn, last_move_san, updated_at
        FROM games
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $tokenRow['game_id']]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$game) {
        $db->rollBack();
        respond(400, ['ok' => false, 'error' => 'Game not found', 'code' => 'game_not_found']);
    }

    $gameId = (int)$game['id'];
    $status = detect_game_over_from_fen($game['fen']);
    $isOver = ($status['ok'] ?? false) && ($status['over'] ?? false);

    if (($game['status'] ?? '') === 'active' && !$isOver) {
        $db->rollBack();
        respond(400, ['ok' => false, 'error' => 'Game is not over yet.', 'code' => 'not_over']);
    }

    finish_game($db, $gameId);

    $nextGame = create_next_game($db, $game);

    $db->commit();

    respond(200, [
        'ok' => true,
        'new_game_id' => $nextGame['id'],
        'host_color' => $nextGame['host_color'],
        'visitor_color' => $nextGame['visitor_color'],
        'turn_color' => $nextGame['turn_color'],
        'host_token' => $nextGame['host_token'],
        'token_expires_at' => $nextGame['token_expires_at'] instanceof DateTimeInterface
            ? $nextGame['token_expires_at']->format('Y-m-d H:i:s T')
            : null,
        'message' => 'Next game created.',
    ]);
} catch (RuntimeException $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    respond(503, ['ok' => false, 'error' => $e->getMessage(), 'code' => 'db_path']);
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    respond(500, ['ok' => false, 'error' => 'Failed to start next game.']);
}
