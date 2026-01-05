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
    $isAbsolute = preg_match('/^(?:\/|[A-Za-z]:[\\\/])/', $configured) === 1;
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
function get_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dbInfo = resolve_db_path_info();
    $dbPath = $dbInfo['realpath'] ?? $dbInfo['anchored'];
    $dbDir = dirname($dbPath);

    if (!is_dir($dbDir)) {
        if (!@mkdir($dbDir, 0755, true) && !is_dir($dbDir)) {
            throw new RuntimeException("DB directory missing and could not be created: {$dbDir}");
        }
    }

    if (!is_writable($dbDir)) {
        $perms = fileperms($dbDir);
        $permOctal = $perms !== false ? substr(sprintf('%o', $perms), -4) : 'n/a';
        $ownerId = @fileowner($dbDir);
        $groupId = @filegroup($dbDir);
        $owner = 'n/a';
        if ($ownerId !== false) {
            $ownerDetails = function_exists('posix_getpwuid') ? posix_getpwuid($ownerId) : null;
            $owner = $ownerDetails['name'] ?? (string)$ownerId;
        }
        $group = 'n/a';
        if ($groupId !== false) {
            $groupDetails = function_exists('posix_getgrgid') ? posix_getgrgid($groupId) : null;
            $group = $groupDetails['name'] ?? (string)$groupId;
        }
        throw new RuntimeException("DB directory is not writable: {$dbDir} (perms={$permOctal}, owner={$owner}, group={$group})");
    }

    try {
        $pdo = new PDO('sqlite:' . $dbPath);
    } catch (PDOException $e) {
        throw new RuntimeException("SQLite open failed at {$dbPath} (dir: {$dbDir}): " . $e->getMessage(), 0, $e);
    }
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
    $stmt = $db->prepare("
        INSERT INTO tokens (game_id, token, purpose, used, created_at)
        VALUES (:game_id, :token, :purpose, 0, CURRENT_TIMESTAMP)
    ");
    $stmt->execute([
        ':game_id' => $gameId,
        ':token' => $token,
        ':purpose' => 'your_move',
    ]);

    return $token;
}

/**
 * Validate and return a host token row for "your_move" purpose.
 * Returns null if missing, expired, or already used.
 */
function validate_host_token(PDO $db, string $tokenValue, bool $allowUsed = false, bool $allowExpired = false): array
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

    if (!$row || ($row['purpose'] ?? '') !== 'your_move') {
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

    $expiry = token_expiry_from_row($db, $row);
    if ($expiry !== null && datetime_utc() >= $expiry) {
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
 * Derive game-over status from a FEN using chess.js (via Node).
 * Returns:
 * [
 *   'ok' => bool,
 *   'over' => bool,
 *   'reason' => string|null,
 *   'winner' => 'w'|'b'|null,
 *   'error' => string|null,
 * ]
 */
function detect_game_over_from_fen(string $fen): array
{
    $response = [
        'ok' => false,
        'over' => false,
        'reason' => null,
        'winner' => null,
        'error' => null,
    ];

    $script = __DIR__ . '/scripts/fen_status.js';
    if (!is_file($script)) {
        $response['error'] = 'Detector script missing';
        return $response;
    }

    $cmd = 'node ' . escapeshellarg($script) . ' ' . escapeshellarg($fen);
    $output = @shell_exec($cmd);
    if ($output === null || $output === false) {
        $response['error'] = 'Detector failed';
        return $response;
    }

    $decoded = json_decode(trim($output), true);
    if (!is_array($decoded)) {
        $response['error'] = 'Detector parse failure';
        return $response;
    }

    return [
        'ok' => true,
        'over' => (bool)($decoded['over'] ?? false),
        'reason' => $decoded['reason'] ?? null,
        'winner' => $decoded['winner'] ?? null,
        'error' => $decoded['error'] ?? null,
    ];
}

/**
 * Mark a game as finished and clear locks/tokens for that game.
 */
function finish_game(PDO $db, int $gameId): void
{
    $db->prepare("UPDATE games SET status = 'finished', updated_at = CURRENT_TIMESTAMP WHERE id = :id")
        ->execute([':id' => $gameId]);
    $db->prepare('DELETE FROM locks WHERE game_id = :id')->execute([':id' => $gameId]);
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
    append_pgn_move_dev_assertion();

    $trimmed = trim(strip_pgn_headers($currentPgn));
    $moveSan = trim($moveSan);
    if ($moveSan === '') {
        return $trimmed;
    }

    $color = strtolower($movingColor) === 'white' ? 'white' : 'black';
    $fullmoveNumber = fullmove_number_from_fen($fenAfterMove);
    $moveNumber = $color === 'white'
        ? $fullmoveNumber
        : max(1, $fullmoveNumber - 1);

    $lastNumber = null;
    if ($trimmed !== '' && preg_match_all('/(\\d+)\\./', $trimmed, $matches) && !empty($matches[1])) {
        $lastNumber = (int)end($matches[1]);
    }

    if ($color === 'white') {
        $segment = "{$moveNumber}. {$moveSan}";
    } else {
        $segment = ($lastNumber === $moveNumber)
            ? $moveSan
            : "{$moveNumber}. {$moveSan}";
    }

    if ($trimmed === '') {
        return $segment;
    }

    return $trimmed . ' ' . $segment;
}

function append_pgn_move_dev_assertion(): void
{
    static $hasRun = false;
    if ($hasRun || !getenv('DEV_ASSERT_PGN_FORMAT')) {
        return;
    }
    $hasRun = true;

    $pgn = '';
    $pgn = append_pgn_move($pgn, 'white', 'e4', '8/8/8/8/8/8/8/8 b - - 0 1');
    $pgn = append_pgn_move($pgn, 'black', 'e5', '8/8/8/8/8/8/8/8 w - - 0 2');
    $pgn = append_pgn_move($pgn, 'white', 'Nc3', '8/8/8/8/8/8/8/8 b - - 0 2');
    $pgn = append_pgn_move($pgn, 'black', 'Nc6', '8/8/8/8/8/8/8/8 w - - 0 3');

    if ($pgn !== '1. e4 e5 2. Nc3 Nc6' || strpos($pgn, '...') !== false) {
        trigger_error(sprintf('append_pgn_move dev assertion failed: "%s"', $pgn), E_USER_WARNING);
    }
}

/**
 * Convert an algebraic square (e.g., "e4") to file/rank indexes.
 */
function square_to_coords(string $square): array
{
    $square = strtolower(trim($square));
    if (!preg_match('/^[a-h][1-8]$/', $square)) {
        throw new InvalidArgumentException('Invalid square: ' . $square);
    }
    $file = ord($square[0]) - ord('a');
    $rank = (int)$square[1];
    return [
        'file' => $file,
        // rankIndex: 0 = rank 8 (top), 7 = rank 1 (bottom)
        'rankIndex' => 8 - $rank,
    ];
}

/**
 * Convert file/rank indexes back to algebraic square.
 */
function coords_to_square(int $file, int $rankIndex): string
{
    if ($file < 0 || $file > 7 || $rankIndex < 0 || $rankIndex > 7) {
        throw new InvalidArgumentException('Invalid coordinates');
    }
    $fileChar = chr(ord('a') + $file);
    $rank = 8 - $rankIndex;
    return $fileChar . $rank;
}

/**
 * Parse the board portion of a FEN string into a 2D array.
 */
function parse_fen_board(string $boardPart): array
{
    $rows = explode('/', $boardPart);
    if (count($rows) !== 8) {
        throw new InvalidArgumentException('FEN board must have 8 ranks');
    }

    $board = [];
    foreach ($rows as $row) {
        $cells = [];
        $len = strlen($row);
        for ($i = 0; $i < $len; $i++) {
            $char = $row[$i];
            if (ctype_digit($char)) {
                $emptyCount = (int)$char;
                if ($emptyCount < 1 || $emptyCount > 8) {
                    throw new InvalidArgumentException('Invalid empty count in FEN row');
                }
                for ($j = 0; $j < $emptyCount; $j++) {
                    $cells[] = null;
                }
            } elseif (preg_match('/[prnbqkPRNBQK]/', $char)) {
                $cells[] = $char;
            } else {
                throw new InvalidArgumentException('Invalid piece in FEN: ' . $char);
            }
        }
        if (count($cells) !== 8) {
            throw new InvalidArgumentException('FEN row must have 8 files');
        }
        $board[] = $cells;
    }

    return $board;
}

/**
 * Convert a 2D board array back into the board portion of a FEN string.
 */
function board_to_fen(array $board): string
{
    if (count($board) !== 8) {
        throw new InvalidArgumentException('Board must have 8 ranks');
    }

    $rows = [];
    for ($r = 0; $r < 8; $r++) {
        $row = $board[$r] ?? [];
        if (count($row) !== 8) {
            throw new InvalidArgumentException('Board row must have 8 files');
        }
        $fenRow = '';
        $empty = 0;
        for ($c = 0; $c < 8; $c++) {
            $cell = $row[$c];
            if ($cell === null) {
                $empty++;
            } else {
                if ($empty > 0) {
                    $fenRow .= $empty;
                    $empty = 0;
                }
                $fenRow .= $cell;
            }
        }
        if ($empty > 0) {
            $fenRow .= $empty;
        }
        $rows[] = $fenRow;
    }

    return implode('/', $rows);
}

/**
 * Remove castling rights from the castling field.
 */
function strip_castling_rights(string $castling, array $rightsToRemove): string
{
    if ($castling === '-' || $castling === '') {
        return '-';
    }

    $rights = str_split($castling);
    $filtered = array_values(array_diff($rights, $rightsToRemove));
    return empty($filtered) ? '-' : implode('', $filtered);
}

/**
 * Apply a single (already-legitimated) move to a FEN string.
 * This does not perform deep legality checking but ensures the board
 * transformation matches the provided move.
 */
function apply_move_to_fen(string $fen, string $from, string $to, string $promotion, string $movingColor): string
{
    $parts = preg_split('/\\s+/', trim($fen));
    if (count($parts) < 6) {
        throw new InvalidArgumentException('Invalid FEN');
    }

    [$boardPart, $activeColor, $castling, $enPassant, $halfmove, $fullmove] = $parts;
    $activeColor = strtolower($activeColor) === 'b' ? 'b' : 'w';
    $movingColor = strtolower($movingColor) === 'black' ? 'b' : 'w';

    if ($activeColor !== $movingColor) {
        throw new InvalidArgumentException('Move color does not match active color');
    }

    $board = parse_fen_board($boardPart);
    $fromCoords = square_to_coords($from);
    $toCoords = square_to_coords($to);

    $piece = $board[$fromCoords['rankIndex']][$fromCoords['file']] ?? null;
    if ($piece === null) {
        throw new InvalidArgumentException('No piece on from-square');
    }

    $isWhitePiece = ctype_upper($piece);
    if (($movingColor === 'w' && !$isWhitePiece) || ($movingColor === 'b' && $isWhitePiece)) {
        throw new InvalidArgumentException('Piece color mismatch for move');
    }

    $targetPiece = $board[$toCoords['rankIndex']][$toCoords['file']] ?? null;
    $isPawn = strtolower($piece) === 'p';
    $isKing = strtolower($piece) === 'k';
    $fileDiff = $toCoords['file'] - $fromCoords['file'];
    $rankDiff = $toCoords['rankIndex'] - $fromCoords['rankIndex'];
    $newCastling = $castling === '' ? '-' : $castling;
    $captureOccurred = false;
    $normalizedPromotion = strtolower((string)$promotion);
    if ($normalizedPromotion !== '' && !in_array($normalizedPromotion[0], ['q', 'r', 'b', 'n'], true)) {
        throw new InvalidArgumentException('Invalid promotion piece.');
    }
    $promotionSymbol = $normalizedPromotion === '' ? '' : $normalizedPromotion[0];
    $requiresPromotion = $isPawn && (($movingColor === 'w' && $toCoords['rankIndex'] === 0) || ($movingColor === 'b' && $toCoords['rankIndex'] === 7));
    if ($requiresPromotion && $promotionSymbol === '') {
        throw new InvalidArgumentException('Promotion piece required for pawn promotion.');
    }

    // Clear source square
    $board[$fromCoords['rankIndex']][$fromCoords['file']] = null;

    $isCastling = $isKing && abs($fileDiff) === 2;
    if ($isCastling) {
        // Move king
        $board[$toCoords['rankIndex']][$toCoords['file']] = $piece;
        // Move rook depending on side
        if ($movingColor === 'w') {
            if ($to === 'g1') { // kingside
                $board[7][5] = $board[7][7];
                $board[7][7] = null;
            } elseif ($to === 'c1') { // queenside
                $board[7][3] = $board[7][0];
                $board[7][0] = null;
            }
            $newCastling = strip_castling_rights($newCastling, ['K', 'Q']);
        } else {
            if ($to === 'g8') {
                $board[0][5] = $board[0][7];
                $board[0][7] = null;
            } elseif ($to === 'c8') {
                $board[0][3] = $board[0][0];
                $board[0][0] = null;
            }
            $newCastling = strip_castling_rights($newCastling, ['k', 'q']);
        }
    } else {
        // Handle en passant capture
        if ($isPawn && $targetPiece === null && $fromCoords['file'] !== $toCoords['file'] && strtolower($enPassant) === strtolower($to)) {
            $captureRank = $movingColor === 'w' ? $toCoords['rankIndex'] + 1 : $toCoords['rankIndex'] - 1;
            if ($captureRank < 0 || $captureRank > 7) {
                throw new InvalidArgumentException('Invalid en passant capture');
            }
            $capturedEp = $board[$captureRank][$toCoords['file']] ?? null;
            if ($capturedEp === null) {
                throw new InvalidArgumentException('Invalid en passant capture target');
            }
            $isCapturedWhite = ctype_upper($capturedEp);
            if (($movingColor === 'w' && $isCapturedWhite) || ($movingColor === 'b' && !$isCapturedWhite)) {
                throw new InvalidArgumentException('Invalid en passant capture target');
            }
            $board[$captureRank][$toCoords['file']] = null;
            $captureOccurred = true;
        }

        if ($targetPiece !== null) {
            $captureOccurred = true;
        }

        // Place moved piece (with promotion if applicable)
        if ($requiresPromotion) {
            $piece = $movingColor === 'w' ? strtoupper($promotionSymbol) : strtolower($promotionSymbol);
        }
        $board[$toCoords['rankIndex']][$toCoords['file']] = $piece;

        // Update castling rights when king or rook move/captured
        if ($isKing) {
            $newCastling = strip_castling_rights($newCastling, $movingColor === 'w' ? ['K', 'Q'] : ['k', 'q']);
        }
        if (strtolower($piece) === 'r') {
            // Rook moved from its original square
            $fromSquare = strtolower($from);
            if ($fromSquare === 'h1') $newCastling = strip_castling_rights($newCastling, ['K']);
            if ($fromSquare === 'a1') $newCastling = strip_castling_rights($newCastling, ['Q']);
            if ($fromSquare === 'h8') $newCastling = strip_castling_rights($newCastling, ['k']);
            if ($fromSquare === 'a8') $newCastling = strip_castling_rights($newCastling, ['q']);
        }
        if (strtolower($to) === 'h1') $newCastling = strip_castling_rights($newCastling, ['K']);
        if (strtolower($to) === 'a1') $newCastling = strip_castling_rights($newCastling, ['Q']);
        if (strtolower($to) === 'h8') $newCastling = strip_castling_rights($newCastling, ['k']);
        if (strtolower($to) === 'a8') $newCastling = strip_castling_rights($newCastling, ['q']);
    }

    // En passant target
    $newEnPassant = '-';
    if ($isPawn && abs($rankDiff) === 2) {
        $epRankIndex = $movingColor === 'w' ? $fromCoords['rankIndex'] - 1 : $fromCoords['rankIndex'] + 1;
        $newEnPassant = coords_to_square($fromCoords['file'], $epRankIndex);
    }

    // Halfmove clock
    $halfmoveClock = is_numeric($halfmove) ? (int)$halfmove : 0;
    if ($isPawn || $captureOccurred) {
        $halfmoveClock = 0;
    } else {
        $halfmoveClock++;
    }

    // Fullmove number increments after black's move
    $fullmoveNumber = is_numeric($fullmove) ? (int)$fullmove : 1;
    if ($movingColor === 'b') {
        $fullmoveNumber = max(1, $fullmoveNumber + 1);
    }

    $nextColor = $movingColor === 'w' ? 'b' : 'w';
    $boardFen = board_to_fen($board);
    $castlingField = $newCastling === '' ? '-' : $newCastling;

    return trim("{$boardFen} {$nextColor} {$castlingField} {$newEnPassant} {$halfmoveClock} {$fullmoveNumber}");
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
