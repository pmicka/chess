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

/**
 * Generate a URL-safe random token (no padding).
 */
function generate_token_value(int $bytes = 32): string
{
    $raw = random_bytes($bytes);
    $encoded = rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    return $encoded;
}

/**
 * Helper to see if the tokens table supports a given column.
 */
function tokens_table_has_column(PDO $db, string $column): bool
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        $stmt = $db->query("PRAGMA table_info(tokens)");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!empty($row['name'])) {
                $cache[$row['name']] = true;
            }
        }
    }

    return isset($cache[$column]);
}

/**
 * Returns a DateTimeImmutable in UTC for the given string.
 */
function datetime_utc(string $time = 'now'): DateTimeImmutable
{
    return new DateTimeImmutable($time, new DateTimeZone('UTC'));
}

/**
 * Compute a default host token expiry (2 hours from now, UTC).
 */
function default_host_token_expiry(): DateTimeImmutable
{
    return datetime_utc('now')->add(new DateInterval('PT2H'));
}

/**
 * Calculate the expiry timestamp for a token row.
 * Falls back to created_at + 2 hours if expires_at column is absent.
 */
function token_expiry_from_row(PDO $db, array $row): ?DateTimeImmutable
{
    if (tokens_table_has_column($db, 'expires_at') && !empty($row['expires_at'])) {
        try {
            return datetime_utc($row['expires_at']);
        } catch (Exception $e) {
            return null;
        }
    }

    if (!empty($row['created_at'])) {
        try {
            return datetime_utc($row['created_at'])->add(new DateInterval('PT2H'));
        } catch (Exception $e) {
            return null;
        }
    }

    return null;
}

/**
 * Insert a single-use host token for the given game.
 *
 * Returns the generated token string.
 */
function insert_host_move_token(PDO $db, int $gameId, DateTimeImmutable $expiresAt): string
{
    $token = generate_token_value();
    $hasExpires = tokens_table_has_column($db, 'expires_at');

    $sql = "
        INSERT INTO tokens (game_id, token, purpose, used, created_at" . ($hasExpires ? ", expires_at" : "") . ")
        VALUES (:game_id, :token, :purpose, 0, CURRENT_TIMESTAMP" . ($hasExpires ? ", :expires_at" : "") . ")
    ";

    $stmt = $db->prepare($sql);
    $params = [
        ':game_id' => $gameId,
        ':token' => $token,
        ':purpose' => 'your_move',
    ];

    if ($hasExpires) {
        $params[':expires_at'] = $expiresAt->format('Y-m-d H:i:s');
    }

    $stmt->execute($params);

    return $token;
}

/**
 * Validate and return a host token row for "your_move" purpose.
 * Returns null if missing, expired, or already used.
 */
function fetch_valid_host_token(PDO $db, string $tokenValue): ?array
{
    if ($tokenValue === '') {
        return null;
    }

    $stmt = $db->prepare("SELECT * FROM tokens WHERE token = :token LIMIT 1");
    $stmt->execute([':token' => $tokenValue]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || ($row['purpose'] ?? '') !== 'your_move') {
        return null;
    }

    if (!empty($row['used']) || !empty($row['used_at'])) {
        return null;
    }

    $expiry = token_expiry_from_row($db, $row);
    if ($expiry !== null && datetime_utc() >= $expiry) {
        return null;
    }

    $row['expires_at_dt'] = $expiry;

    return $row;
}
