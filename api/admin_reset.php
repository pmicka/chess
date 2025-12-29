<?php
/**
 * /api/admin_reset.php — Reset the active game to the starting position.
 */

header('X-Robots-Tag: noindex');
header('Content-Type: application/json');

function respond_json(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

try {
    require_once __DIR__ . '/../db.php';
    log_db_path_info('admin_reset');
    ensure_db_path_ready(true);
} catch (Throwable $e) {
    respond_json(503, ['error' => $e->getMessage(), 'code' => 'config']);
}

if (!defined('ADMIN_RESET_KEY') || ADMIN_RESET_KEY === '') {
    respond_json(503, ['error' => 'Server misconfigured: ADMIN_RESET_KEY not set', 'code' => 'admin_key_missing']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$usedKeySource = null;
$providedKey = '';

$headers = function_exists('getallheaders') ? getallheaders() : [];
if (!is_array($headers)) {
    $headers = [];
}

$headerKey = '';
foreach ($headers as $name => $value) {
    if (strcasecmp((string)$name, 'X-Admin-Key') === 0) {
        $headerKey = (string)$value;
        break;
    }
}

if ($headerKey === '' && isset($_SERVER['HTTP_X_ADMIN_KEY'])) {
    $headerKey = (string)$_SERVER['HTTP_X_ADMIN_KEY'];
}

if ($headerKey !== '') {
    $providedKey = $headerKey;
    $usedKeySource = 'header';
} else {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $bodyKey = '';

    if (stripos($contentType, 'application/json') !== false) {
        $rawInput = file_get_contents('php://input');
        $decoded = json_decode($rawInput, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded) && isset($decoded['admin_key'])) {
            $bodyKey = is_scalar($decoded['admin_key']) ? (string)$decoded['admin_key'] : '';
        }
    } else {
        if (isset($_POST['admin_key'])) {
            $bodyKey = is_scalar($_POST['admin_key']) ? (string)$_POST['admin_key'] : '';
        }
    }

    if ($bodyKey !== '') {
        $providedKey = $bodyKey;
        $usedKeySource = 'body';
    }
}

if ($providedKey === '' || $usedKeySource === null) {
    respond_json(401, ['error' => 'Admin key missing', 'code' => 'admin_key_missing']);
}

if (!hash_equals(ADMIN_RESET_KEY, $providedKey)) {
    respond_json(403, ['error' => 'Forbidden', 'code' => 'forbidden']);
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
        'loaded_config' => true,
        'used_key_source' => $usedKeySource,
    ]);
} catch (RuntimeException $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    respond_json(503, ['error' => $e->getMessage(), 'code' => 'db_path']);
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Reset failed.']);
}
