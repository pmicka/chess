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
 * Data accepted: coordinates, explicit promotion when needed, game/revision,
 * and CAPTCHA token. Client notation is ignored; FEN/PGN inputs are rejected.
 * The server verifies stored history against canonical FEN, then obtains legal
 * move, SAN, FEN, turn and terminal status from the bundled chess.js engine.
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
    require_once __DIR__ . '/../lib/score.php';
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
$rawPromotion = array_key_exists('promotion', $data) ? $data['promotion'] : '';
$promotion = is_string($rawPromotion) ? strtolower(trim($rawPromotion)) : null;
$gameId = clean_nonnegative_int($data['game_id'] ?? null);
$expectedRevision = clean_nonnegative_int($data['expected_revision'] ?? null);

if (isset($data['fen']) || isset($data['pgn'])) {
    respond_json(400, ['ok' => false, 'error' => 'FEN/PGN are server-managed. Submit coordinates only.', 'code' => 'server_managed_fields']);
}

if ($turnstileToken === '') {
    respond_json(400, ['ok' => false, 'error' => 'CAPTCHA token missing', 'code' => 'captcha_missing']);
}

if ($from === '' || $to === '' || $gameId === null || $gameId < 1 || $expectedRevision === null) {
    respond_json(400, ['ok' => false, 'error' => 'Missing required fields (from, to, game_id, expected_revision).', 'code' => 'missing_fields']);
}

$allowedPromotions = ['q', 'r', 'b', 'n'];
if ($promotion === null || ($promotion !== '' && !in_array($promotion, $allowedPromotions, true))) {
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
    begin_write_transaction($db);

    // The client must identify the exact current game, never a timestamp match.
    $stmt = $db->prepare("SELECT id,host_color,host_color AS you_color,visitor_color,turn_color,status,pgn,fen,revision,updated_at
        FROM games WHERE id=? AND status='active' AND id=(SELECT MAX(id) FROM games)");
    $stmt->execute([$gameId]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$game) {
        error_log('visitor_move game_found=0');
        $db->rollBack();
        respond_json(409, ['ok' => false, 'error' => 'Requested game is no longer current and active.', 'code' => 'stale_state']);
    }

    if ((int)$game['id'] !== $gameId || (int)$game['revision'] !== $expectedRevision) {
        $db->rollBack();
        respond_json(409, ['ok' => false, 'error' => 'Server state changed. Refresh and try again.', 'code' => 'stale_state']);
    }

    if ($game['turn_color'] !== $game['visitor_color']) {
        $db->rollBack();
        respond_json(400, ['ok' => false, 'error' => 'It is not the visitor turn.', 'code' => 'turn_mismatch']);
    }
    $validated = validate_game_move($game, $from, $to, $promotion);
    $currentStatus = $validated['before'];
    if (($currentStatus['ok'] ?? false) && ($currentStatus['over'] ?? false)) {
        finish_game($db, (int)$game['id']);
        record_detected_result($db, $game, $currentStatus);
        $completionExpiry = default_host_token_expiry();
        $completionToken = insert_host_move_token($db, (int)$game['id'], $completionExpiry, 'next_game');
        $db->commit();
        send_host_turn_email((int)$game['id'], $completionToken, $completionExpiry, $game['last_move_san'] ?? 'Game finished');
        respond_json(409, [
            'ok' => false,
            'error' => 'Game is over. Waiting for next game.',
            'code' => 'GAME_OVER',
        ]);
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

    $newFen = $validated['fen'];
    $newPgn = $validated['pgn'];
    $newStatus = $validated['after'];
    $isOver = $newStatus['over'];
    $lastMoveSan = $validated['move']['san'];

    $update = $db->prepare("
        UPDATE games
        SET fen = :fen,
            pgn = :pgn,
            last_move_san = :last_move_san,
            turn_color = :turn_color,
            status = :status,
            revision = revision + 1,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id AND revision = :expected_revision
    ");

    $update->execute([
        ':fen' => $newFen,
        ':pgn' => $newPgn,
        ':last_move_san' => $lastMoveSan,
        ':turn_color' => $validated['turn_color'],
        ':status' => $isOver ? 'finished' : 'active',
        ':id' => $game['id'],
        ':expected_revision' => $expectedRevision,
    ]);
    if ($update->rowCount() !== 1) throw new RuntimeException('Revision conflict during move update.');

    error_log(sprintf(
        'visitor_move write game=%d fen_len=%d',
        $game['id'],
        strlen($newFen)
    ));

    revoke_game_tokens($db, (int)$game['id']);
    $expiresAt = default_host_token_expiry();
    if ($isOver) {
        $db->prepare('DELETE FROM locks WHERE game_id=?')->execute([$game['id']]);
        record_detected_result($db, $game, $newStatus);
    }
    $hostToken = insert_host_move_token($db, (int)$game['id'], $expiresAt, $isOver ? 'next_game' : 'your_move');

    // Fetch the updated state for the response.
    $stateStmt = $db->prepare("
        SELECT id, host_color AS you_color, visitor_color, turn_color, status, fen, pgn, last_move_san, revision, updated_at
        FROM games
        WHERE id = :id
        LIMIT 1
    ");
    $stateStmt->execute([':id' => $game['id']]);
    $updatedGame = $stateStmt->fetch(PDO::FETCH_ASSOC);

    $db->commit();

    $updatedGame['id'] = (int)$updatedGame['id'];
    $updatedGame['revision'] = (int)$updatedGame['revision'];

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
} catch (ChessStateException $e) {
    if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
    respond_json($e->httpStatus, ['ok' => false, 'error' => $e->getMessage(), 'code' => $e->responseCode]);
} catch (ChessEngineUnavailable $e) {
    if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
    log_event('chess_engine_unavailable');
    respond_json(503, ['ok' => false, 'error' => $e->getMessage(), 'code' => 'chess_unavailable']);
} catch (PDOException $e) {
    if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
    log_event('database_write_error', ['busy' => database_busy($e) ? 1 : 0]);
    if (database_busy($e)) header('Retry-After: 1');
    respond_json(database_busy($e) ? 503 : 500, ['ok' => false, 'error' => 'Database write unavailable. Retry after refreshing.',
        'code' => database_busy($e) ? 'database_busy' : 'database_error']);
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
