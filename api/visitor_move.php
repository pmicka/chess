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

header('X-Robots-Tag: noindex, nofollow', true);
header('Content-Type: application/json');
require_once __DIR__ . '/../lib/http.php';

try {
    require_once __DIR__ . '/../db.php';
} catch (Throwable $e) {
    respond_json(503, ['ok' => false, 'error' => $e->getMessage(), 'code' => 'config']);
}
log_db_path_info('visitor_move');
require_post();

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
    respond_json(400, ['ok' => false, 'error' => 'Invalid request body', 'code' => 'bad_payload']);
}

$turnstileToken = clean_string(
    $data['turnstile_token']
    ?? $data['cf-turnstile-response']
    ?? '',
    2048
);
$from = clean_square($data['from'] ?? '');
$to = clean_square($data['to'] ?? '');
$promotion = clean_promotion($data['promotion'] ?? '');
$lastMoveSan = clean_string($data['last_move_san'] ?? '', 32);
$lastKnownUpdatedAt = clean_string($data['last_known_updated_at'] ?? '', 64);

if (isset($data['fen']) || isset($data['pgn'])) {
    respond_json(400, ['ok' => false, 'error' => 'FEN/PGN are server-managed. Submit coordinates only.', 'code' => 'server_managed_fields']);
}

if ($turnstileToken === '') {
    respond_json(400, ['ok' => false, 'error' => 'CAPTCHA token missing', 'code' => 'captcha_missing']);
}

if ($from === '' || $to === '' || $lastKnownUpdatedAt === '') {
    respond_json(400, ['ok' => false, 'error' => 'Missing required fields (from, to, last_known_updated_at).', 'code' => 'missing_fields']);
}

$allowedPromotions = ['q', 'r', 'b', 'n'];
if ($promotion !== '' && !in_array($promotion, $allowedPromotions, true)) {
    respond_json(400, ['ok' => false, 'error' => 'Invalid promotion piece. Use q, r, b, or n.', 'code' => 'promotion_invalid']);
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
    log_event('visitor_move_captcha_fail', [
        'ip' => client_ip(),
        'errors' => implode(',', $turnstileResult['errors'] ?? []),
    ]);
    respond_json(400, [
        'ok' => false,
        'error' => 'CAPTCHA verification failed',
        'turnstile_errors' => $turnstileResult['errors'],
        'code' => 'captcha_failed',
    ]);
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
        respond_json(400, ['ok' => false, 'error' => 'No active game found.', 'code' => 'game_missing']);
    }

    $currentStatus = detect_game_over_from_fen($game['fen']);
    if (($currentStatus['ok'] ?? false) && ($currentStatus['over'] ?? false)) {
        finish_game($db, (int)$game['id']);
        $db->commit();
        respond_json(409, [
            'ok' => false,
            'error' => 'Game is over. Waiting for next game.',
            'code' => 'GAME_OVER',
        ]);
    }

    if ($game['turn_color'] === $game['you_color']) {
        $db->rollBack();
        respond_json(400, ['ok' => false, 'error' => 'It is not the visitor turn.', 'code' => 'turn_mismatch']);
    }

    if ($lastKnownUpdatedAt === '' || $lastKnownUpdatedAt !== ($game['updated_at'] ?? '')) {
        $db->rollBack();
        respond_json(409, ['ok' => false, 'error' => 'Server state changed. Refresh and try again.', 'code' => 'stale_state']);
    }

    log_event('visitor_move_attempt', [
        'ip' => client_ip(),
        'game_id' => $game['id'] ?? null,
        'turn' => $game['turn_color'] ?? null,
        'from' => $from,
        'to' => $to,
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
        respond_json(409, ['ok' => false, 'error' => 'Move already accepted for this turn', 'code' => 'turn_locked']);
    }

    try {
        $newFen = apply_move_to_fen($game['fen'], $from, $to, $promotion, $game['turn_color']);
    } catch (Throwable $e) {
        $db->rollBack();
        $msg = $e->getMessage();
        $clientMessage = stripos((string)$msg, 'promotion') !== false ? $msg : 'Illegal move or invalid coordinates.';
        respond_json(400, ['ok' => false, 'error' => $clientMessage, 'code' => 'illegal_move']);
    }

    if ($lastMoveSan === '') {
        $lastMoveSan = "{$from}-{$to}";
    }

    $newPgn = append_pgn_move($game['pgn'] ?? '', $game['turn_color'], $lastMoveSan, $newFen);

    $newStatus = detect_game_over_from_fen($newFen);
    $isOver = ($newStatus['ok'] ?? false) && ($newStatus['over'] ?? false);

    // Save visitor move and flip turn back to host (you_color).
    $update = $db->prepare("
        UPDATE games
        SET fen = :fen,
            pgn = :pgn,
            last_move_san = :last_move_san,
            turn_color = host_color,
            status = :status,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");

    $update->execute([
        ':fen' => $newFen,
        ':pgn' => $newPgn,
        ':last_move_san' => $lastMoveSan,
        ':status' => $isOver ? 'finished' : 'active',
        ':id' => $game['id'],
    ]);

    error_log(sprintf(
        'visitor_move write game=%d fen_len=%d',
        $game['id'],
        strlen($newFen)
    ));

    $hostToken = null;
    $expiresAt = null;
    if (!$isOver) {
        $tokenInfo = ensure_host_move_token($db, (int)$game['id']);
        $hostToken = $tokenInfo['token'];
        $expiresAt = $tokenInfo['expires_at'];
    } else {
        $db->prepare('DELETE FROM locks WHERE game_id = :id')->execute([':id' => $game['id']]);
    }

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

    log_event('visitor_move_commit', [
        'game_id' => $updatedGame['id'],
        'turn' => $game['turn_color'],
        'to' => $to,
        'promotion' => $promotion ?: '-',
        'status' => $isOver ? 'finished' : 'active',
    ]);

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

    respond_json(200, $response);
} catch (RuntimeException $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    log_event('visitor_move_runtime_error', ['error' => $e->getMessage()]);
    respond_json(503, ['ok' => false, 'error' => $e->getMessage(), 'code' => 'runtime']);
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    log_event('visitor_move_error', ['error' => $e->getMessage()]);
    respond_json(500, ['ok' => false, 'error' => 'Failed to save move.', 'code' => 'server_error']);
}
