<?php
/**
 * /api/admin_reset.php — Reset the active game to the starting position.
 */

header('X-Robots-Tag: noindex');
header('Content-Type: application/json');

require_once __DIR__ . '/../lib/admin_auth.php';

function respond_json(int $status, array $payload): void
{
    global $requestId;
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $payload['request_id'] = $requestId;
    $payload['occurred_at'] = $now->format(DateTimeInterface::ATOM);
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

$requestId = admin_request_id();

try {
    require_once __DIR__ . '/../db.php';
    log_db_path_info('admin_reset');
    ensure_db_path_ready(true);
} catch (Throwable $e) {
    respond_json(503, ['error' => $e->getMessage(), 'code' => 'config']);
}

$adminResetKey = trim(load_admin_reset_key());
$adminResetKeySource = defined('ADMIN_RESET_KEY_SOURCE') ? ADMIN_RESET_KEY_SOURCE : 'default';
if ($adminResetKey === '') {
    $logParts = ['reason' => 'missing_key', 'ip' => client_ip(), 'key_source' => $adminResetKeySource];
    log_event('admin_reset_blocked', $logParts);
    error_log(sprintf('admin_reset missing ADMIN_RESET_KEY source=%s request_id=%s', $adminResetKeySource, $requestId));
    respond_json(503, ['error' => 'Server misconfigured: ADMIN_RESET_KEY not set. Define ADMIN_RESET_KEY in config.php/config.local.php or set the ADMIN_RESET_KEY environment variable.', 'code' => 'admin_key_missing']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    log_event('admin_reset_blocked', ['reason' => 'method_not_post', 'ip' => client_ip()]);
    respond_json(405, ['error' => 'POST required', 'code' => 'method_not_allowed']);
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
    $providedKey = trim((string)$headerKey);
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
        $providedKey = trim((string)$bodyKey);
        $usedKeySource = 'body';
    }
}

if ($providedKey === '' || $usedKeySource === null) {
    log_event('admin_reset_blocked', ['reason' => 'key_missing', 'ip' => client_ip()]);
    respond_json(401, ['error' => 'Admin key missing', 'code' => 'admin_key_missing']);
}

if (!hash_equals($adminResetKey, $providedKey)) {
    log_event('admin_reset_blocked', ['reason' => 'invalid_key', 'ip' => client_ip(), 'key_source' => $usedKeySource, 'config_source' => $adminResetKeySource]);
    error_log(sprintf(
        'admin_reset key_mismatch source=%s provided_source=%s request_id=%s',
        $adminResetKeySource,
        $usedKeySource ?? 'unknown',
        $requestId
    ));
    respond_json(403, ['error' => 'Admin key invalid. Reference the request ID when reporting this error.', 'code' => 'forbidden']);
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

    log_event('admin_reset_success', [
        'game_id' => $gameId,
        'token_suffix' => token_suffix($tokenInfo['token'] ?? ''),
        'key_source' => $usedKeySource,
        'ip' => client_ip(),
    ]);

    respond_json(200, [
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
    respond_json(500, ['error' => 'Reset failed.', 'code' => 'reset_failed']);
}
