<?php
/**
 * config.example.php — sample configuration (copy to config.php for real use)
 *
 * This file is meant for local development or as a template.
 * DO NOT commit your real config.php (it is ignored by git).
 */

// Contact email used for notifications or debugging.
const YOUR_EMAIL = 'you@example.com';

// Base URL of the deployed site (e.g., https://example.com).
const BASE_URL = 'http://localhost:8000';

// Cloudflare Turnstile public key (shown in HTML).
const TURNSTILE_SITE_KEY = 'turnstile_site_key_here';

// Cloudflare Turnstile secret key (server-side verification).
const TURNSTILE_SECRET_KEY = 'turnstile_secret_here';

// Path to the SQLite database file (kept in writable data/).
const DB_PATH = __DIR__ . '/data/chess.sqlite';

// From address used when emailing host tokens.
const MAIL_FROM = 'chess@example.com';

// Admin reset key required by /api/admin_reset.php.
const ADMIN_RESET_KEY = 'set-a-strong-admin-reset-key';
