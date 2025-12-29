<?php
/**
 * config.php — Tracked baseline configuration (safe defaults only)
 *
 * Real secrets and environment-specific overrides belong in
 * config.local.php (which is gitignored). This file provides
 * placeholder values so the app can load without fatal errors in
 * environments where config.local.php is missing.
 */

$applyPlaceholderConfig = function (): void {
    // Contact email used for notifications or debugging.
    if (!defined('YOUR_EMAIL')) {
        define('YOUR_EMAIL', '');
    }

    // Base URL of the deployed site (e.g., https://example.com).
    if (!defined('BASE_URL')) {
        define('BASE_URL', '');
    }

    // Cloudflare Turnstile public key (shown in HTML).
    if (!defined('TURNSTILE_SITE_KEY')) {
        define('TURNSTILE_SITE_KEY', '');
    }

    // Cloudflare Turnstile secret key (server-side verification).
    if (!defined('TURNSTILE_SECRET_KEY')) {
        define('TURNSTILE_SECRET_KEY', '');
    }

    // Path to the SQLite database file (kept in writable data/).
    if (!defined('DB_PATH')) {
        define('DB_PATH', __DIR__ . '/data/chess.sqlite');
    }

    // From address used when emailing host tokens.
    if (!defined('MAIL_FROM')) {
        define('MAIL_FROM', '');
    }
};

$localConfig = __DIR__ . '/config.local.php';
if (file_exists($localConfig)) {
    require $localConfig;
}

$applyPlaceholderConfig();
unset($applyPlaceholderConfig);
