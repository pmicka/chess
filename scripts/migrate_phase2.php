<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../db.php';

$options = getopt('', ['database:', 'score-file:', 'apply']);
try {
    $path = $options['database'] ?? '';
    if (!is_string($path) || !database_path_is_absolute($path) || !is_file($path)) {
        throw new RuntimeException('Use --database=/verified/existing/file.sqlite; no database is created.');
    }
    $apply = array_key_exists('apply', $options);
    $db = open_existing_database($path, !$apply);
    if (phase2_installed($db)) {
        echo "Phase 2 already installed; no changes made.\n";
        exit;
    }
    phase2_preflight($db);
    $score = read_legacy_score((string)($options['score-file'] ?? ''));
    if (!$apply) {
        echo "Preflight passed. No changes made. Back up both files before using --apply.\n";
        exit;
    }
    migrate_phase2($db, $score);
    echo "Phase 2 installed. Legacy token rows retained and revoked; privately provision current access.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration refused: ' . $e->getMessage() . "\n");
    exit(1);
}
