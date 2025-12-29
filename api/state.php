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

header('X-Robots-Tag: noindex');
header('Content-Type: application/json');

function respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

try {
    require_once __DIR__ . '/../db.php';
} catch (Throwable $e) {
    respond(503, ['ok' => false, 'message' => $e->getMessage(), 'code' => 'config']);
}

log_db_path_info('state.php');

try {
    $db = get_db();
    error_log('state.php db_open=ok');
} catch (RuntimeException $e) {
    error_log('state.php db_path=fail: ' . $e->getMessage());
    respond(503, ['ok' => false, 'message' => $e->getMessage(), 'code' => 'db_path']);
} catch (Throwable $e) {
    error_log('state.php db_open=fail: ' . $e->getMessage());
    respond(500, ['ok' => false, 'message' => 'Database unavailable.', 'code' => 'db_open']);
}

$tokenProvided = array_key_exists('token', $_GET);
$tokenValue = isset($_GET['token']) ? trim($_GET['token']) : '';
$tokenGameId = null;
$tokenStatus = 'missing';
$tokenValid = false;

if ($tokenProvided && $tokenValue === '') {
    error_log(sprintf(
        'state.php auth token_present=1 valid=0 code=missing path=%s',
        $_SERVER['REQUEST_URI'] ?? 'n/a'
    ));
    respond(401, [
        'ok' => false,
        'message' => 'Token missing.',
        'code' => 'AUTH_MISSING',
    ]);
}

if ($tokenValue !== '') {
    $validation = validate_host_token($db, $tokenValue, true, false);
    $tokenStatus = $validation['code'] ?? 'unknown';
    $tokenValid = ($validation['ok'] ?? false) === true;

    if (!$tokenValid) {
        $httpCode = $tokenStatus === 'expired' ? 410 : 403;
        error_log(sprintf(
            'state.php auth token_present=1 valid=0 code=%s path=%s',
            $tokenStatus,
            $_SERVER['REQUEST_URI'] ?? 'n/a'
        ));
        respond($httpCode, [
            'ok' => false,
            'message' => $tokenStatus === 'expired' ? 'Token expired.' : 'Invalid host token.',
            'code' => $tokenStatus === 'expired' ? 'AUTH_EXPIRED' : 'AUTH_INVALID',
        ]);
    }

    $tokenRow = $validation['row'] ?? null;
    $tokenGameId = $tokenRow ? (int)$tokenRow['game_id'] : null;
    error_log(sprintf(
        'state.php auth token_present=1 valid=1 code=%s path=%s',
        $tokenStatus,
        $_SERVER['REQUEST_URI'] ?? 'n/a'
    ));
} else {
    error_log(sprintf(
        'state.php auth token_present=0 valid=0 code=%s path=%s',
        $tokenStatus,
        $_SERVER['REQUEST_URI'] ?? 'n/a'
    ));
}

if ($tokenGameId !== null) {
    $stmt = $db->prepare("
        SELECT id, host_color AS you_color, visitor_color, turn_color, status, fen, pgn, last_move_san, updated_at
        FROM games
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $tokenGameId]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    $stmt = $db->query("
        SELECT id, host_color AS you_color, visitor_color, turn_color, status, fen, pgn, last_move_san, updated_at
        FROM games
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
    ");
    $game = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$game) {
    error_log('state.php game_found=0');
    respond(500, ['ok' => false, 'message' => 'No game found. Run init_db.php.', 'code' => 'game_not_found']);
}

$fen = isset($game['fen']) ? trim((string)$game['fen']) : '';
error_log(sprintf(
    'state.php game_found=1 game_id=%d fen_length=%d',
    (int)$game['id'],
    strlen($fen)
));

if ($fen === '') {
    respond(500, ['ok' => false, 'message' => 'FEN unavailable.', 'code' => 'fen_missing']);
}

$turnColor = $game['turn_color'] ?? '';
$turn = strtolower($turnColor) === 'white' ? 'w' : (strtolower($turnColor) === 'black' ? 'b' : null);

$response = [
    'ok' => true,
    'id' => (int)$game['id'],
    'fen' => $fen,
    'turn' => $turn,
    'turn_color' => $turnColor,
    'you_color' => $game['you_color'] ?? null,
    'visitor_color' => $game['visitor_color'] ?? null,
    'status' => $game['status'] ?? null,
    'pgn' => $game['pgn'] ?? '',
    'last_move_san' => $game['last_move_san'] ?? null,
    'updated_at' => $game['updated_at'] ?? null,
    'message' => '',
];

respond(200, $response);
