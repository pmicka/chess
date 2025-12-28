<?php
/**
 * config.php — SERVER-ONLY configuration (DO NOT COMMIT)
 *
 * This file contains environment-specific configuration and secrets:
 * - CAPTCHA secret key (e.g., Turnstile secret)
 * - Email routing / SMTP credentials (if used)
 * - BASE_URL for constructing absolute links in emails
 * - DB_PATH pointing to the writable SQLite file location
 *
 * Rules:
 * - Must be excluded from git (via .gitignore).
 * - Must not be displayed or echoed to users.
 * - Treat keys/passwords as secrets.
 *
 * Typical constants:
 * - YOUR_EMAIL
 * - BASE_URL
 * - TURNSTILE_SITE_KEY (public; used in HTML)
 * - TURNSTILE_SECRET_KEY (secret; used server-side verification)
 * - DB_PATH
 * - MAIL_FROM
 */

