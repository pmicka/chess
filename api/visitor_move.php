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

// Parse JSON body if present
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

// Fallback to form POST (just in case)
if (!is_array($data)) {
    $data = $_POST;
}

$token =
    $data['turnstile_token']
    ?? $data['cf-turnstile-response']
    ?? '';

header('Content-Type: application/json');

require_once __DIR__ . '/../db.php';

// Accept only POST with JSON payload.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST required']);
    exit;
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);
if (!is_array($data)) {
    $data = $_POST; // fallback
}

$fen = trim($data['fen'] ?? '');
$pgn = trim($data['pgn'] ?? '');
$lastMoveSan = trim($data['last_move_san'] ?? '');
$turnstileToken = trim($data['turnstile_token'] ?? '');

if ($fen === '' || $pgn === '' || $lastMoveSan === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required fields (fen, pgn, last_move_san).']);
    exit;
}

if ($turnstileToken === '') {
    http_response_code(400);
    echo json_encode(['error' => 'CAPTCHA verification failed']);
    exit;
}

// Verify Turnstile token before attempting any DB changes.
function verify_turnstile(string $token): bool
{
    $postData = http_build_query([
        'secret' => TURNSTILE_SECRET_KEY,
        'response' => $token,
    ]);

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
        return false;
    }

    $response = json_decode($result, true);

    return is_array($response) && ($response['success'] ?? false) === true;
}

if (!verify_turnstile($turnstileToken)) {
    http_response_code(400);
    echo json_encode(['error' => 'CAPTCHA verification failed']);
    exit;
}

$db = null;

try {
    $db = get_db();
    $db->beginTransaction();

    // Load the latest active game.
    $stmt = $db->query("
        SELECT id, host_color AS you_color, visitor_color, turn_color, status
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
        ':pgn' => $pgn,
        ':last_move_san' => $lastMoveSan,
        ':id' => $game['id'],
    ]);

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

    echo json_encode([
        'ok' => true,
        'game' => $updatedGame,
        'message' => 'Move accepted. Waiting for host.',
    ]);
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save move.']);
}
