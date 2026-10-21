# Me vs the World Chess
Asynchronous chess experiment where the host plays the world on a single board. The stack is plain PHP + SQLite with a static frontend (chess.js) and small JSON endpoints under `/api/`. The public page is `index.php`; host-only moves live at `my_move.php` (token-gated via emailed links).

## Requirements
- PHP 8+ with `pdo_sqlite`, ctype, `openssl` (for `random_bytes`), JSON, and `mail()` enabled. No Composer or Node build steps.
- SQLite 3.24+ writable by PHP. Default path: `data/chess.sqlite`; runtime opens only an existing, migrated file.
- Node.js and enabled `shell_exec()` are existing runtime requirements for game-over detection using the bundled chess.js asset. The regression suites stub this subprocess; they do not verify hosting support.
- Cloudflare Turnstile keys: `TURNSTILE_SITE_KEY` and `TURNSTILE_SECRET_KEY` are required for visitor move submission; without them, `/api/visitor_move.php` will reject requests.
- Outgoing mail transport for PHP `mail()` so host links can be delivered to `YOUR_EMAIL`.
- Web server capable of running PHP (built-in `php -S` works for local use).

## Quick start (local)
1. Clone the repo and enter it.
2. Copy the sample config and set real values:
   ```bash
   cp config.local.php.example config.local.php
   ```
   - `YOUR_EMAIL` / `MAIL_FROM`: where host tokens are sent and which From header to use.  
   - `BASE_URL`: full URL to this app (used in emailed links, but only honored when it matches `https://patrickmicka.com/chess`).  
   - `TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY`: required for visitor moves.  
   - `DB_PATH`: defaults to `data/chess.sqlite`; keep it writable.  
   - `ADMIN_RESET_KEY`: required to use `/api/admin_reset.php`.
3. Ensure `data/` stays writable (it holds the SQLite file, score JSON, and optional `email.log`; `.htaccess` in that folder blocks web access).
4. Initialize the database (creates tables and inserts the first game):
   ```bash
   php init_db.php --new
   ```
5. Run a local server from the project root:
   ```bash
   php -S localhost:8000
   ```
6. Open `http://localhost:8000/` to view the board (reads state from `/api/state.php`).
7. For isolated development checks without email delivery, run `PHP_BIN=php python3 tests/host_authorization.py`. It uses synthetic credentials and stubbed mail in a temporary application. Do not use admin reset to recover a host link; reset intentionally destroys the active game's history.

## Deployment (shared hosting / DreamHost-style)
- Place the repo in your PHP document root (the app expects to live at `BASE_URL`; static assets and manifest currently point to `/chess/` paths).
- Verify the runtime requirements above, including the Node executable available to PHP. No dependency upgrades or build steps are performed by deployment.
- Copy `config.local.php.example` to `config.local.php` on the server with real secrets and URLs. `config.php` is tracked but only contains placeholders and will include `config.local.php` when present.
- Ensure the verified database directory is writable by the web user for SQLite journals. The repo ships no database. Existing deployments must use the Phase 2 migration guide below.
- Only for a verified new installation, initialize via SSH before serving requests:
  ```bash
  php init_db.php --new
  ```
- Email delivery relies on the host’s PHP `mail()` configuration; no SMTP credentials are stored in-repo.
- There are no in-repo cron jobs or deploy scripts; panel-level choices (PHP version, document root, mail configuration) are hosting-specific manual steps.

## Search Console Setup
- **Canonical base:** The site is anchored to `https://patrickmicka.com/chess/` via `CANONICAL_CHESS_BASE`. Any use of `BASE_URL` is sanitized so it only takes effect if it matches the canonical base; otherwise the canonical base is used for absolute links (including emails).
- **Sitemap:** Static sitemap at `https://patrickmicka.com/chess/sitemap.xml`. Add a line in the root site `robots.txt` (domain root) to reference it, e.g. `Sitemap: https://patrickmicka.com/chess/sitemap.xml`.
- **Verification:** To surface a `<meta name="google-site-verification">` on the public page, set `GOOGLE_SITE_VERIFICATION` in `config.local.php` (leave it blank in git). You can also upload the Google-provided HTML verification file to the web root if your host allows static files there.
- **Keep out of index:** Action endpoints (`my_move.php`, `/api/*.php`, `init_db.php`) send `X-Robots-Tag: noindex, nofollow`. Leave admin/API paths out of any sitemap entries.
- **Root-level HTTPS/non-www redirect:** If `/chess` is hosted in a subdirectory, keep `.htaccess` minimal here and enforce scheme/host at the domain root instead:
  ```apache
  RewriteEngine On
  RewriteCond %{HTTPS} !=on [OR]
  RewriteCond %{HTTP_HOST} ^www\. [NC]
  RewriteRule ^ https://patrickmicka.com%{REQUEST_URI} [R=301,L]
  ```

## Validation checks
- Verify redirects and single-hop behavior:
  ```bash
  curl -I http://patrickmicka.com/chess
  curl -I http://www.patrickmicka.com/chess
  curl -I https://www.patrickmicka.com/chess
  ```
- Verify host-page indexing/privacy headers and non-consuming GET/HEAD behavior with the isolated host-authorization suite; do not use production host-token paths for smoke checks.
- Verify sitemap reachable:
  ```bash
  curl -I https://patrickmicka.com/chess/sitemap.xml
  ```

## Operational notes
- **Health check:** `GET /api/health.php` verifies DB path existence/writability and whether `TURNSTILE_SECRET_KEY` is loaded.
- **Admin reset:** `POST /api/admin_reset.php` with `X-Admin-Key: <ADMIN_RESET_KEY>` resets the active game to the starting position, deletes outstanding tokens/locks, and returns a fresh `host_token`. On DreamHost/shared hosting you can set `ADMIN_RESET_KEY` as a panel-level environment variable or inside `config.local.php`; clients may send it via the `X-Admin-Key` header or an `admin_key` POST body field.
- **State integrity and migration:** Existing installations must follow the [Phase 2 migration guide](docs/STATE_INTEGRITY_PHASE2.md). Runtime never creates a replacement database or migrates implicitly. Move submissions require `game_id` and `expected_revision`; refresh existing browser tabs after deployment. SQLite now holds the imported score baseline and unique game results; the old score JSON remains unchanged as an archive.
- **Host next game:** Finished games issue a separate `next_game` link. POST its credential to `/api/host_next_game.php` to create one successor; replay returns the same successor ID without repeating score updates or emails. Used move tokens cannot start another game.
- **Host move submissions:** Host links are single-use capabilities emailed to `YOUR_EMAIL` by `send_host_turn_email()` (via `mail()`). `action=resend` requires a recognized unused/unrevoked capability issued less than seven days ago, bound to the latest game's current revision and appropriate host action. It sends a replacement only to the configured host email and never returns that credential. GET/HEAD never recover or issue tokens. See the [current token and deployment policy](docs/STATE_INTEGRITY_PHASE2.md).
- **Backups:** Persist the verified SQLite file (games, tokens, score baseline/results, transition receipts) and `config.local.php`. Keep the original `data/score.json` as an archived migration baseline; it no longer tracks current totals. Optional `data/email.log` records failed mail attempts.
- **Logging:** Application events go to the PHP error log. Database path checks log via `log_db_path_info()`; email failures append to `data/email.log` in addition to error_log.

## Troubleshooting
- `captcha_failed` or Turnstile errors: confirm valid `TURNSTILE_SITE_KEY`/`TURNSTILE_SECRET_KEY` and outbound HTTPS access to Cloudflare.
- Database path errors (`db_path`/`db_missing`): verify the actual existing database and legacy nested candidate before changing configuration. Never initialize a replacement to resolve missing state. Use the Phase 2 migration guide for existing databases; `init_db.php --new` is only for a verified new installation.
- `admin_key_missing` / `forbidden` from `/api/admin_reset.php`: set `ADMIN_RESET_KEY` in `config.local.php` or as an environment variable.
- Host links show “invalid/expired”: eligible unused expired links can request an email-only replacement using the page's resend button. Missing, invalid, used, or older links cannot request recovery; open the latest host email or contact the operator for private assistance. Do not reset the game to recover access.
- 404 while browsing GitHub URLs: unrelated to the app; this repo has no GitHub auth checks in runtime code.

## Security
- Secrets live in `config.local.php` (gitignored) or environment variables (only `ADMIN_RESET_KEY` is read from env); do not commit them.
- `data/` is blocked from HTTP by `.htaccess`, but ensure the web server honors it and that filesystem permissions keep SQLite files private.
- Host tokens are single-use and tied to a specific game; admin reset tokens also return via authenticated calls.

## Contributing / dev workflow
- Run `PHP_BIN=php python3 tests/state_integrity.py` for Phase 2 plus the Phase 1 authorization regressions, or `tests/host_authorization.py` for just authorization. Run `php -l` on modified PHP files. The suites use Python's standard library and PHP 8+ with PDO SQLite and ctype; no dependency upgrade is needed.
- Use feature branches and pull requests as usual; there is no enforced branching convention in-repo.
