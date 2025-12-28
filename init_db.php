<?php
/**
 * init_db.php — One-time database bootstrap (CLI-friendly)
 *
 * Intent:
 * - Create required SQLite tables if they do not exist.
 * - Insert an initial active game if none exists.
 *
 * Tables (typical):
 * - games: current game state (FEN/PGN/turn/status), and host color for that game
 * - tokens: single-use tokens used to authorize host moves (emailed links)
 * - locks: lightweight mechanism to ensure only one visitor move is accepted per turn
 *
 * Game rules enforced at a data level:
 * - There is exactly one "active" game at a time in MVP.
 * - Host color flips per new game (new game uses opposite host color from prior game).
 *
 * Usage:
 * - Run from shell: php init_db.php
 * - Should print a clear success/failure message.
 */

require_once __DIR__ . '/db.php';

$db = get_db();

// Create tables if they do not exist.
$db->exec("
    CREATE TABLE IF NOT EXISTS games (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        host_color TEXT NOT NULL,
        visitor_color TEXT NOT NULL,
        turn_color TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'active',
        fen TEXT NOT NULL,
        pgn TEXT NOT NULL,
        last_move_san TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");

$db->exec("
    CREATE TABLE IF NOT EXISTS tokens (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        game_id INTEGER NOT NULL,
        token TEXT NOT NULL UNIQUE,
        purpose TEXT NOT NULL,
        used INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        used_at DATETIME,
        FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
    )
");

$db->exec("
    CREATE TABLE IF NOT EXISTS locks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        game_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (game_id) REFERENCES games(id) ON DELETE CASCADE
    )
");

// Insert an initial active game if none exist.
$stmt = $db->query("SELECT COUNT(*) AS count FROM games");
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ((int)$row['count'] === 0) {
    $initialFen = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';
    $db->prepare("
        INSERT INTO games (host_color, visitor_color, turn_color, status, fen, pgn, last_move_san, updated_at)
        VALUES (:host_color, :visitor_color, :turn_color, 'active', :fen, '', NULL, CURRENT_TIMESTAMP)
    ")->execute([
        ':host_color' => 'white',
        ':visitor_color' => 'black',
        ':turn_color' => 'white',
        ':fen' => $initialFen,
    ]);
    echo "Database initialized and initial game inserted.\n";
} else {
    echo "Database ready. Existing games found.\n";
}
