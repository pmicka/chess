<?php
/**
 * /api/admin_reset.php — Reset the active game to the starting position.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$providedSecret = $_POST['secret'] ?? ($_GET['secret'] ?? '');
if (defined('ADMIN_RESET_KEY') && ADMIN_RESET_KEY !== '') {
    if (!is_string($providedSecret) || !hash_equals(ADMIN_RESET_KEY, $providedSecret)) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }
}

$db = null;

try {
    $db = get_db();
    $db->beginTransaction();

    $initialFen = starting_fen();
    $hostColor = 'white';
    $visitorColor = 'black';

    $gameStmt = $db->query("SELECT id FROM games WHERE status = 'active' ORDER BY updated_at DESC LIMIT 1");
    $gameRow = $gameStmt->fetch(PDO::FETCH_ASSOC);

    if ($gameRow) {
        $gameId = (int)$gameRow['id'];

        $update = $db->prepare("
            UPDATE games
            SET host_color = :host_color,
                visitor_color = :visitor_color,
                turn_color = :turn_color,
                status = 'active',
                fen = :fen,
                pgn = '',
                last_move_san = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");

        $update->execute([
            ':host_color' => $hostColor,
            ':visitor_color' => $visitorColor,
            ':turn_color' => $hostColor,
            ':fen' => $initialFen,
            ':id' => $gameId,
        ]);

        $db->prepare('DELETE FROM locks WHERE game_id = :id')->execute([':id' => $gameId]);
        $db->prepare('DELETE FROM tokens WHERE game_id = :id')->execute([':id' => $gameId]);
    } else {
        $insert = $db->prepare("
            INSERT INTO games (host_color, visitor_color, turn_color, status, fen, pgn, last_move_san, updated_at)
            VALUES (:host_color, :visitor_color, :turn_color, 'active', :fen, '', NULL, CURRENT_TIMESTAMP)
        ");
        $insert->execute([
            ':host_color' => $hostColor,
            ':visitor_color' => $visitorColor,
            ':turn_color' => $hostColor,
            ':fen' => $initialFen,
        ]);
        $gameId = (int)$db->lastInsertId();
    }

    $tokenInfo = ensure_host_move_token($db, $gameId);

    $db->commit();

    echo json_encode([
        'ok' => true,
        'game_id' => $gameId,
        'host_token' => $tokenInfo['token'] ?? null,
        'token_expires_at' => ($tokenInfo['expires_at'] instanceof DateTimeInterface)
            ? $tokenInfo['expires_at']->format('Y-m-d H:i:s T')
            : null,
    ]);
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Reset failed.']);
}
