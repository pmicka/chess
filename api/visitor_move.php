<?php
/**
 * /api/visitor_move.php — Accept a visitor move (CAPTCHA-gated)
 *
 * Intent:
 * - Accept exactly ONE visitor move when it is visitors' turn.
 * - Guard submission with CAPTCHA to reduce abuse.
 * - Update game state, flip turn to host, and notify host by email.
 *
 * Required checks (server-side):
 * - CAPTCHA verification MUST pass (Turnstile/hCaptcha/reCAPTCHA).
 * - Game MUST be active.
 * - It MUST be the visitors' turn.
 * - Concurrency control: only one submission can win for a given turn (lock).
 *
 * Data accepted (MVP):
 * - Updated FEN and PGN representing the move made client-side
 * - Move notation (SAN or coordinate)
 * - CAPTCHA token
 *
 * Security note (important):
 * - Client-side chess.js checks are not authoritative; malicious clients can bypass them.
 * - MVP enforces turn/locking/CAPTCHA; later hardening adds server-side move validation.
 *
 * Side effects:
 * - Save updated state in games table
 * - Create a single-use token for host to make the next move
 * - Email host a link to /my_move.php?token=...
 */

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../db.php';
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['error' => $e->getMessage()]);
    exit;
}
log_db_path_info('visitor_move');

// Accept only POST with JSON payload.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

/**
 * Parse request data from JSON or form-encoded POST.
 */
function get_request_data(): ?array
{
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    if (!empty($_POST)) {
        return $_POST;
    }

    return null;
}

$data = get_request_data();

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body']);
    exit;
}

$turnstileToken = trim(
    $data['turnstile_token']
    ?? $data['cf-turnstile-response']
    ?? ''
);
$from = strtolower(trim($data['from'] ?? ''));
$to = strtolower(trim($data['to'] ?? ''));
$promotion = strtolower(trim($data['promotion'] ?? ''));
$lastMoveSan = trim($data['last_move_san'] ?? '');
$lastKnownUpdatedAt = trim($data['last_known_updated_at'] ?? '');
$clientFen = isset($data['client_fen']) ? trim($data['client_fen']) : null;

if (isset($data['fen']) || isset($data['pgn'])) {
    http_response_code(400);
    echo json_encode(['error' => 'FEN/PGN are server-managed. Submit coordinates only.']);
    exit;
}

if ($turnstileToken === '') {
    http_response_code(400);
    echo json_encode(['error' => 'CAPTCHA token missing']);
    exit;
}

if ($from === '' || $to === '' || $lastKnownUpdatedAt === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields (from, to, last_known_updated_at).']);
    exit;
}

$allowedPromotions = ['q', 'r', 'b', 'n'];
if ($promotion !== '' && !in_array($promotion, $allowedPromotions, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid promotion piece. Use q, r, b, or n.']);
    exit;
}

// Verify Turnstile token before attempting any DB changes.
function verify_turnstile(string $token): array
{
    $payload = [
        'secret' => TURNSTILE_SECRET_KEY,
        'response' => $token,
    ];

    if (!empty($_SERVER['REMOTE_ADDR'])) {
        $payload['remoteip'] = $_SERVER['REMOTE_ADDR'];
    }

    $postData = http_build_query($payload);

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $postData,
            'timeout' => 5,
        ],
    ]);

    $result = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $context);

    if ($result === false) {
        return ['success' => false, 'errors' => ['network_error']];
    }

    $response = json_decode($result, true);

    $success = is_array($response) && ($response['success'] ?? false) === true;
    $errors = [];
    if (is_array($response)) {
        if (isset($response['error-codes']) && is_array($response['error-codes'])) {
            $errors = $response['error-codes'];
        } elseif (!$success) {
            $errors[] = 'unknown_error';
        }
    } else {
        $errors[] = 'invalid_response';
    }

    return ['success' => $success, 'errors' => $errors];
}

$turnstileResult = verify_turnstile($turnstileToken);

if ($turnstileResult['success'] !== true) {
    http_response_code(400);
    echo json_encode([
        'error' => 'CAPTCHA verification failed',
        'turnstile_errors' => $turnstileResult['errors'],
    ]);
    exit;
}

$db = null;
$warning = null;

try {
    $db = get_db();
    $db->beginTransaction();

    // Load the latest active game.
    $stmt = $db->query("
        SELECT id, host_color AS you_color, visitor_color, turn_color, status, pgn, fen, updated_at
        FROM games
        WHERE status = 'active'
        ORDER BY updated_at DESC
        LIMIT 1
    ");
    $game = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$game) {
        error_log('visitor_move game_found=0');
        $db->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'No active game found.']);
        exit;
    }

    if ($game['turn_color'] === $game['you_color']) {
        $db->rollBack();
        http_response_code(400);
        echo json_encode(['error' => 'It is not the visitor turn.']);
        exit;
    }

    if ($lastKnownUpdatedAt === '' || $lastKnownUpdatedAt !== ($game['updated_at'] ?? '')) {
        $db->rollBack();
        http_response_code(409);
        echo json_encode(['error' => 'Server state changed. Refresh and try again.']);
        exit;
    }

    log_event('visitor_move_attempt', [
        'ip' => client_ip(),
        'game_id' => $game['id'] ?? null,
        'turn' => $game['turn_color'] ?? null,
        'from' => $from,
        'to' => $to,
        'client_fen_len' => $clientFen !== null ? strlen($clientFen) : null,
    ]);

    error_log(sprintf(
        'visitor_move game_found=1 game_id=%d fen_length=%d',
        (int)$game['id'],
        isset($game['fen']) ? strlen((string)$game['fen']) : 0
    ));

    // Acquire a lock for this visitor turn. If it already exists, reject.
    // Lock is cleared when the host successfully submits their move (see api/my_move_submit.php).
    $lock = $db->prepare("
        INSERT INTO locks (game_id, name)
        SELECT :game_id, :name
        WHERE NOT EXISTS (
            SELECT 1 FROM locks WHERE game_id = :game_id AND name = :name
        )
    ");

    $lock->execute([
        ':game_id' => $game['id'],
        ':name' => 'visitor_turn',
    ]);

    if ($lock->rowCount() === 0) {
        $db->rollBack();
        http_response_code(409);
        echo json_encode(['error' => 'Move already accepted for this turn']);
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

    if ($lastMoveSan === '') {
        $lastMoveSan = "{$from}-{$to}";
    }

    $newPgn = append_pgn_move($game['pgn'] ?? '', $game['turn_color'], $lastMoveSan, $newFen);

    // Save visitor move and flip turn back to host (you_color).
    $update = $db->prepare("
        UPDATE games
        SET fen = :fen,
            pgn = :pgn,
            last_move_san = :last_move_san,
            turn_color = host_color,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");

    $update->execute([
        ':fen' => $newFen,
        ':pgn' => $newPgn,
        ':last_move_san' => $lastMoveSan,
        ':id' => $game['id'],
    ]);

    error_log(sprintf(
        'visitor_move write game=%d fen_len=%d client_fen_len=%s',
        $game['id'],
        strlen($newFen),
        $clientFen !== null ? strlen($clientFen) : 'n/a'
    ));

    $tokenInfo = ensure_host_move_token($db, (int)$game['id']);
    $hostToken = $tokenInfo['token'];
    $expiresAt = $tokenInfo['expires_at'];

    // Fetch the updated state for the response.
    $stateStmt = $db->prepare("
        SELECT id, host_color AS you_color, visitor_color, turn_color, status, fen, pgn, last_move_san, updated_at
        FROM games
        WHERE id = :id
        LIMIT 1
    ");
    $stateStmt->execute([':id' => $game['id']]);
    $updatedGame = $stateStmt->fetch(PDO::FETCH_ASSOC);

    $db->commit();

    $updatedGame['id'] = (int)$updatedGame['id'];

    // Email host the single-use link. DB changes are already committed.
    $emailResult = send_host_turn_email((int)$updatedGame['id'], $hostToken, $expiresAt, $lastMoveSan);
    if (($emailResult['ok'] ?? false) !== true) {
        $warning = $emailResult['warning'] ?? 'Email failed';
    }

    $response = [
        'ok' => true,
        'game' => $updatedGame,
        'message' => 'Move accepted. Waiting for host.',
    ];

    if ($warning !== null) {
        $response['warning'] = $warning;
    }

    echo json_encode($response);
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
