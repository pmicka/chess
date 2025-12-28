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
        if ($isPawn && (($movingColor === 'w' && $toCoords['rankIndex'] === 0) || ($movingColor === 'b' && $toCoords['rankIndex'] === 7))) {
            $promoPiece = strtolower($promotion ?: 'q')[0];
            $piece = $movingColor === 'w' ? strtoupper($promoPiece) : strtolower($promoPiece);
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
