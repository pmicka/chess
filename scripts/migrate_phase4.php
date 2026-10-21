<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../db.php';
$options = getopt('', ['database:', 'apply']);
try {
    $path = $options['database'] ?? '';
    if (!is_string($path) || !database_path_is_absolute($path) || !is_file($path)) {
        throw new RuntimeException('Explicit existing --database required.');
    }
    $apply = array_key_exists('apply', $options);
    $db = open_existing_database($path, !$apply);
    if (!phase2_installed($db)) throw new RuntimeException('Phase 2 migration required first.');
    phase2_preflight($db);
    if (!$apply) {
        echo "Preflight passed. No changes made. Back up SQLite and configure the notification worker before deployment.\n";
        exit;
    }
    migrate_phase4($db);
    echo "Phase 4 installed. Existing games, scores and credentials preserved.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration refused: ' . $e->getMessage() . "\n");
    exit(1);
}
