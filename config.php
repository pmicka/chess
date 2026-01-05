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

    // Optional: Google Search Console verification token for the meta tag.
    if (!defined('GOOGLE_SITE_VERIFICATION')) {
        define('GOOGLE_SITE_VERIFICATION', '');
    }

    // Path to the SQLite database file (kept in writable data/).
    if (!defined('DB_PATH')) {
        define('DB_PATH', __DIR__ . '/data/chess.sqlite');
    }

    // From address used when emailing host tokens.
    if (!defined('MAIL_FROM')) {
        define('MAIL_FROM', '');
    }

    // Admin reset key used for POST /api/admin_reset.php
    if (!defined('ADMIN_RESET_KEY')) {
        $envKey = getenv('ADMIN_RESET_KEY');
        if (!defined('ADMIN_RESET_KEY_SOURCE')) {
            define('ADMIN_RESET_KEY_SOURCE', $envKey !== false && $envKey !== '' ? 'env' : 'default');
        }
        define('ADMIN_RESET_KEY', $envKey !== false ? $envKey : '');
    }
};

$localConfig = __DIR__ . '/config.local.php';
if (file_exists($localConfig)) {
    require $localConfig;
}

if (!defined('ADMIN_RESET_KEY_SOURCE')) {
    if (defined('ADMIN_RESET_KEY') && ADMIN_RESET_KEY !== '') {
        define('ADMIN_RESET_KEY_SOURCE', 'config_local');
    } else {
        $envKey = getenv('ADMIN_RESET_KEY');
        define('ADMIN_RESET_KEY_SOURCE', $envKey !== false && $envKey !== '' ? 'env' : 'default');
    }
}

$applyPlaceholderConfig();
unset($applyPlaceholderConfig);
