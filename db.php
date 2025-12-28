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

/**
 * Return a valid (unused, unexpired) host token for the given game, if one exists.
 */
function fetch_valid_host_token_for_game(PDO $db, int $gameId): ?array
{
    $stmt = $db->prepare("
        SELECT *
        FROM tokens
        WHERE game_id = :game_id
          AND purpose = 'your_move'
          AND used = 0
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([':game_id' => $gameId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    $expiry = token_expiry_from_row($db, $row);
    if ($expiry !== null && datetime_utc() >= $expiry) {
        return null;
    }

    if (!empty($row['used_at'])) {
        return null;
    }

    $row['expires_at_dt'] = $expiry;

    return $row;
}

/**
 * Ensure there is a valid host token for the given game.
 * Returns an array with the token value and its expiry (if available).
 */
function ensure_host_move_token(PDO $db, int $gameId, ?DateTimeImmutable $expiresAt = null): array
{
    $existing = fetch_valid_host_token_for_game($db, $gameId);
    if ($existing && !empty($existing['token'])) {
        return [
            'token' => $existing['token'],
            'expires_at' => $existing['expires_at_dt'] ?? null,
        ];
    }

    $expiry = $expiresAt ?? default_host_token_expiry();
    $token = insert_host_move_token($db, $gameId, $expiry);

    return [
        'token' => $token,
        'expires_at' => $expiry,
    ];
}

/**
 * Return the canonical starting position FEN.
 */
function starting_fen(): string
{
    return 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1';
}

/**
 * Extract the fullmove number from a FEN string, defaulting to 1.
 */
function fullmove_number_from_fen(string $fen): int
{
    $parts = preg_split('/\\s+/', trim($fen));
    if (isset($parts[5]) && is_numeric($parts[5])) {
        $num = (int)$parts[5];
        return ($num > 0) ? $num : 1;
    }
    return 1;
}

/**
 * Remove leading PGN header lines (e.g., [FEN "..."]) and blank lines.
 */
function strip_pgn_headers(string $pgn): string
{
    $lines = preg_split('/\\r?\\n/', (string)$pgn);
    if ($lines === false) {
        return trim($pgn);
    }

    while (!empty($lines)) {
        $line = $lines[0];
        $isHeader = preg_match('/^\\s*\\[.*\\]\\s*$/', $line) === 1;
        $isBlank = trim($line) === '';
        if ($isHeader || $isBlank) {
            array_shift($lines);
            continue;
        }
        break;
    }

    return trim(implode("\n", $lines));
}

/**
 * Append a SAN move to an existing PGN string.
 *
 * This is a lightweight formatter meant to preserve accumulated PGN history
 * without relying on client-provided PGN (which may be truncated).
 */
function append_pgn_move(string $currentPgn, string $movingColor, string $moveSan, string $fenAfterMove): string
{
    $trimmed = trim(strip_pgn_headers($currentPgn));
    $moveSan = trim($moveSan);
    if ($moveSan === '') {
        return $trimmed;
    }

    $moveNumber = fullmove_number_from_fen($fenAfterMove);
    $color = strtolower($movingColor) === 'white' ? 'white' : 'black';

    if ($color === 'white') {
        $segment = "{$moveNumber}. {$moveSan}";
    } else {
        $lastNumber = null;
        if ($trimmed !== '' && preg_match_all('/(\\d+)\\./', $trimmed, $matches) && !empty($matches[1])) {
            $lastNumber = (int)end($matches[1]);
        }

        if ($lastNumber === $moveNumber) {
            $segment = $moveSan;
        } else {
            $segment = "{$moveNumber}... {$moveSan}";
        }
    }

    if ($trimmed === '') {
        return $segment;
    }

    return $trimmed . ' ' . $segment;
}

/**
 * Send the host-turn email for the given game/token.
 */
function send_host_turn_email(int $gameId, string $hostToken, ?DateTimeInterface $expiresAt, string $lastMoveSan): array
{
    $warning = null;

    $link = BASE_URL . '/my_move.php?token=' . urlencode($hostToken);
    $subject = 'Your turn — Me vs the World Chess';
    $body = "Game ID: {$gameId}\n"
        . "Last visitor move: {$lastMoveSan}\n"
        . "Link: {$link}\n"
        . "Token expires at: " . ($expiresAt instanceof DateTimeInterface ? $expiresAt->format('Y-m-d H:i:s T') : 'n/a') . "\n";

    $emailHeaders = 'From: ' . MAIL_FROM . "\r\n";

    try {
        $mailSent = @mail(YOUR_EMAIL, $subject, $body, $emailHeaders);
        if ($mailSent === false) {
            $warning = 'Email failed';
        }
    } catch (Throwable $mailErr) {
        $warning = 'Email failed';
    }

    if ($warning !== null) {
        $logLine = sprintf(
            "[%s] Failed to send host turn email for game_id=%d\n",
            datetime_utc()->format('Y-m-d H:i:s'),
            $gameId
        );
        @file_put_contents(__DIR__ . '/data/email.log', $logLine, FILE_APPEND);
    }

    return [
        'ok' => $warning === null,
        'warning' => $warning,
    ];
}
