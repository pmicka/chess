# Me vs the World Chess
Asynchronous chess experiment where the host plays the world on a single board. The stack is plain PHP + SQLite with a static frontend (chess.js) and small JSON endpoints under `/api/`. The public page is `index.php`; host-only moves live at `my_move.php` (token-gated via emailed links).

## Requirements
- PHP 8+ with `pdo_sqlite`, `openssl` (for `random_bytes`), JSON, and `mail()` enabled. No Composer or Node build steps.
- SQLite writable by PHP. Default path: `data/chess.sqlite`.
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
   - `BASE_URL`: full URL to this app (used in emailed links).  
   - `TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET_KEY`: required for visitor moves.  
   - `DB_PATH`: defaults to `data/chess.sqlite`; keep it writable.  
   - `ADMIN_RESET_KEY`: required to use `/api/admin_reset.php`.
3. Ensure `data/` stays writable (it holds the SQLite file, score JSON, and optional `email.log`; `.htaccess` in that folder blocks web access).
4. Initialize the database (creates tables and inserts the first game):
   ```bash
   php init_db.php
   ```
5. Run a local server from the project root:
   ```bash
   php -S localhost:8000
   ```
6. Open `http://localhost:8000/` to view the board (reads state from `/api/state.php`).
7. If email delivery is unavailable locally, you can mint a host link by POSTing your admin key:
   ```bash
   curl -X POST -H "X-Admin-Key: <ADMIN_RESET_KEY>" http://localhost:8000/api/admin_reset.php
   ```
   The response includes `host_token`; open `/my_move.php?token=...` with that value to play the host turn.

## Deployment (shared hosting / DreamHost-style)
- Place the repo in your PHP document root (the app expects to live at `BASE_URL`; static assets and manifest currently point to `/chess/` paths).
- Set PHP to 8+ with `pdo_sqlite` and `mail()` enabled. No additional extensions or build steps are referenced.
- Copy `config.local.php.example` to `config.local.php` on the server with real secrets and URLs. `config.php` is tracked but only contains placeholders and will include `config.local.php` when present.
- Ensure `data/` exists and is writable by the web user before first request. The repo ships only `.gitkeep` and `.htaccess`; the SQLite file is created at runtime.
- Run once after deploy (via SSH) to create the schema and seed the first game:
  ```bash
  php init_db.php
  ```
- Email delivery relies on the host’s PHP `mail()` configuration; no SMTP credentials are stored in-repo.
- There are no in-repo cron jobs or deploy scripts; panel-level choices (PHP version, document root, mail configuration) are hosting-specific manual steps.

## Search Console Setup
- **Canonical base:** The site is intended to live at `https://patrickmicka.com/chess/` (or your own `BASE_URL` ending in `/chess/`). `index.php` emits a canonical link using `BASE_URL` when set; make sure your deployment sets `BASE_URL` to the full public URL (including `/chess/`) to avoid split signals between `/` and `/chess/`.
- **Sitemap:** Static sitemap at `https://patrickmicka.com/chess/sitemap.xml`, referenced from `robots.txt`. If you host under a different domain/path, update both files to the correct absolute URL.
- **Verification:** To surface a `<meta name="google-site-verification">` on the public page, set `GOOGLE_SITE_VERIFICATION` in `config.local.php` (leave it blank in git). You can also upload the Google-provided HTML verification file to the web root if your host allows static files there.
- **Keep out of index:** `robots.txt` disallows `/chess/my_move.php`, `/chess/api/`, `/chess/init_db.php`, `/chess/scripts/`, and `/chess/data/`; `my_move.php` also sends `noindex`. Leave admin/API paths out of any sitemap entries.

## Operational notes
- **Health check:** `GET /api/health.php` verifies DB path existence/writability and whether `TURNSTILE_SECRET_KEY` is loaded.
- **Admin reset:** `POST /api/admin_reset.php` with `X-Admin-Key: <ADMIN_RESET_KEY>` resets the active game to the starting position, deletes outstanding tokens/locks, and returns a fresh `host_token`.
- **Host next game:** After a finished game, `POST /api/host_next_game.php` with JSON `{"token":"<host_token>"}` to flip colors, record the result, and start the next game.
- **Host move submissions:** Host links are single-use tokens emailed to `YOUR_EMAIL` by `send_host_turn_email()` (via `mail()`). `/api/my_move_submit.php` accepts host moves and supports an `action` of `resend` to issue a fresh token for the current host turn.
- **Backups:** Persist `data/chess.sqlite` (game state + tokens), `data/score.json` (win/draw counts), and `config.local.php`. Optional `data/email.log` records failed mail attempts.
- **Logging:** Application events go to the PHP error log. Database path checks log via `log_db_path_info()`; email failures append to `data/email.log` in addition to error_log.

## Troubleshooting
- `captcha_failed` or Turnstile errors: confirm valid `TURNSTILE_SITE_KEY`/`TURNSTILE_SECRET_KEY` and outbound HTTPS access to Cloudflare.
- Database path errors (`db_path`/`db_missing`): ensure `data/` exists and is writable; rerun `php init_db.php`.
- `admin_key_missing` / `forbidden` from `/api/admin_reset.php`: set `ADMIN_RESET_KEY` in `config.local.php` or as an environment variable.
- Host links show “invalid/expired”: request a fresh link via `/api/my_move_submit.php` with `{"action":"resend","token":"<old_or_empty>"}` or use the admin reset endpoint.
- 404 while browsing GitHub URLs: unrelated to the app; this repo has no GitHub auth checks in runtime code.

## Security
- Secrets live in `config.local.php` (gitignored) or environment variables (only `ADMIN_RESET_KEY` is read from env); do not commit them.
- `data/` is blocked from HTTP by `.htaccess`, but ensure the web server honors it and that filesystem permissions keep SQLite files private.
- Host tokens are single-use and tied to a specific game; admin reset tokens also return via authenticated calls.

## Contributing / dev workflow
- No automated tests or build tooling are present. Run `php -l` on modified files if you change PHP code.
- Use feature branches and pull requests as usual; there is no enforced branching convention in-repo.
