<?php

/**
 * Shared HTTP helpers for JSON APIs.
 */

/**
 * Generate (and memoize) a request identifier for log correlation.
 */
function request_id(): string
{
    static $id = null;

    if ($id === null) {
        try {
            $id = bin2hex(random_bytes(6)) . '-' . time();
        } catch (Throwable $e) {
            $id = 'req-' . time();
        }
    }

    return $id;
}

/**
 * Return the request path for diagnostics, without any credential-bearing query.
 */
function request_log_path(): string
{
    $path = explode('?', $_SERVER['REQUEST_URI'] ?? 'n/a', 2)[0];
    return preg_replace('/[\x00-\x20\x7f]/', '_', $path);
}

/**
 * Return an ISO-8601 timestamp for the current request lifecycle.
 */
function request_timestamp(): string
{
    static $ts = null;

    if ($ts === null) {
        try {
            $ts = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
        } catch (Throwable $e) {
            $ts = gmdate('c');
        }
    }

    return $ts;
}

/**
 * Emit a JSON response with request metadata and exit by default.
 */
function respond_json(int $status, array $payload): void
{
    $payload['request_id'] = request_id();
    $payload['occurred_at'] = request_timestamp();

    http_response_code($status);
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');

    echo json_encode($payload);
    exit;
}

/**
 * Require a POST method or respond with 405.
 */
function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respond_json(405, ['ok' => false, 'error' => 'POST required', 'code' => 'method_not_allowed']);
    }
}

/**
 * Read a JSON body with a sane size limit. Returns an array or null.
 */
function read_json_body(int $maxBytes = 65536): ?array
{
    $raw = file_get_contents('php://input');
    if ($raw === false) {
        return null;
    }

    if (strlen($raw) > $maxBytes) {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    return $decoded;
}

/**
 * Fetch a single header value case-insensitively.
 */
function header_value(string $name): string
{
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    if (!is_array($headers)) {
        $headers = [];
    }

    foreach ($headers as $key => $value) {
        if (strcasecmp((string)$key, $name) === 0) {
            return is_scalar($value) ? (string)$value : '';
        }
    }

    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    if (isset($_SERVER[$serverKey])) {
        return is_scalar($_SERVER[$serverKey]) ? (string)$_SERVER[$serverKey] : '';
    }

    return '';
}

/**
 * Normalize a scalar string input and clamp length.
 */
function clean_string($value, int $maxLength = 255): string
{
    $str = is_scalar($value) ? trim((string)$value) : '';
    if (strlen($str) > $maxLength) {
        $str = substr($str, 0, $maxLength);
    }
    return $str;
}

/**
 * Normalize a chess square (e.g., "e4") or return an empty string.
 */
function clean_square($value): string
{
    // Do not truncate malformed input such as "e2garbage" into a valid square.
    $sq = is_string($value) ? strtolower(trim($value)) : '';
    if (preg_match('/^[a-h][1-8]$/', $sq) !== 1) {
        return '';
    }
    return $sq;
}

/**
 * Normalize a promotion piece (q, r, b, n) or return an empty string.
 */
function clean_promotion($value): string
{
    $piece = strtolower(clean_string($value, 1));
    return in_array($piece, ['q', 'r', 'b', 'n'], true) ? $piece : '';
}


/** Strict integer preconditions, including form-encoded visitor requests. */
function clean_nonnegative_int($value): ?int
{
    if (!is_int($value) && !is_string($value)) return null;
    if (is_string($value) && preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1) return null;
    $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    return $number === false ? null : $number;
}
