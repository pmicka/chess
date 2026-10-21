<?php
/** Private operator provisioning after migration; no mail and no token on stdout. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../db.php';
$options = getopt('', ['database:', 'output:']);
$db = null;
$created = false;
$output = '';
try {
    $path = $options['database'] ?? '';
    $output = $options['output'] ?? '';
    if (!is_string($path) || !database_path_is_absolute($path) || !is_file($path)
        || !is_string($output) || !database_path_is_absolute($output)) {
        throw new RuntimeException('Explicit existing --database and private absolute --output paths required.');
    }
    $parent = realpath(dirname($output));
    $root = realpath(__DIR__ . '/..');
    if (!$parent || $parent === $root || str_starts_with($parent, $root . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Output must be outside the application document root.');
    }
    $db = open_existing_database($path);
    if (!phase2_installed($db)) throw new RuntimeException('Phase 2 migration required.');
    begin_write_transaction($db);
    $game = $db->query('SELECT * FROM games ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$game || ($game['status'] === 'active' && $game['host_color'] !== $game['turn_color'])) {
        throw new RuntimeException('No current host action.');
    }
    $purpose = $game['status'] === 'finished' ? 'next_game' : 'your_move';
    $expiry = default_host_token_expiry();
    revoke_game_tokens($db, (int)$game['id']);
    $token = insert_host_move_token($db, (int)$game['id'], $expiry, $purpose);
    $previousMask = umask(0077);
    try { $file = @fopen($output, 'x'); } finally { umask($previousMask); }
    if (!$file) throw new RuntimeException('Private output must not exist and must be writable.');
    $created = true;
    try {
        if (!chmod($output, 0600)) throw new RuntimeException('Cannot enforce private output permissions.');
        $text = canonical_chess_base() . '/my_move.php?' . http_build_query(['token' => $token]) . "\n";
        if (fwrite($file, $text) !== strlen($text) || !fflush($file)) throw new RuntimeException('Private output write failed.');
    } finally { fclose($file); }
    $db->commit();
    echo "Host link written privately. Existing game state and scores preserved.\n";
} catch (Throwable $e) {
    if ($db instanceof PDO && $db->inTransaction()) $db->rollBack();
    if ($created) @unlink($output);
    fwrite(STDERR, "Host link not issued: " . $e->getMessage() . "\n");
    exit(1);
}
