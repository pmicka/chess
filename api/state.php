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

require_once __DIR__ . '/../lib/http.php';

function respond(int $statusCode, array $payload): void
{
    respond_json($statusCode, $payload);
}

try {
    require_once __DIR__ . '/../db.php';
    require_once __DIR__ . '/../lib/score.php';
} catch (Throwable $e) {
    respond(503, ['ok' => false, 'message' => $e->getMessage(), 'code' => 'config']);
}

log_db_path_info('state.php');

try {
    $db = get_db();
    log_event('state_db_open', ['result' => 'ok']);
} catch (RuntimeException $e) {
    log_event('state_db_open', ['result' => 'db_path', 'error' => $e->getMessage()]);
    respond(503, ['ok' => false, 'message' => $e->getMessage(), 'code' => 'db_path']);
} catch (Throwable $e) {
    log_event('state_db_open', ['result' => 'fail', 'error' => $e->getMessage()]);
    respond(500, ['ok' => false, 'message' => 'Database unavailable.', 'code' => 'db_open']);
}

$tokenProvided = array_key_exists('token', $_GET);
$tokenValue = isset($_GET['token']) ? trim($_GET['token']) : '';
$tokenGameId = null;
$tokenStatus = 'missing';
$tokenValid = false;
$requestId = request_id();

if ($tokenProvided && $tokenValue === '') {
    log_event('state_auth', [
        'request_id' => $requestId,
        'valid' => 0,
        'code' => 'missing',
        'path' => $_SERVER['REQUEST_URI'] ?? 'n/a',
    ]);
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
        log_event('state_auth', [
            'request_id' => $requestId,
            'valid' => 0,
            'code' => $tokenStatus,
            'path' => $_SERVER['REQUEST_URI'] ?? 'n/a',
        ]);
        respond($httpCode, [
            'ok' => false,
            'message' => $tokenStatus === 'expired' ? 'Token expired.' : 'Invalid host token.',
            'code' => $tokenStatus === 'expired' ? 'AUTH_EXPIRED' : 'AUTH_INVALID',
        ]);
    }

    $tokenRow = $validation['row'] ?? null;
    $tokenGameId = $tokenRow ? (int)$tokenRow['game_id'] : null;
    log_event('state_auth', [
        'request_id' => $requestId,
        'valid' => 1,
        'code' => $tokenStatus,
        'path' => $_SERVER['REQUEST_URI'] ?? 'n/a',
        'game_id' => $tokenGameId,
    ]);
} else {
    log_event('state_auth', [
        'request_id' => $requestId,
        'valid' => 0,
        'code' => $tokenStatus,
        'path' => $_SERVER['REQUEST_URI'] ?? 'n/a',
    ]);
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
    log_event('state_load', ['request_id' => $requestId, 'found' => 0]);
    respond(500, ['ok' => false, 'message' => 'No game found. Run init_db.php.', 'code' => 'game_not_found']);
}

$fen = isset($game['fen']) ? trim((string)$game['fen']) : '';
log_event('state_load', [
    'request_id' => $requestId,
    'found' => 1,
    'game_id' => (int)$game['id'],
    'fen_length' => strlen($fen),
]);

if ($fen === '') {
    respond(500, ['ok' => false, 'message' => 'FEN unavailable.', 'code' => 'fen_missing']);
}

$turnColor = $game['turn_color'] ?? '';
$turn = strtolower($turnColor) === 'white' ? 'w' : (strtolower($turnColor) === 'black' ? 'b' : null);

$scoreTotals = score_load();
$scoreLineText = sprintf(
    'overall: host %d · world %d · draws %d',
    $scoreTotals['host_wins'] ?? 0,
    $scoreTotals['world_wins'] ?? 0,
    $scoreTotals['draws'] ?? 0
);

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
    'score' => $scoreTotals,
    'score_line' => $scoreLineText,
    'message' => '',
];

respond(200, $response);
