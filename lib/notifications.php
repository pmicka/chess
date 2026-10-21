<?php
/** Durable email intent. Credentials stay in tokens, never in job payloads/logs. */
function enqueue_host_notification(PDO $db, int $gameId, string $token): int
{
    if (!$db->inTransaction()) throw new RuntimeException('Notification requires the game transaction.');
    $db->prepare('INSERT INTO notification_outbox(token_id,game_id,game_revision)
        SELECT id,game_id,game_revision FROM tokens WHERE token=? AND game_id=?
        ON CONFLICT(token_id) DO NOTHING')->execute([$token, $gameId]);
    $query = $db->prepare('SELECT id FROM notification_outbox WHERE token_id=(SELECT id FROM tokens WHERE token=?)');
    $query->execute([$token]);
    $id = $query->fetchColumn();
    if (!$id) throw new RuntimeException('Notification credential missing.');
    return (int)$id;
}

/** One leased attempt. No SQLite transaction is held during the mail transport. */
function dispatch_host_notification(PDO $db, int $id): string
{
    $now = time();
    begin_write_transaction($db);
    try {
        $query = $db->prepare('SELECT * FROM notification_outbox WHERE id=?');
        $query->execute([$id]);
        $job = $query->fetch(PDO::FETCH_ASSOC);
        if (!$job || in_array($job['status'], ['accepted', 'obsolete', 'failed'], true)
            || ($job['status'] === 'sending' && (int)$job['lease_until'] > $now)
            || ($job['status'] === 'pending' && (int)$job['next_attempt_at'] > $now)) {
            $db->rollBack();
            return $job['status'] ?? 'missing';
        }
        if ((int)$job['attempts'] >= 8) {
            $db->prepare("UPDATE notification_outbox SET status='failed',claim=NULL,lease_until=NULL,last_error='attempt_limit' WHERE id=?")
                ->execute([$id]);
            $db->commit();
            return 'failed';
        }
        $query = $db->prepare('SELECT * FROM tokens WHERE id=?');
        $query->execute([$job['token_id']]);
        $token = $query->fetch(PDO::FETCH_ASSOC);
        $query = $db->prepare('SELECT * FROM games WHERE id=? AND id=(SELECT MAX(id) FROM games)');
        $query->execute([$job['game_id']]);
        $game = $query->fetch(PDO::FETCH_ASSOC);
        $eligible = $token && $game && (int)$token['game_id'] === (int)$job['game_id']
            && !(int)$token['used'] && empty($token['used_at']) && empty($token['revoked_at'])
            && (int)$game['revision'] === (int)$job['game_revision']
            && (int)$token['game_revision'] === (int)$job['game_revision']
            && (($token['purpose'] === 'your_move' && $game['status'] === 'active' && $game['turn_color'] === $game['host_color'])
                || ($token['purpose'] === 'next_game' && $game['status'] === 'finished'));
        if (!$eligible) {
            $db->prepare("UPDATE notification_outbox SET status='obsolete',claim=NULL,lease_until=NULL WHERE id=?")->execute([$id]);
            $db->commit();
            return 'obsolete';
        }
        $expiry = token_expiry_from_row($db, $token);
        if (!$expiry) throw new RuntimeException('Invalid notification expiry.');
        if ($expiry <= datetime_utc()) {
            // This is a private worker retry, bound to the original job's turn.
            // It cannot recover a used/revoked credential or select another game.
            $db->prepare('UPDATE tokens SET revoked_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$token['id']]);
            $expiry = default_host_token_expiry();
            $token['token'] = insert_host_move_token($db, (int)$game['id'], $expiry, $token['purpose']);
            $token['id'] = (int)$db->lastInsertId();
            $db->prepare('UPDATE notification_outbox SET token_id=? WHERE id=?')->execute([$token['id'], $id]);
        }
        $claim = bin2hex(random_bytes(16));
        $db->prepare("UPDATE notification_outbox SET status='sending',attempts=attempts+1,claim=?,lease_until=? WHERE id=?")
            ->execute([$claim, $now + 600, $id]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }

    try {
        $messageId = 'chess-notification-' . $id . '@' . parse_url(canonical_chess_base(), PHP_URL_HOST);
        $mail = send_host_turn_email((int)$game['id'], $token['token'], $expiry,
            $game['last_move_san'] ?? 'New game', $messageId, $token['purpose']);
        $accepted = ($mail['ok'] ?? false) === true;
    } catch (Throwable $e) {
        $accepted = false;
    }
    $attempt = (int)$job['attempts'] + 1;
    $status = $accepted ? 'accepted' : ($attempt >= 8 ? 'failed' : 'pending');
    // An expired lease cannot let an old worker overwrite a newer worker's claim.
    $db->prepare('UPDATE notification_outbox SET status=?,accepted_at=?,next_attempt_at=?,last_error=?,claim=NULL,lease_until=NULL
        WHERE id=? AND claim=?')->execute([$status, $accepted ? gmdate('Y-m-d H:i:s') : null,
            time() + min(3600, 60 * (2 ** min(6, $attempt - 1))),
            $accepted ? null : 'mail_submission_failed', $id, $claim]);
    return $status;
}

/** Post-commit convenience only. Failures cannot turn an accepted move into 500. */
function try_dispatch_notification(PDO $db, ?int $id): string
{
    if ($id === null) return 'none';
    try {
        return dispatch_host_notification($db, $id);
    } catch (Throwable $e) {
        log_event('notification_dispatch_unavailable', ['job_id' => $id]);
        return 'queued';
    }
}
