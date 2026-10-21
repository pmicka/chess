<?php
/** Atomically account for one finished game and create exactly one successor. */
header('X-Robots-Tag: noindex, nofollow', true);
header('Referrer-Policy: no-referrer');
require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/score.php';
require_post();
$data = read_json_body() ?? [];
$tokenValue = clean_string($data['token'] ?? '', 256);
if ($tokenValue === '') respond_json(401, ['ok' => false, 'error' => 'Token missing', 'code' => 'AUTH_MISSING']);
$db = null;
try {
    $db = get_db();
    require_phase4($db);
    begin_write_transaction($db);
    // A spent capability can retrieve ONLY its own public transition receipt.
    // This branch never creates a game, issues a token, records a score, or emails.
    $receipt = $db->prepare("SELECT g.* FROM game_transitions x JOIN tokens t ON t.id=x.token_id
        JOIN games g ON g.id=x.successor_game_id WHERE t.token=? AND t.purpose='next_game'");
    $receipt->execute([$tokenValue]);
    $next = $receipt->fetch(PDO::FETCH_ASSOC);
    $replayed = (bool)$next;
    $openingToken = null;
    $notificationId = null;
    if (!$replayed) {
        $validation = validate_host_token($db, $tokenValue, false, false, 'next_game');
        if (!$validation['ok']) {
            $db->rollBack();
            respond_json($validation['code'] === 'expired' ? 410 : 403,
                ['ok' => false, 'error' => 'Invalid next-game link', 'code' => 'AUTH_INVALID']);
        }
        $token = $validation['row'];
        $query = $db->prepare('SELECT * FROM games WHERE id=? AND id=(SELECT MAX(id) FROM games)');
        $query->execute([$token['game_id']]);
        $game = $query->fetch(PDO::FETCH_ASSOC);
        if (!$game || $game['status'] !== 'finished') {
            $db->rollBack();
            respond_json(409, ['ok' => false, 'error' => 'Game is not the current completed game.', 'code' => 'game_conflict']);
        }
        $known = $db->prepare('SELECT 1 FROM game_results WHERE game_id=?');
        $known->execute([$game['id']]);
        if (!$known->fetchColumn()) record_detected_result($db, $game, detect_game_over_from_fen($game['fen'], $game['pgn']));
        $db->prepare('UPDATE tokens SET used=1,used_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$token['id']]);
        revoke_game_tokens($db, (int)$game['id']);
        $created = create_next_game($db, $game);
        $db->prepare('INSERT INTO game_transitions(completed_game_id,successor_game_id,token_id) VALUES(?,?,?)')
            ->execute([$game['id'], $created['id'], $token['id']]);
        $query = $db->prepare('SELECT * FROM games WHERE id=?');
        $query->execute([$created['id']]);
        $next = $query->fetch(PDO::FETCH_ASSOC);
        $openingToken = $created['host_token'];
        if ($openingToken !== null) $notificationId = enqueue_host_notification($db, (int)$next['id'], $openingToken);
    }
    $db->commit();
    $response = ['ok' => true, 'new_game_id' => (int)$next['id'], 'host_color' => $next['host_color'],
        'visitor_color' => $next['visitor_color'], 'turn_color' => $next['turn_color'],
        'revision' => (int)$next['revision'], 'message' => $replayed ? 'Next game already created.' : 'Next game created.'];
    $response['notification'] = try_dispatch_notification($db, $notificationId);
    respond_json(200, $response);
} catch (PDOException $e) {
    if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
    if (database_busy($e)) header('Retry-After: 1');
    respond_json(database_busy($e) ? 503 : 500, ['ok' => false, 'error' => 'Transition could not be committed.',
        'code' => database_busy($e) ? 'database_busy' : 'database_error']);
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
    log_event('host_next_game_error', ['error' => $e->getMessage()]);
    respond_json(503, ['ok' => false, 'error' => 'Transition unavailable; no new game was committed.', 'code' => 'transition_unavailable']);
}
