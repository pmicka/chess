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

require_once __DIR__ . '/../db.php';

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
$fen = trim($data['fen'] ?? '');
$lastMoveSan = trim($data['last_move_san'] ?? '');

if ($turnstileToken === '') {
    http_response_code(400);
    echo json_encode(['error' => 'CAPTCHA token missing']);
    exit;
}

if ($fen === '' || $lastMoveSan === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields (fen, last_move_san).']);
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
        SELECT id, host_color AS you_color, visitor_color, turn_color, status, pgn, fen
        FROM games
        WHERE status = 'active'
        ORDER BY updated_at DESC
        LIMIT 1
    ");
    $game = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$game) {
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

    $newPgn = append_pgn_move($game['pgn'] ?? '', $game['turn_color'], $lastMoveSan, $fen);

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
        ':fen' => $fen,
        ':pgn' => $newPgn,
        ':last_move_san' => $lastMoveSan,
        ':id' => $game['id'],
    ]);

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
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save move.']);
}
