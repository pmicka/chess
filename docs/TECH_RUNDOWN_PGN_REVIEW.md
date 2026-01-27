# Technical rundown: PGN review kit extraction (read-only)

## Executive summary (1-page)
This repo is a PHP + SQLite chess site with no frontend build system. The public app is `index.php`, which renders a static HTML shell plus an inlined, monolithic script that implements board rendering, PGN history review, opening lookup, and move submission. The viewer logic you want to extract lives almost entirely inside the `<script>` tag in `index.php`, backed by three static assets: `assets/chess.min.js` (chess.js), `assets/ui_helpers.js` (orientation + piece rendering helpers), and `assets/board_id_map.js` (opaque square IDs). The PGN review UI (forward/back/live, FEN/PGN readout, and opening explorer) is already implemented on the public page; it is the best source of reusable viewer logic.

There is no Node tooling or bundler. Static assets are served from `assets/` and `manifest.webmanifest`; CSS is a single file at `assets/css/style.css`. The opening explorer is client-only: it fetches `assets/data/eco_lite.json` and does prefix matching against UCI-style move tokens derived from chess.js’s verbose history. No remote opening APIs are used; the only remote call on the client is Cloudflare Turnstile (for move submission), and the only remote call on the server is Turnstile verification.

Gameplay and security flows are tightly coupled to PHP endpoints under `/api` and the host-only `my_move.php` page. Those should not be part of the read-only kit. The viewer should be extracted as a standalone, dependency-light module that accepts PGN (and optionally initial FEN) and renders a board + notation, without any move submission, Turnstile, or server polling.

The recommended extraction path is to build a small package (e.g., `packages/pgn-review/`) that exposes a `mountPgnReview(containerEl, opts)` API. The package should directly reuse the board renderer and history/notation logic from `index.php`, along with `assets/chess.min.js`, `assets/ui_helpers.js`, and the ECO dataset. The extraction should preserve the current “board + history + opening” UX but remove all server-bound behaviors (polling, submission, Turnstile, host-only paths). The minimal dist should include JS, CSS, piece SVGs, and `eco_lite.json`.

Before extraction, key decisions are needed: target styling (reuse `assets/css/style.css` vs. slimmer CSS), storage allowances (localStorage/sessionStorage usage is baked into the opening cache and view state), and how to package assets (relative URL strategy, or allow the host to inject base paths). These are solvable but must be decided up front to avoid rework.

---

## 1) Build system inventory
- **Framework/build tool:** None. The app is plain PHP serving HTML + static assets; the board logic is in a `<script>` tag inside `index.php`. There is no `package.json` or bundler. (See `index.php` and repo root.)
- **Package manager:** None in-repo. Deployment is PHP 8+ with SQLite and mail enabled; no Composer or Node steps are referenced. (`README.md`.)
- **CSS handling:** Single global stylesheet `assets/css/style.css` loaded directly by `index.php`/`my_move.php`. (`index.php` and `assets/css/style.css`.)
- **Static assets:** All static assets (JS, data, images, icons, pieces) live under `assets/`. Background images and icons are referenced via `/chess/`-prefixed paths in CSS. (`assets/` tree and `assets/css/style.css`.)

## 2) Entrypoints and routing
- **Main public entrypoint:** `index.php` (visitor-facing). It renders the board, review controls, opening snapshot, and move submission UI. It loads static JS helpers and chess.js, then runs a large inline script to manage client state and render the board. (`index.php`.)
- **Host entrypoint:** `my_move.php` (token-gated, host-only move submission). (`my_move.php`.)
- **Server API endpoints:**
  - `api/state.php`: read-only JSON state used by both pages. (`api/state.php`.)
  - `api/visitor_move.php`: visitor submission endpoint (Turnstile-gated). (`api/visitor_move.php`.)
  - `api/my_move_submit.php`: host move submission. (`api/my_move_submit.php`.)
  - `api/admin_reset.php`, `api/host_next_game.php`, `api/health.php`: admin/ops flows. (`api/*.php`.)
- **Routing model:** No client-side router; PHP pages map to file paths.

## 3) Viewer subsystem deep dive (most important)
### Board rendering layer
- **Rendering approach:** Custom DOM renderer in `index.php` creates a 10x10 grid (including file/rank labels) and inserts per-square divs with piece images. The square IDs are opaque and session-scoped via `assets/board_id_map.js`. (`index.php`, `assets/board_id_map.js`.)
- **Piece rendering:** Uses `assets/ui_helpers.js` to map piece types and colors to SVG assets; falls back to placeholder SVGs if assets fail. (`assets/ui_helpers.js`, `index.php`.)
- **Board orientation:** Uses `ChessUI.orientationLayout()` (or local fallback) to reverse file/rank order based on orientation. (`assets/ui_helpers.js`, `index.php`.)

### PGN parsing and move list representation
- **Library:** `assets/chess.min.js` (chess.js) provides `Chess`, `load_pgn`, `history`, and `fen` APIs. (`assets/chess.min.js`, `index.php`.)
- **Parsing:** `index.php` normalizes PGN text (fixing ply-numbered lines), loads it with `Chess().load_pgn(..., { sloppy: true })`, and reconstructs SAN history for navigation. (`index.php`.)
- **Move representation:** SAN strings (from chess.js history) drive history/timeline; verbose history (from/to/promotion) is used for opening lookup and snapshot UI. (`index.php`.)

### Navigation controls (forward/back/live)
- **State model:** A `historyState` object holds a FEN timeline and SAN array; `viewState` tracks `mode` (`live` or `review`) and `selectedPly`. Moving forward/back updates `viewState` and reloads the appropriate FEN. (`index.php`.)
- **Controls:** Buttons + keyboard arrows apply `applyHistoryPosition()`, while `Live` returns to the latest ply. (`index.php`.)

### FEN generation and state reset
- **FEN timeline:** `buildHistoryTimeline()` replays SAN history via chess.js to generate FENs for each ply, with optional starting FEN; fallback uses server FEN if parsing fails. (`index.php`.)
- **Reset:** `renderHistoryPosition()` loads FEN into `game` (chess.js instance) and re-renders the board. (`index.php`.)

### Engine/analyzer integration
- **None detected:** No stockfish or eval bar references in code or assets. (No such references in `index.php` or `assets/`.)

### UI architecture and state management
- **Architecture:** Vanilla JS (no framework). UI is built via DOM APIs in `index.php`, with module-like helpers in `assets/ui_helpers.js` and `assets/board_id_map.js`.
- **Global state:** Local variables (`state`, `historyState`, `viewState`, `notationData`, etc.) inside the `index.php` script; no Redux/Zustand/etc. (`index.php`.)
- **Storage:** `localStorage` caches ECO data; `sessionStorage` persists “details” panel visibility and view state. (`index.php`.)

## 4) Opening explorer deep dive
- **Modules involved:** Opening logic is in `index.php` (functions `ecoLiteStore`, `getOpeningInfo`, and helpers like `buildMoveTokensFromHistory`). (`index.php`.)
- **Data source:** Local `assets/data/eco_lite.json` containing `{ eco, name, moves }` entries where `moves` is a space-delimited list of UCI tokens like `e2e4 e7e5`. (`assets/data/eco_lite.json`, `index.php`.)
- **Loading:** `ecoLiteStore.load()` fetches `assets/data/eco_lite.json` with `cache: 'force-cache'`, hydrates entries into arrays, and stores them in `localStorage` (`chess_eco_lite_cache_v1`). (`index.php`.)
- **Matching algorithm:**
  - Chess.js provides verbose move history; these are converted to UCI tokens like `e2e4` in `buildMoveTokensFromHistory()`.
  - `getOpeningInfo()` filters ECO entries whose `movesArray` is a prefix of the game tokens.
  - Short 2-ply matches are marked generic; if longer matches exist, the algorithm filters out 2-ply umbrella lines.
  - The best match is the longest prefix, then non-generic names, then lexical order and dataset order. (`index.php`.)
- **Output:** UI shows `ECO — name` in the “Opening” snapshot. (`index.php`.)
- **Remote APIs:** None. All opening logic is local; no network calls beyond fetching `eco_lite.json` from the same origin. (`index.php`.)
- **Licensing/attribution:** Chess piece assets are CC BY-SA 3.0 (Lichess/CBurnett). ECO dataset licensing is not stated in-repo; this is a question to resolve before reuse. (`assets/pieces/lichess/LICENSE.txt`, `assets/data/eco_lite.json`.)

## 5) Gameplay + anti-abuse areas (avoid for review kit)
**Do not include these in the read-only bundle:**
- **Turnstile integration:**
  - Client script tag in `index.php` (`https://challenges.cloudflare.com/turnstile/v0/api.js`).
  - Client callbacks (`onTurnstileSuccess`, `onTurnstileExpired`, `onTurnstileError`) and token usage before submissions. (`index.php`.)
  - Server verification in `api/visitor_move.php` using `https://challenges.cloudflare.com/turnstile/v0/siteverify`. (`api/visitor_move.php`.)
- **Move submission flows:**
  - Visitor submission handler (`api/visitor_move.php`).
  - Host submission handler (`api/my_move_submit.php`) and host-only page (`my_move.php`). (`api/visitor_move.php`, `api/my_move_submit.php`, `my_move.php`.)
- **Auth/session logic:** Host token validation in `db.php` + `my_move.php` + `api/state.php`. (`my_move.php`, `api/state.php`.)
- **Realtime/polling:** `index.php` polls `api/state.php` every 20 seconds; remove in read-only kit. (`index.php`.)
- **Rate limiting/bot detection:** Turnstile gating and server-side checks in `api/visitor_move.php` (CAPTCHA, turn checking, state locks). (`api/visitor_move.php`.)

## 6) Portability blockers for pmicka.com (non-iframe)
- **Backend assumptions:** `index.php` assumes `/api/state.php` exists and polls it; review kit must replace with a local PGN/FEN input mechanism. (`index.php`, `api/state.php`.)
- **Absolute asset paths:** CSS references `/chess/assets/bg.*`; `manifest.webmanifest` and social URLs assume `/chess/`. These should be parameterized or made relative for embedding on `pmicka.com`. (`assets/css/style.css`, `index.php`, `manifest.webmanifest`.)
- **Storage usage:** `localStorage` for ECO caching and `sessionStorage` for view state/details. Decide if allowed in pmicka.com’s CSP/storage policy. (`index.php`.)
- **Bundler assumptions:** None. But any extraction should maintain UMD globals (`Chess`, `ChessUI`, `BoardIdMap`) or provide replacements. (`assets/chess.min.js`, `assets/ui_helpers.js`, `assets/board_id_map.js`.)
- **Font loading:** Uses system fonts only; no external font dependencies. (`assets/css/style.css`.)
- **Large assets:** `assets/data/eco_lite.json` and piece SVGs must be hosted or bundled; background image is large and currently referenced via absolute `/chess/` path. (`assets/data/eco_lite.json`, `assets/pieces/lichess/*`, `assets/css/style.css`.)

## 7) Recommended extraction plan
### Proposed package layout
- `packages/pgn-review/`
  - `src/` (extracted JS module + minimal HTML templates)
  - `style.css` (subset of `assets/css/style.css` or a new scoped stylesheet)
  - `assets/` (piece SVGs + background optional)
  - `data/eco_lite.json`

### Minimal public API (proposal)
```ts
mountPgnReview(containerEl: HTMLElement, opts: {
  pgn: string;
  initialFen?: string; // optional if PGN starts from non-standard position
  initialPly?: number; // defaults to last ply
  orientation?: 'white' | 'black';
  showExplorer?: boolean; // opening snapshot + ECO lookup
  showNotation?: boolean; // FEN/PGN readout
  allowStorage?: boolean; // toggle localStorage/sessionStorage usage
  assetsBaseUrl?: string; // base URL for pieces/data
  theme?: 'dark' | 'light';
}): { destroy(): void; setPgn(nextPgn: string, nextFen?: string): void; }
```

### Reuse vs copy strategy
- **Reuse directly:**
  - `assets/chess.min.js` (chess.js).
  - `assets/ui_helpers.js` (piece rendering + orientation logic).
  - `assets/board_id_map.js` (opaque square IDs to avoid DOM ID assumptions).
  - Opening dataset `assets/data/eco_lite.json`.
- **Extract from `index.php`:**
  - Board renderer (`renderBoard`, piece rendering helpers).
  - History/PGN handling (`buildHistoryTimeline`, `normalizeMovetext`, `parseSansFromPgn`).
  - Opening lookup (`ecoLiteStore`, `getOpeningInfo`).
  - Notation panel (`showNotation` / `hideNotation`).
- **Omit entirely:**
  - Polling, submission, Turnstile, host-only UI.

### Minimal dist output
- One JS bundle exposing `mountPgnReview` + `destroy`.
- One CSS file (scoped or namespaced).
- Assets: piece SVGs, optional board background, `eco_lite.json`.

### How pmicka.com should initialize (proposal)
- On page load, scan for `pre > code.language-pgn` blocks.
- For each block:
  1. Parse PGN text.
  2. Insert a container `div` after the block.
  3. Call `mountPgnReview(container, { pgn, showExplorer: true, showNotation: false })`.
- Optionally replace the `<pre>` with the interactive view.

## 8) Unanswered questions (>=10)
1) **ECO dataset licensing** — Why it matters: pmicka.com reuse requires license clarity. Where: check source of `assets/data/eco_lite.json` and any scripts used to generate it (`scripts/validate_eco_lite.py`). Options: obtain explicit license + attribution, replace with a licensed dataset, or drop opening names.
2) **Does PGN ever include non-starting FEN?** — Matters for initial FEN support. Where: check DB seed/game history in `init_db.php`/`db.php`. Options: always assume start, or support custom FEN in API and review kit.
3) **Required orientation logic** — Matters for viewer UX (white/black perspective). Where: `index.php` uses `visitor_color` from API and `ChessUI.orientationLayout()`. Options: default white, or expose `orientation` option.
4) **Notation visibility defaults** — Matters for UX and embedding. Where: `index.php` toggles notation with a button. Options: hide by default vs. show by default vs. configurable.
5) **LocalStorage/sessionStorage policy** — Matters for pmicka.com CSP/privacy. Where: `ecoLiteStore` and `persistViewState` in `index.php`. Options: allow storage (better perf/UX) or disable storage (stateless).
6) **Asset base URL strategy** — Matters for embedding on different host paths. Where: CSS uses `/chess/assets/...`; JS references `assets/...`. Options: bundle with relative paths, or accept `assetsBaseUrl` option.
7) **Should the review kit accept live updates?** — Matters for integration with a backend. Where: `index.php` polls `/api/state.php`. Options: omit polling entirely, or allow host to call `setPgn` on updates.
8) **Opening lookup behavior for short lines** — Matters for accuracy. Where: `getOpeningInfo()` filters 2-ply matches. Options: keep as-is, or expose a config to allow shallow matches.
9) **Accessibility requirements** — Matters for pmicka.com compliance. Where: `index.php` uses ARIA labels, but interactive behavior may need keyboard focus handling. Options: expand keyboard support or keep minimal.
10) **Scope of styling reuse** — Matters for bundle size and integration. Where: `assets/css/style.css` is global and includes layout for the whole site. Options: carve out a minimal stylesheet or namespace classes.
11) **Piece asset licensing/attribution** — Matters for redistribution. Where: `assets/pieces/lichess/LICENSE.txt`. Options: retain CC BY-SA with attribution or swap in other assets.
12) **PGN normalization expectations** — Matters for input flexibility. Where: `normalizePlyNumberedPGN` and `normalizeMovetext` in `index.php`. Options: keep existing normalization or integrate a more robust parser.

---

## Key file map (top 20)
| Path | Purpose |
| --- | --- |
| `index.php` | Public page, includes all viewer logic (board render, history, opening lookup, submission). |
| `my_move.php` | Host-only move page (token gated). |
| `api/state.php` | Read-only JSON game state. |
| `api/visitor_move.php` | Visitor move submission + Turnstile verification. |
| `api/my_move_submit.php` | Host move submission endpoint. |
| `api/admin_reset.php` | Admin reset endpoint. |
| `api/host_next_game.php` | Starts the next game after completion. |
| `api/health.php` | Health check endpoint. |
| `assets/chess.min.js` | chess.js library (rules, PGN/FEN). |
| `assets/ui_helpers.js` | Piece rendering and orientation helpers. |
| `assets/board_id_map.js` | Opaque square ID mapping. |
| `assets/data/eco_lite.json` | ECO opening dataset (UCI move prefixes). |
| `assets/css/style.css` | Main stylesheet for board + UI. |
| `assets/pieces/lichess/*` | Piece SVGs + license. |
| `lib/score.php` | Scoreboard data load/save. |
| `lib/http.php` | JSON response + request helpers. |
| `db.php` | DB access + game logic helpers. |
| `init_db.php` | Creates schema + seeds first game. |
| `manifest.webmanifest` | PWA manifest (paths under `/chess/`). |
| `README.md` | Deployment/config overview. |

## Reuse matrix
| Component | Keep? | Why | Notes | File paths |
| --- | --- | --- | --- | --- |
| Board renderer | ✅ Keep | Core of the review kit; already handles orientation and highlights. | Extract from `index.php`; remove click-to-move. | `index.php` |
| PGN normalization + history | ✅ Keep | Needed for forward/back and FEN timeline. | Uses chess.js; can be standalone. | `index.php`, `assets/chess.min.js` |
| Opening explorer | ✅ Keep | Desired feature; local dataset. | Ensure licensing; cache logic optional. | `index.php`, `assets/data/eco_lite.json` |
| Notation panel (FEN/PGN) | ✅ Keep | Useful in review context. | Provide toggle option. | `index.php` |
| Piece assets | ✅ Keep | Required for board visuals. | CC BY-SA 3.0 attribution required. | `assets/pieces/lichess/*` |
| UI helpers | ✅ Keep | Encapsulates orientation + piece rendering. | Reuse as-is. | `assets/ui_helpers.js` |
| Board ID map | ✅ Keep | Avoids exposing square IDs in DOM. | Reuse as-is. | `assets/board_id_map.js` |
| Turnstile integration | ❌ Avoid | Only for anti-abuse on move submission. | Remove client script + server verification. | `index.php`, `api/visitor_move.php` |
| Submission endpoints | ❌ Avoid | Gameplay-only. | Not required for read-only viewer. | `api/visitor_move.php`, `api/my_move_submit.php` |
| Host token logic | ❌ Avoid | Auth + gameplay workflow. | Not relevant to read-only review. | `my_move.php`, `api/state.php` |
| Polling/state sync | ❌ Avoid | Depends on backend. | Replace with manual `setPgn()` API. | `index.php` |
| Scoreboard | ⚠️ Optional | Not needed for PGN review; depends on server state. | Could be omitted or made configurable. | `index.php`, `lib/score.php` |

## Risks & mitigations (non-iframe static site)
- **Risk: absolute asset paths break when embedded.** Mitigation: parameterize asset base URLs or use relative paths in extracted CSS/JS. (`assets/css/style.css`, `index.php`.)
- **Risk: ECO dataset license unknown.** Mitigation: verify provenance (scripts or source), or swap to a licensed dataset. (`assets/data/eco_lite.json`, `scripts/validate_eco_lite.py`.)
- **Risk: storage blocked by CSP.** Mitigation: add `allowStorage` option; handle storage failures gracefully (as current code does). (`index.php`.)
- **Risk: no build system for packaging.** Mitigation: use a minimal bundling step in the extraction package or keep vanilla ES module export. (Repo has no bundler.)
- **Risk: keyboard/ARIA expectations on pmicka.com.** Mitigation: audit focus order and add minimal keyboard bindings for navigation (left/right/home/end already handled). (`index.php`.)
- **Risk: dataset/asset size.** Mitigation: lazy-load `eco_lite.json` and ship a slim CSS subset for the review kit. (`index.php`, `assets/data/eco_lite.json`, `assets/css/style.css`.)

