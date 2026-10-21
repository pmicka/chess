<?php
/** Default is read-only status; only explicit --drain submits email. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../db.php';
$options = getopt('', ['database:', 'drain', 'queue-current', 'retry-failed']);
try {
    $path = $options['database'] ?? '';
    if (!is_string($path) || !database_path_is_absolute($path) || !is_file($path)) {
        throw new RuntimeException('Explicit existing --database required.');
    }
    $write = isset($options['drain']) || isset($options['queue-current']) || isset($options['retry-failed']);
    $db = open_existing_database($path, !$write);
    require_phase4($db);
    if (isset($options['queue-current'])) {
        begin_write_transaction($db);
        $game = $db->query('SELECT * FROM games ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if (!$game || ($game['status'] === 'active' && $game['turn_color'] !== $game['host_color'])) {
            throw new RuntimeException('No current host action.');
        }
        $purpose = $game['status'] === 'finished' ? 'next_game' : 'your_move';
        $query = $db->prepare('SELECT token FROM tokens WHERE game_id=? AND game_revision=? AND purpose=? AND used=0 AND revoked_at IS NULL ORDER BY id DESC');
        $query->execute([$game['id'], $game['revision'], $purpose]);
        $token = null;
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!validate_host_token($db, $row['token'], false, false, $purpose)['ok']) continue;
            $existing = $db->prepare('SELECT status FROM notification_outbox WHERE token_id=(SELECT id FROM tokens WHERE token=?)');
            $existing->execute([$row['token']]);
            $status = $existing->fetchColumn();
            if ($status === false || in_array($status, ['pending', 'sending'], true)) {
                $token = $row['token'];
                break;
            }
        }
        // Explicit operator recovery after a previously accepted/lost message.
        // The capability is queued for email only, never displayed by this CLI.
        if ($token === null) $token = insert_host_move_token($db, (int)$game['id'], default_host_token_expiry(), $purpose);
        enqueue_host_notification($db, (int)$game['id'], $token);
        $db->commit();
    }
    if (isset($options['retry-failed'])) {
        $db->exec("UPDATE notification_outbox SET status='pending',attempts=0,next_attempt_at=0 WHERE status='failed'");
    }
    $unavailable = false;
    if (isset($options['drain'])) {
        $now = time();
        $query = $db->prepare("SELECT id FROM notification_outbox WHERE (status='pending' AND next_attempt_at<=?)
            OR (status='sending' AND lease_until<=?) ORDER BY id LIMIT 20");
        $query->execute([$now, $now]);
        $ids = $query->fetchAll(PDO::FETCH_COLUMN);
        $query->closeCursor();
        foreach ($ids as $id) {
            if (in_array(try_dispatch_notification($db, (int)$id), ['queued', 'failed'], true)) $unavailable = true;
        }
    }
    echo json_encode($db->query('SELECT status,COUNT(*) AS count FROM notification_outbox GROUP BY status')->fetchAll(PDO::FETCH_ASSOC)) . "\n";
    if ($unavailable) exit(2);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    fwrite(STDERR, "Notification operation unavailable; no credentials displayed.\n");
    exit(1);
}
