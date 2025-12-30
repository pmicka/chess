<?php

/**
 * Helpers for admin authentication and diagnostics.
 */

function admin_request_id(): string
{
    try {
        return bin2hex(random_bytes(6)) . '-' . time();
    } catch (Throwable $e) {
        return 'req-' . time();
    }
}

function load_admin_reset_key(): string
{
    if (defined('ADMIN_RESET_KEY') && ADMIN_RESET_KEY !== '') {
        return (string)ADMIN_RESET_KEY;
    }

    $envKey = getenv('ADMIN_RESET_KEY');
    if ($envKey !== false && $envKey !== '') {
        return (string)$envKey;
    }

    return '';
}
