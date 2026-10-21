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
    throw new RuntimeException('Missing config.php. Copy config.example.php to config.php and fill in your values.');
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/schema.php';
require_once __DIR__ . '/lib/chess.php';

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
        throw new RuntimeException("Configuration error: missing constant {$name} in config.php.");
    }
}

/**
 * Return the client IP (best-effort) for logging.
 */
function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/**
 * Redact a token by returning only the suffix (default last 6 characters).
 */
function token_suffix(string $token, int $length = 6): string
{
    if ($token === '') {
        return '';
    }
    return substr($token, -1 * max(1, $length));
}

/**
 * Emit a structured error_log line: "event key=value key2=value2".
 */
function log_event(string $event, array $fields = []): void
{
    $parts = [$event];

    // Attach request metadata when available.
    if (!array_key_exists('request_id', $fields) && function_exists('request_id')) {
        $fields['request_id'] = request_id();
    }
    if (!array_key_exists('ts', $fields)) {
        $fields['ts'] = gmdate('c');
    }

    foreach ($fields as $key => $value) {
        if ($value === null) {
            continue;
        }
        $safeKey = preg_replace('/[^a-zA-Z0-9_:-]/', '_', (string)$key);
        $safeValue = preg_replace('/\s+/', '_', (string)$value);
        $parts[] = "{$safeKey}={$safeValue}";
    }
    error_log(implode(' ', $parts));
}

/**
 * Resolve the SQLite path relative to the project root (db.php directory).
 */
function resolve_db_path_info(): array
{
    $configured = DB_PATH;

    // Anchor relative paths to the project root (this file's directory).
    $isAbsolute = database_path_is_absolute($configured);
    $anchored = $isAbsolute
        ? $configured
        : rtrim(__DIR__, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($configured, DIRECTORY_SEPARATOR);

    $dir = dirname($anchored);
    $dirReal = realpath($dir) ?: $dir;
    $realFile = realpath($anchored) ?: null;
    $exists = file_exists($anchored);
    $size = $exists ? @filesize($anchored) : null;
    $dirExists = is_dir($dir);
    $dirWritable = $dirExists ? is_writable($dir) : false;

    return [
        'legacy_candidate' => $isAbsolute ? __DIR__ . '/' . ltrim($configured, '/\\') : null,
        'configured' => $configured,
        'anchored' => $anchored,
        'dir_anchored' => $dir,
        'realpath' => $realFile,
        'dir_real' => $dirReal,
        'exists' => $exists,
        'size' => $size,
        'dir_exists' => $dirExists,
        'dir_writable' => $dirWritable,
    ];
}

/**
 * Validate that the DB directory exists and is writable. Creates the directory
 * when missing (if permitted) and throws a RuntimeException on failure.
 */
function ensure_db_path_ready(bool $createDir = true): array
{
    $info = resolve_db_path_info();
    $dir = $info['dir_anchored'];

    if (!$info['dir_exists'] && $createDir) {
        @mkdir($dir, 0775, true);
        clearstatcache(true, $dir);
        $info['dir_exists'] = is_dir($dir);
        $info['dir_writable'] = $info['dir_exists'] ? is_writable($dir) : false;
    }

    if (!$info['dir_exists']) {
        throw new RuntimeException('Database directory is missing and could not be created.');
    }
    if (!$info['dir_writable']) {
        throw new RuntimeException('Database directory is not writable.');
    }

    $dbPath = $info['realpath'] ?? $info['anchored'];
    if ($info['exists'] && !is_writable($dbPath)) {
        throw new RuntimeException('Database file exists but is not writable.');
    }

    return $info;
}

/**
 * Log SQLite path diagnostics for visibility across entrypoints.
 */
function log_db_path_info(string $context): array
{
    $info = resolve_db_path_info();
    error_log(sprintf(
        '%s db_path configured=%s anchored=%s real=%s dir=%s exists=%s size=%s dir_exists=%s dir_writable=%s',
        $context,
        $info['configured'],
        $info['anchored'],
        $info['realpath'] ?? 'n/a',
        $info['dir_real'],
        $info['exists'] ? '1' : '0',
        $info['size'] !== null ? (string)$info['size'] : 'n/a',
        $info['dir_exists'] ? '1' : '0',
        $info['dir_writable'] ? '1' : '0'
    ));

    return $info;
}

/**
 * Returns a shared PDO connection to the SQLite database.
 */
function database_path_is_absolute(string $path): bool
{
    return $path !== '' && ($path[0] === '/' || $path[0] === chr(92)
        || (strlen($path) > 2 && ctype_alpha($path[0]) && $path[1] === ':'
            && in_array($path[2], ['/', chr(92)], true)));
}

function open_existing_database(string $path, bool $readOnly = false): PDO
{
    // SQLite URI modes prevent accidental creation even if a file disappears.
    $escaped = str_replace(['%', '?', '#'], ['%25', '%3F', '%23'], $path);
    $db = new PDO('sqlite:file:' . $escaped . '?mode=' . ($readOnly ? 'ro' : 'rw'));
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys=ON');
    $db->exec('PRAGMA busy_timeout=2000');
    return $db;
}

function get_db(bool $create = false): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $info = resolve_db_path_info();
    $legacy = $info['legacy_candidate'];
    if ($legacy && is_file($legacy) && realpath($legacy) !== realpath($info['anchored'])) {
        throw new RuntimeException('Legacy database path detected. Select the verified file explicitly; refusing to create or switch databases.');
    }
    if (!$info['exists'] && !$create) {
        throw new RuntimeException('Configured database is missing. Verify its path; runtime will not create a replacement.');
    }
    ensure_db_path_ready($create);
    $path = $info['realpath'] ?? $info['anchored'];
    if ($create) {
        if ($info['exists']) throw new RuntimeException('Database already exists; refusing initialization.');
        $previousMask = umask(0077);
        try { $file = @fopen($path, 'x'); } finally { umask($previousMask); }
        if (!$file) throw new RuntimeException('Cannot exclusively create a new database.');
        fclose($file);
        $pdo = open_existing_database($path);
    } else {
        $pdo = open_existing_database($path);
    }
    if (!$create && !phase2_installed($pdo)) {
        $pdo = null;
        throw new RuntimeException('Phase 2 migration required. Runtime schema changes are disabled.');
    }
    return $pdo;
}

/** Reserve the writer before reading state, while retaining PDO rollback tracking. */
function begin_write_transaction(PDO $db): void
{
    $db->beginTransaction();
    try {
        $db->exec('UPDATE app_meta SET schema_version=schema_version WHERE id=1');
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function database_busy(Throwable $e): bool
{
    return $e instanceof PDOException && in_array((int)($e->errorInfo[1] ?? 0), [5, 6], true);
}

function revoke_game_tokens(PDO $db, int $gameId): void
{
    $db->prepare('UPDATE tokens SET revoked_at=CURRENT_TIMESTAMP WHERE game_id=? AND revoked_at IS NULL')
        ->execute([$gameId]);
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
 */
function token_expiry_from_row(PDO $db, array $row): ?DateTimeImmutable
{
    $text = (string)($row['expires_at'] ?? '');
    $expiry = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $text, new DateTimeZone('UTC'));
    return $expiry && $expiry->format('Y-m-d H:i:s') === $text ? $expiry : null;
}

/**
 * Insert a single-use host token for the given game.
 *
 * Returns the generated token string.
 */
function insert_host_move_token(PDO $db, int $gameId, DateTimeImmutable $expiresAt, string $purpose = 'your_move'): string
{
    if (!$db->inTransaction()) throw new RuntimeException('Token issuance requires a transaction.');
    $stmt = $db->prepare('SELECT revision,status,host_color,turn_color FROM games WHERE id=?');
    $stmt->execute([$gameId]);
    $game = $stmt->fetch(PDO::FETCH_ASSOC);
    $eligible = $purpose === 'your_move'
        ? $game && $game['status'] === 'active' && $game['host_color'] === $game['turn_color']
        : $purpose === 'next_game' && $game && $game['status'] === 'finished';
    if (!$eligible) throw new RuntimeException('No eligible host action for this game.');
    $token = generate_token_value();
    $db->prepare("INSERT INTO tokens(game_id,token,purpose,used,game_revision,expires_at)
        VALUES(?,?,?,0,?,?)")->execute([$gameId, $token, $purpose, $game['revision'], $expiresAt->format('Y-m-d H:i:s')]);
    return $token;
}

/**
 * Validate and return a host token row for "your_move" purpose.
 * Returns null if missing, expired, or already used.
 */
function validate_host_token(PDO $db, string $tokenValue, bool $allowUsed = false, bool $allowExpired = false, ?string $purpose = 'your_move'): array
{
    $result = [
        'ok' => false,
        'code' => 'missing',
        'row' => null,
    ];

    if ($tokenValue === '') {
        return $result;
    }

    $stmt = $db->prepare("SELECT * FROM tokens WHERE token = :token LIMIT 1");
    $stmt->execute([':token' => $tokenValue]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || !in_array($row['purpose'] ?? '', ['your_move', 'next_game'], true)
        || ($purpose !== null && $row['purpose'] !== $purpose) || !empty($row['revoked_at'])) {
        $result['code'] = 'invalid';
        return $result;
    }

    if (!empty($row['used']) || !empty($row['used_at'])) {
        if (!$allowUsed) {
            $result['code'] = 'used';
            return $result;
        }
        $result['code'] = 'used';
    }

    $gameStmt = $db->prepare('SELECT revision,status,host_color,turn_color,id=(SELECT MAX(id) FROM games) AS is_current FROM games WHERE id=?');
    $gameStmt->execute([$row['game_id']]);
    $boundGame = $gameStmt->fetch(PDO::FETCH_ASSOC);
    if (!$boundGame || !(int)$boundGame['is_current'] || $row['game_revision'] === null || (int)$row['game_revision'] !== (int)$boundGame['revision']
        || ($row['purpose'] === 'your_move' && ($boundGame['status'] !== 'active' || $boundGame['host_color'] !== $boundGame['turn_color']))
        || ($row['purpose'] === 'next_game' && $boundGame['status'] !== 'finished')) {
        return ['ok' => false, 'code' => 'invalid', 'row' => null];
    }
    $expiry = token_expiry_from_row($db, $row);
    if ($expiry === null) return ['ok' => false, 'code' => 'invalid', 'row' => null];
    if (datetime_utc() >= $expiry) {
        if (!$allowExpired) {
            $result['code'] = 'expired';
            return $result;
        }
        $result['code'] = 'expired';
    }

    $row['expires_at_dt'] = $expiry;

    $code = $result['code'] === 'missing' ? 'ok' : $result['code'];

    return [
        'ok' => true,
        'code' => $code,
        'row' => $row,
    ];
}

function fetch_valid_host_token(PDO $db, string $tokenValue): ?array
{
    $validation = validate_host_token($db, $tokenValue);
    if (($validation['ok'] ?? false) !== true) {
        return null;
    }

    return $validation['row'] ?? null;
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
          AND used = 0 AND revoked_at IS NULL
          AND game_revision = (SELECT revision FROM games WHERE id=:game_id)
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([':game_id' => $gameId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    $expiry = token_expiry_from_row($db, $row);
    if ($expiry === null || datetime_utc() >= $expiry) {
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

/** Server-authoritative status. Supply PGN to include verified repetition history. */
function detect_game_over_from_fen(string $fen, ?string $pgn = null): array
{
    $payload = ['fen' => $fen];
    if ($pgn !== null) $payload['pgn'] = $pgn;
    $result = chess_engine_request($payload);
    if (!chess_status_is_valid($result)) throw new ChessEngineUnavailable('Chess status could not be verified.');
    return $result;
}

/**
 * Mark a game finished, increment its revision, clear locks, and revoke tokens.
 */
function finish_game(PDO $db, int $gameId): void
{
    $db->prepare("UPDATE games SET status = 'finished', revision=revision+1, updated_at = CURRENT_TIMESTAMP WHERE id = :id AND status='active'")
        ->execute([':id' => $gameId]);
    $db->prepare('DELETE FROM locks WHERE game_id = :id')->execute([':id' => $gameId]);
    revoke_game_tokens($db, $gameId);
}

/**
 * Create a new game with flipped colors based on a completed game row.
 */
function create_next_game(PDO $db, array $completedGame): array
{
    $currentHost = strtolower($completedGame['host_color'] ?? 'white') === 'black' ? 'black' : 'white';
    $nextHost = $currentHost === 'white' ? 'black' : 'white';
    $nextVisitor = $nextHost === 'white' ? 'black' : 'white';
    $initialFen = starting_fen();
    $turnColor = 'white';

    $insert = $db->prepare("
        INSERT INTO games (host_color, visitor_color, turn_color, status, fen, pgn, last_move_san, updated_at)
        VALUES (:host_color, :visitor_color, :turn_color, 'active', :fen, '', NULL, CURRENT_TIMESTAMP)
    ");

    $insert->execute([
        ':host_color' => $nextHost,
        ':visitor_color' => $nextVisitor,
        ':turn_color' => $turnColor,
        ':fen' => $initialFen,
    ]);

    $newGameId = (int)$db->lastInsertId();

    // If host is White, they move first; pre-issue a token so the host can act immediately.
    $hostToken = null;
    $tokenExpiry = null;
    if ($nextHost === 'white') {
        $tokenInfo = ensure_host_move_token($db, $newGameId);
        $hostToken = $tokenInfo['token'] ?? null;
        $tokenExpiry = $tokenInfo['expires_at'] ?? null;
    }

    return [
        'id' => $newGameId,
        'host_color' => $nextHost,
        'visitor_color' => $nextVisitor,
        'turn_color' => $turnColor,
        'fen' => $initialFen,
        'host_token' => $hostToken,
        'token_expires_at' => $tokenExpiry,
    ];
}

/** Compatibility helper: unlike the legacy transformer, this validates legality. */
function apply_move_to_fen(string $fen, string $from, string $to, string $promotion, string $movingColor): string
{
    $result = chess_engine_request(['fen' => $fen, 'turn_color' => $movingColor,
        'move' => ['from' => $from, 'to' => $to, 'promotion' => $promotion]]);
    if (!empty($result['before']['over'])) throw new ChessStateException('illegal_move');
    if (!is_string($result['fen'] ?? null)) throw new ChessEngineUnavailable('Chess move could not be verified.');
    return $result['fen'];
}

/**
 * Send the host-turn email for the given game/token.
 */
function send_host_turn_email(int $gameId, string $hostToken, ?DateTimeInterface $expiresAt, string $lastMoveSan): array
{
    $warning = null;

    $link = canonical_chess_base() . '/my_move.php?token=' . urlencode($hostToken);
    $subject = 'Your turn — Me vs the World Chess';
    $body = "Game ID: {$gameId}\n"
        . "Last visitor move: {$lastMoveSan}\n"
        . "Link: {$link}\n"
        . "Token expires at: " . ($expiresAt instanceof DateTimeInterface ? $expiresAt->format('Y-m-d H:i:s T') : 'n/a') . "\n";

    $emailHeaders = 'From: ' . MAIL_FROM . "\r\n";

    $tokenSuffix = token_suffix($hostToken);

    try {
        if (!function_exists('mail')) {
            $warning = 'mail() unavailable';
        } else {
            $mailSent = @mail(YOUR_EMAIL, $subject, $body, $emailHeaders);
            if ($mailSent === false) {
                $warning = 'Email failed';
            }
        }
    } catch (Throwable $mailErr) {
        $warning = 'Email failed';
    }

    log_event('email_host_turn', [
        'game_id' => $gameId,
        'token_suffix' => $tokenSuffix,
        'result' => $warning === null ? 'sent' : 'failed',
    ]);

    if ($warning !== null) {
        error_log(sprintf(
            'email_host_turn_failed game_id=%d token_suffix=%s reason=%s',
            $gameId,
            $tokenSuffix,
            $warning
        ));
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
