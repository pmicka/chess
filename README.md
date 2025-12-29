# Me vs The World Chess (PHP + SQLite)
Lightweight, framework-free chess experiment where a single host plays the internet via alternating turns stored in SQLite.

## Quick start (local)
1) Copy the sample local config: `cp config.local.php.example config.local.php` and fill in the placeholder constants (keys, email, base URL).  
2) Install PHP 8+: `php -v` should work.  
3) Initialize the database: `php init_db.php` (creates tables and inserts the first game).  
4) Run a local server from the project root: `php -S localhost:8000`.  
5) Open `http://localhost:8000` in a browser; the UI will read game state from `/api/state.php`.

## Deployment notes (DreamHost-friendly)
- Upload the repo contents to your site (e.g., via SSH/FTP).
- Ensure a writable `data/` directory exists alongside the code (contains `chess.sqlite` after init). Leave `.gitkeep` in git; runtime files stay server-side.
- Copy `config.local.php.example` to `config.local.php` on the server and set real values (TURNSTILE keys, BASE_URL, email addresses). The `config.php` file is tracked with safe defaults; your `config.local.php` stays untracked.
- From SSH, run `php init_db.php` once to create the SQLite file and initial game.
- Serve via PHP (DreamHost CGI/FastCGI). Endpoints live under `/api/*`.

## Files and expectations
- `config.php` holds safe, tracked defaults and includes `config.local.php` when present (for secrets).
- `data/` holds runtime artifacts only; it is ignored by git except for `.gitkeep` and `.htaccess`.
- `db.php` provides a PDO connection; `init_db.php` builds tables; `/api/state.php` returns the current game JSON.
