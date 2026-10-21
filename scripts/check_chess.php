<?php
/** Read-only deployment check; never provisions credentials or repairs history. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../db.php';

$options = getopt('', ['database:']);
try {
    $path = $options['database'] ?? '';
    if (!is_string($path) || !database_path_is_absolute($path) || !is_file($path)) {
        throw new RuntimeException('Use --database=/verified/existing/file.sqlite; no database is created.');
    }
    $db = open_existing_database($path, true);
    if (!phase2_installed($db)) throw new RuntimeException('Phase 2 migration required.');
    $game = $db->query('SELECT id,fen,pgn,turn_color,status,revision FROM games ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    if (!$game) throw new RuntimeException('No current game found.');
    $status = chess_engine_request(['fen' => $game['fen'], 'pgn' => $game['pgn'], 'turn_color' => $game['turn_color']]);
    if (!chess_status_is_valid($status)) throw new ChessEngineUnavailable('Chess status could not be verified.');
    $matches = ($game['status'] === 'finished') === $status['over'];
    echo json_encode(['ok' => $matches, 'game_id' => (int)$game['id'], 'revision' => (int)$game['revision'],
        'stored_status' => $game['status'], 'detected_over' => $status['over'], 'reason' => $status['reason'],
        'message' => $matches ? 'Current state verified; no changes made.' : 'Status disagreement requires review; no changes made.']) . "\n";
    exit($matches ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Chess preflight refused: ' . $e->getMessage() . "\n");
    exit(1);
}
