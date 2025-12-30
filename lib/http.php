<?php

/**
 * Shared HTTP helpers for JSON APIs.
 */

/**
 * Read JSON body into associative array (returns empty array on failure).
 */
function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Basic sanitizer: trim string values and reject non-scalars.
 */
function sanitize_string($value): string
{
    if (!is_scalar($value)) {
        return '';
    }
    return trim((string)$value);
}

/**
 * Respond with JSON including timestamp and request id.
 */
function respond_json(int $status, array $payload): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json');
    }

    if (!isset($payload['request_id'])) {
        $payload['request_id'] = request_id();
    }
    if (!isset($payload['occurred_at'])) {
        $payload['occurred_at'] = gmdate(DateTimeInterface::ATOM);
    }

    echo json_encode($payload);
    exit;
}
