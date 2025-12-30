<?php
/**
 * /api/host_next_game.php — Host-controlled start of next game after game over.
 */

header('X-Robots-Tag: noindex');
header('Content-Type: application/json');
require_once __DIR__ . '/../lib/http.php';

try {
    require_once __DIR__ . '/../db.php';
    require_once __DIR__ . '/../lib/score.php';
} catch (Throwable $e) {
    respond_json(503, ['ok' => false, 'error' => $e->getMessage(), 'code' => 'config']);
}
log_db_path_info('host_next_game');

require_post();

$data = read_json_body();
if (!is_array($data)) {
    $data = [];
}

$tokenValue = clean_string($data['token'] ?? '', 256);
if ($tokenValue === '') {
    respond_json(401, ['ok' => false, 'error' => 'Token missing', 'code' => 'AUTH_MISSING']);
}

$db = null;

try {
    $db = get_db();
    $db->beginTransaction();

    $validation = validate_host_token($db, $tokenValue, true, false);
    if (($validation['ok'] ?? false) !== true) {
        $db->rollBack();
        $code = $validation['code'] ?? 'invalid';
        $http = $code === 'expired' ? 410 : 403;
        log_event('host_next_game_auth_fail', [
            'token_suffix' => token_suffix($tokenValue),
            'code' => $code,
            'ip' => client_ip(),
        ]);
        respond_json($http, ['ok' => false, 'error' => 'Invalid token', 'code' => $code]);
    }

    $tokenRow = $validation['row'];
    $stmt = $db->prepare("
        SELECT id, host_color, visitor_color, turn_color, status, fen, pgn, last_move_san, updated_at
        FROM games
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $tokenRow['game_id']]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$game) {
        $db->rollBack();
        respond_json(400, ['ok' => false, 'error' => 'Game not found', 'code' => 'game_not_found']);
    }

    $gameId = (int)$game['id'];
    $status = detect_game_over_from_fen($game['fen']);
    $isOver = ($status['ok'] ?? false) && ($status['over'] ?? false);

    if (($game['status'] ?? '') === 'active' && !$isOver) {
        $db->rollBack();
        respond_json(400, ['ok' => false, 'error' => 'Game is not over yet.', 'code' => 'not_over']);
    }

    $resultLabel = null;
    if ($isOver) {
        $hostColor = strtolower((string)($game['host_color'] ?? ''));
        $winner = $status['winner'] ?? null;
        $reason = $status['reason'] ?? null;

        if ($winner === 'w' || $winner === 'b') {
            $hostCode = $hostColor === 'white' ? 'w' : 'b';
            $resultLabel = $winner === $hostCode ? 'host' : 'world';
        } elseif ($reason === 'draw' || $reason === 'stalemate') {
            $resultLabel = 'draw';
        }
    }

    finish_game($db, $gameId);

    if ($resultLabel !== null) {
        try {
            score_increment($resultLabel, $gameId);
        } catch (Throwable $e) {
            error_log('host_next_game score_increment_failed game_id=' . $gameId . ' err=' . $e->getMessage());
        }
    }

    $nextGame = create_next_game($db, $game);

    $db->commit();

    log_event('host_next_game', [
        'game_id' => $gameId,
        'result' => $resultLabel ?? 'unknown',
    ]);

    respond_json(200, [
        'ok' => true,
        'new_game_id' => $nextGame['id'],
        'host_color' => $nextGame['host_color'],
        'visitor_color' => $nextGame['visitor_color'],
        'turn_color' => $nextGame['turn_color'],
        'host_token' => $nextGame['host_token'],
        'token_expires_at' => $nextGame['token_expires_at'] instanceof DateTimeInterface
            ? $nextGame['token_expires_at']->format('Y-m-d H:i:s T')
            : null,
        'message' => 'Next game created.',
    ]);
} catch (RuntimeException $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    respond_json(503, ['ok' => false, 'error' => $e->getMessage(), 'code' => 'db_path']);
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    log_event('host_next_game_error', ['error' => $e->getMessage()]);
    respond_json(500, ['ok' => false, 'error' => 'Failed to start next game.']);
}
