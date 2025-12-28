<?php
/**
 * db.php — Database access helper (SQLite via PDO)
 *
 * Intent:
 * - Provide a single, reliable PDO connection to the SQLite database.
 * - Keep database location configurable via DB_PATH (from config.php).
 *
 * Responsibilities:
 * - Load config.php
 * - Create PDO connection
 * - Enable foreign keys (SQLite PRAGMA)
 * - Set sensible error mode (exceptions)
 *
 * Non-responsibilities:
 * - No schema creation here (see init_db.php)
 * - No business logic here (endpoints handle game rules)
 */

// Load configuration (required for DB_PATH and other settings).
if (!file_exists(__DIR__ . '/config.php')) {
    exit('Missing config.php. Copy config.example.php to config.php and fill in your values.');
}
require_once __DIR__ . '/config.php';

// Ensure required constants exist so the rest of the app can rely on them.
$requiredConstants = [
    'YOUR_EMAIL',
    'BASE_URL',
    'TURNSTILE_SITE_KEY',
    'TURNSTILE_SECRET_KEY',
    'DB_PATH',
    'MAIL_FROM',
];

foreach ($requiredConstants as $name) {
    if (!defined($name)) {
        exit("Configuration error: missing constant {$name} in config.php.");
    }
}

/**
 * Returns a shared PDO connection to the SQLite database.
 */
function get_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');

    return $pdo;
}
