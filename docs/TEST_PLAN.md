# Test Plan

## Score tracker and live state refresh
1. Start the local PHP server (for example: `php -S localhost:8000`) from the repository root.
2. Load `http://localhost:8000/index.php` and confirm the score line renders from the server (matches `api/state.php` output).
3. Trigger a score change (finish a game and call `POST /api/host_next_game.php` as the host, or edit `data/score.json` to increment a total), then click **Refresh** on the public page.
4. Verify the score line updates immediately after the refresh/poll and that the board re-renders using the new state payload (including `score_line` and `score` fields).

## Host move submission redirect
1. Visit `my_move.php` with a valid host token.
2. Submit a legal move.
3. Confirm the page immediately redirects to `index.php` and the host page controls become disabled before navigation (no option to reuse the link).

## Admin reset authentication and messaging
1. Clear or omit `ADMIN_RESET_KEY` from configuration/environment and call `POST /api/admin_reset.php`; expect a 503 response with `code=admin_key_missing`, `request_id`, and `occurred_at`.
2. Call `POST /api/admin_reset.php` with an incorrect key via `X-Admin-Key`; expect a 403 response with `code=forbidden`, `request_id`, and `occurred_at`.
3. Call `POST /api/admin_reset.php` with the correct key; expect a 200 response containing `ok=true`, `used_key_source`, `request_id`, and `occurred_at`.

## Move review stability and keyboard shortcuts
1. Enter review mode by clicking **Back** and remain idle for at least 60 seconds while polling continues; verify the board does not revert to live automatically.
2. Use **Forward** to advance one or more plies and confirm the board stays in review until you reach the latest ply (where it should return to live).
3. Click **Live** and confirm the board resumes live updates and highlights the latest move.
4. Enter review mode at a specific ply, then trigger a refresh or solve the Turnstile challenge; after the page reloads/authorizes, confirm the board restores to the same ply and remains in review until you choose Live.
5. On desktop, press ArrowLeft/ArrowRight/Home/End to navigate history; confirm they mirror Back/Forward/start/live behaviors without adding new UI.
