# Technical rundown: PGN review kit extraction (read-only)

> The extraction discussion below is historical. For the current selective
> integration baseline, verification results, and remaining decisions, see
> [Phase 0 baseline](#phase-0-selective-integration-baseline--2026-11-01).
> The current application uses Node.js for server-authoritative rules validation;
> it still has no frontend bundler. Current security/operations behavior is
> documented in the Phase 1–4 guides and README.

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

## Phase 0 selective integration baseline — 2026-11-01

### Scope and authorization

Phase 0 establishes a regression baseline for selectively bringing notebook
viewer improvements into the live application. Only tests and this document
change. No application, API, schema, configuration, dependency manifest, or
deployment changes are included. No branch, commit, PR, or production write was
made. Approval to begin Phase 0 does not approve subsequent UI phases or resolve
the owner decisions below.

The application remains PHP/SQLite with static frontend assets. Preserve its
gameplay controller; do not mount the notebook controller over the live board.
The extracted read-only package is a source of reusable rendering patterns, not
an alternative authority for moves, credentials, or live state.

### Source and deployment evidence

- Target checkout: `5c85915125b09314c1710019037e5e79516a9f38` (`main`).
- Reference snapshot from the audit:
  `pmicka/notebook@7b92d91056847650ed03db2c393414a43ada7def`.
- Production inspection used GET requests only at
  `https://patrickmicka.com/chess/` and its static asset paths. No authenticated
  host links, API actions, CAPTCHA submissions, resets, workers, or migrations
  were invoked on production.
- The main inline controller in the live public HTML matched the checkout
  byte-for-byte. Its SHA-256 was
  `dbb740d8f2708299454e62200de68d60aa619547e51103fa8a0dc08cd4156fad`.
- These live assets also matched the checkout byte-for-byte:

| Asset | SHA-256 |
| --- | --- |
| `assets/ui_helpers.js` | `3231b5aa66f1d9e50e711e38634b7c5a6a88b158903d128714d20aec5829e33a` |
| `assets/board_id_map.js` | `544b408c80fd86047a907e64bd1f702f0195b7fd123c4b39b5db284ad31ef5c2` |
| `assets/submission.js` | `f999a96e64f16f1a401122ca3036602987a043f079fda13c43fc81d3e33019ec` |
| `assets/chess.min.js` | `4233087c47d45a26cd0809341e4361a6756953b41616e2b289eed29b3020c3a9` |
| `assets/css/style.css` | `8547b6c2cbfddf116bc2741540c8bb229e52e0196aeae2969d48d9768be86793` |

This establishes the inspected frontend baseline, not the deployed backend
commit. Production PHP source, database/schema/history consistency, configured
Node runtime, worker scheduling, host authentication, and real mail delivery
were not independently verified. No production filesystem/database access was
available for the documented read-only deployment checks. Do not infer those
checks passed from matching frontend assets or passing local tests.

### Preserved behavior and automated coverage

`tests/browser_review.py` reuses `HostAuthorization`'s temporary application and
SQLite fixture. It generates legal test histories with the existing chess.js
asset, uses synthetic configuration, and blocks outside browser requests and
all browser non-GET requests. It never submits a move. Fixture SQL updates
simulate server changes; snapshots verify navigation does not mutate rows.
Temporary browser storage is permitted for testing existing view persistence.

At both 1280×900 and 390×844, with visitors assigned White and Black, it checks:

- 64 board squares, color-relative coordinate mapping, live move endpoints,
  square board geometry, and horizontal viewport fit.
- White legal selection/hints and a local move preview without persistence;
  Black out-of-turn selection rejection.
- Back/Home/Right/End/Forward navigation, start boundary, and existing automatic
  return to Live at the latest ply.
- Review submission disabling and JavaScript move-selection rejection even
  when a synthetic click bypasses the CSS pointer lock.
- Review mode/ply restoration on page reload.
- Actual polling callback handling of an advanced fixture revision: stable
  reviewed position, queued-update notice, and Live synchronization.
- Divergent PGN cannot replace canonical live FEN or rewrite stored history.
- No database mutation from browser review actions, mail, browser POSTs, or
  uncaught browser errors.

The current absence of Start/flip controls and historical highlights is not
asserted as a permanent requirement. Add feature-specific assertions when each
future phase is approved. Keyboard navigation must continue to use the existing
review transition path. New rendering must retain logical square identities,
canonical live FEN authority, player assignments, explicit promotion, revision
checks, CAPTCHA/token boundaries, and idempotent submission recovery.

The existing browser suites cover real local host/visitor submission, promotion,
checkmate, engine failure, and unknown-outcome recovery. The Phase 4 integration
suite inherits Phase 1–3 cases, so it covers authorization, state integrity,
chess legality, notification recovery, and transitions without running each
inherited suite again.

### Reproduction and results

Prerequisites: Git checkout, Node.js, PHP 8+ with PDO SQLite/ctype, Python,
Playwright, and Chromium. Use only the isolated fixtures; do not adapt these
commands to a production URL or database.

```sh
node --test tests/chess_rules.test.js
PHP_BIN=php PYTHONDONTWRITEBYTECODE=1 python3 tests/notification_recovery.py
PHP_BIN=php CHROMIUM_BIN=/usr/bin/chromium python3 tests/browser_chess.py
PHP_BIN=php CHROMIUM_BIN=/usr/bin/chromium python3 tests/browser_recovery.py
PHP_BIN=php CHROMIUM_BIN=/usr/bin/chromium python3 tests/browser_review.py
```

Verification environment: Node 24.19.0, Python 3.12.14, Chromium
154.0.8037.57, PHP 8.4.27 with PDO SQLite. PHP was absent initially; a Debian
container provisioned a session-local PHP runtime outside the checkout. No
project dependency or production runtime was installed/upgraded.

| Check | Result |
| --- | --- |
| Existing Node rules/protocol/perft suite | PASS — 54 tests |
| Existing Phase 1–4 HTTP/SQLite integration suite | PASS — 114 tests |
| Existing gameplay browser suite | PASS — 16 scenarios |
| Existing recovery browser suite | PASS — 25 scenarios |
| New review browser baseline | PASS — 4 scenarios, multiple assertions each |
| PHP syntax | PASS — all 25 PHP files |
| New Python test syntax | PASS |
| Production backend/runtime/database checks | NOT RUN |
| Physical iOS/Android, screen reader, interactive annotation overlay checks | NOT RUN |

These results establish an unchanged-application baseline. They do not validate
future flip, overlay, metadata, annotation, or wheel implementations.

### Next-phase gate, decisions, and rollback

Phase 1 may add a visible Start button by reusing Home's current transition only
after implementation approval. Subsequent priorities remain independent board
flip, historical move visualization, then compact live metadata.

Before affected phases, confirm public-only versus host-page scope; flip
persistence and pending-promotion policy; metadata hierarchy/score placement;
selected-position versus live FEN copy labels; and whether automatic Forward-to-
Live should change. Annotation sourcing, active-game assistance, generalized
transpositions, wheel activation, and engine analysis remain separate decisions.
No ratings, clocks, evaluations, best moves, or accuracy may be fabricated.

Rollback of Phase 0 consists only of removing `tests/browser_review.py` and this
document update. No database restoration, application rollback, migration,
credential change, or deployment is required.

## Phase 1 visible Start navigation — 2026-11-02

Phase 1 adds a native Start button before Back/Forward/Live on the public page.
It invokes the same `applyHistoryPosition(0, { forceRender: true, mode: 'review' })`
transition as Home. It begins disabled until state loads and is disabled at ply
zero, for empty history, and when history reconstruction is unavailable.

The mobile transport grid now has four columns. Its buttons have a minimum
44px height and compact padding; automated checks verify at least 44px width
and height, no clipped button content, and viewport fit at 390px and 320px.
Desktop retains its existing wrapping transport layout.

Only `index.php` and `assets/css/style.css` change application behavior. No host
page, API, schema, submission, polling, or authorization implementation changes.
No dependencies, build steps, commits, branches, PRs, or deployment are included.

`tests/browser_review.py` extends the Phase 0 baseline to both visitor colors at
1280×900, 390×844, and 320×740. It checks pointer and keyboard activation of Start,
Home equivalence, disabled boundaries, empty/unavailable history, preview
cancellation, review submission blocking, and Start with a queued server update
followed by Live synchronization. Database snapshots and blocked browser POSTs
verify navigation does not persist moves or alter server state.

Validation uses the same isolated PHP/SQLite fixtures and session-local runtime
as Phase 0. See the reproduction commands above; tests never target production.
Phase 2 board flip and later enhancements remain outside this phase.

Phase 1 verification: PASS — 6 review scenarios, 16 gameplay browser scenarios,
25 recovery browser scenarios, PHP syntax for `index.php`, Python syntax for the
review test, and `git diff --check`. The unchanged backend/rules suites passed
in Phase 0 and were not rerun for this UI-only change. Physical-device and screen
reader checks were not performed.

Rollback only the Phase 1 button, handler, disabled-state wiring, and mobile
transport CSS together (or restore their prior deployed versions). No database
rollback or credential changes are needed. Production deployment remains a
separate action; refresh existing tabs when it occurs.

## Phase 2 public board flip — 2026-11-02

The public page now has a native `Flip board` button with `aria-pressed` state.
It reverses display orientation relative to the API-assigned visitor side.
Player color variables, logical square identities, legality, submission payloads,
and canonical state remain independent of this display preference.

The preference is intentionally page-local: it survives same-game polling,
history navigation, Live synchronization, and move acceptance in the current
page, but resets on a full reload or when a different game ID is applied.
No orientation value is written to browser storage. Host-page parity and a
persistent orientation preference remain separate scope decisions.

Flip rerenders the current chess instance without loading a new FEN or clearing
selection. Selected pieces, legal hints, pending previews, promotion choice/color,
review position, and submission enabled/disabled state are preserved. The
promotion chooser remains available while flipped. Flip starts disabled until
state loads and is disabled while a move request is in flight. Unknown-outcome
submission recovery retains its existing identifier and payload after flipping.

The control sits outside the four-button transport group so mobile controls do
not shrink into a five-column row. It has a minimum 44px touch target and uses
existing focus styles; keyboard activation uses the native button behavior.
No document-level shortcut was added. Desktop and 320px local screenshots were
visually inspected; automated layout checks also cover 390px.

Application changes are confined to `index.php` and `assets/css/style.css`.
No API, host page, database, rules engine, credentials, dependencies, build
system, polling protocol, or deployment changes are included.

### Phase 2 regression coverage

- `tests/browser_review.py`: both visitor colors at 1280×900, 390×844, and
  320×740. Verifies exact reversal of opaque square IDs, unchanged assignments,
  selected-piece/hint and preview preservation, keyboard activation/focus,
  review navigation, queued updates, same-game orientation retention, reload
  reset, and a fixture-created successor restoring the new assigned side.
  Read-only browser navigation must still leave database snapshots unchanged.
- `tests/browser_flip.py`: both visitor colors at 1280×900 and 390×844, with an
  ordinary move, each of queen/rook/bishop/knight promotion, and interrupted
  promotion (24 scenarios). Flips before selection, during selection and preview,
  and while the chooser is open. Compares accepted FEN/SAN and logical payloads
  with existing chess.js behavior; verifies unchanged colors, one revision,
  receipt and stubbed email, CAPTCHA gating, and Flip disabled during an
  intercepted in-flight request. These submissions use only temporary fixtures.
- `tests/browser_recovery.py`: visitor cases now flip the pending preview and
  flip again after an unknown outcome. Existing retry/reload checks still require
  one submission identifier, move receipt, accepted revision, and notification.
  Host cases remain unchanged.

Run with existing browser-test prerequisites and a disposable local fixture:

```sh
PHP_BIN=php CHROMIUM_BIN=/usr/bin/chromium python3 tests/browser_flip.py
PHP_BIN=php CHROMIUM_BIN=/usr/bin/chromium python3 tests/browser_review.py
PHP_BIN=php CHROMIUM_BIN=/usr/bin/chromium python3 tests/browser_chess.py
PHP_BIN=php CHROMIUM_BIN=/usr/bin/chromium python3 tests/browser_recovery.py
```

Phase 2 verification: PASS — 24 flip scenarios, 6 review scenarios, 16 gameplay
scenarios, 25 recovery scenarios, PHP/Python syntax checks, and `git diff --check`.
Backend and rule implementations are unchanged; their Phase 0 baseline results
remain recorded above. No production, physical-device, or screen-reader tests
were performed.

Rollback the Phase 2 flip markup, orientation flag/rendering, new-game reset,
submission disabled-state wiring, handler, and flip-specific CSS together. Keep
Phase 1 Start navigation. No database or credential rollback is required. No
branch, commit, PR, or production action is part of this phase; historical move
visualization remains Phase 3.

## Phase 3 historical move visualization — 2026-11-07

History reconstruction now retains verbose move records alongside the existing
FEN timeline and SAN list. The selected ply supplies its `from`/`to` endpoints
to the existing gold last-move styling, including after restored review state,
flip, resize, and server refresh. Start has no previous move and no endpoints.
Castling highlights the king's endpoints; en passant highlights the moving
pawn's endpoints; promotions highlight the pawn's source and destination.
No extra rook/captured-pawn square is implied by the endpoint convention.

In review, the snapshot's last-move field uses the selected SAN without the live
move's timestamp. Its status begins `Review position`, followed by the side to
move and check/checkmate/stalemate when applicable. Live retains canonical
last-move/timestamp data and server status. These are presentation changes only:
the snapshot does not change game results, turn permissions, or terminal state.
Opening lookup retains its existing replay-based context.

A checked king in review receives a square-attached red radial glow, paired with
the text status. It uses the existing chess.js check predicate and logical square
lookup, so it follows flips and resizing without SVG coordinate calculations or
resize observers. The glow clears on Start, an unchecked position, and Live.
The live board's selection/hint/preview behavior is unchanged.

Changes reuse the target's current visual style. No notebook theme, graphical
arrows, annotation parser/toggle, best moves, evaluations, accuracy, external
analysis engine, opening dataset, or FEN-copy contract is introduced. The host
page, APIs, schema, submission/recovery, polling protocol, canonical-FEN gate,
and deployment model remain unchanged.

`tests/browser_history.py` checks every ply of six standard-start games covering
castling, captures, en passant, promotions, check, mate, and stalemate. Both visitor colors
at desktop/mobile widths are covered. Each reviewed ply is flipped and checked
again for exact endpoint IDs, selected SAN, correct checked king and text, and
disabled submission. Final-ply review is restored explicitly to cover mate;
returning Live must restore canonical metadata and clear review decoration.
Browser writes are blocked, and database/mail snapshots must remain unchanged.
Use `--case <name>` to run one fixture. An additional isolated resize check at
1280px, 320px, and 768px verified endpoints and the checked king across flips;
the resulting local board screenshot was visually inspected.

```sh
PHP_BIN=php CHROMIUM_BIN=/usr/bin/chromium python3 tests/browser_history.py
```

Use the existing review, flip, gameplay, and recovery commands above for the
remaining browser regressions. All fixtures are disposable; no production moves,
resets, database checks, or deployment are part of Phase 3.

Phase 3 verification: PASS — 20 historical scenarios plus 4 targeted stalemate
scenarios, 6 review scenarios, 24 flip scenarios, 16 gameplay scenarios, 25
recovery scenarios, the explicit resize/flip check, PHP/Python syntax, and
`git diff --check`. Backend/rules code is unchanged and those suites were not
rerun. Physical-device and screen-reader testing remain unperformed.

Rollback the verbose-record wiring, selected-history highlight lookup,
review-only metadata changes, and check-glow logic/style together; preserve
Phase 1 Start and Phase 2 Flip. No database rollback is required. Phase 4 compact
metadata layout remains separate.

## Phase 4 compact current-game metadata — 2026-11-07

The public scoreboard becomes a compact current-game card below the board,
following the notebook's useful board/card hierarchy while retaining this app's
colors, typography, pieces, buttons, and visual identity. It displays actual API
fields only: game ID, Host/World colors, side to move for an active game (or its
stored status), latest SAN, and last update time. All-time Host/World/Draw totals
and the existing last-result message remain present. Mobile totals use one
three-column row instead of moving Draws into a second full-width row.

Current-game facts are always visible. Existing opening, selected ply, notation,
and historical-position details remain in Details. The card's labels explicitly
refer to `Current game`, `Latest move`, `Last updated`, and `All-time score`;
review metadata continues to describe the selected position. Original header
assignments remain tied to the applied board state.

`renderLiveMetadata()` consumes the fetched state without altering it. Normal
state application refreshes the card; both existing queued-update branches also
refresh it while leaving the reviewed board/pending selection under the original
controller. Thus the card can show the latest move while Details shows an older
selected move. Existing stale-selection blocking and the update banner remain
unchanged. Flip and local move previews do not manufacture new live metadata.

SQLite's `YYYY-MM-DD HH:MM:SS` timestamps are normalized as UTC before browser
localization. The card uses a semantic `time` with a UTC `datetime` value, and
the existing human timestamp formatter uses the same normalization so status,
Details, and the card agree across timezones. Missing/invalid timestamps and
missing SAN show a dash. There are no fabricated player ratings, timers, event
tags, evaluations, or analysis claims.

Application changes remain confined to `index.php` and `assets/css/style.css`.
Card-specific CSS preserves the host page's existing scoreboard layout. No host
controller, API, schema, credentials, move authorization, submission/recovery,
polling schedule, dependency, frontend build, or deployment change is included.

### Phase 4 verification

The review baseline now checks both visitor colors at desktop, 390px, and 320px
in the `Pacific/Honolulu` timezone. It checks card identity, assignments, live
turn, actual score totals, UTC machine timestamp and localized display, card
placement and viewport fit, historical-versus-current SAN, metadata updates while
the board stays on a queued historical position, and successor identity/colors.
Existing database/mail snapshots and blocked browser POSTs continue to enforce
read-only navigation. Screenshots at all three widths were inspected, including
the compact score row. Gameplay/recovery suites use only disposable fixtures.

Reproduce with the existing browser commands:

```sh
PHP_BIN=php CHROMIUM_BIN=/usr/bin/chromium python3 tests/browser_review.py
PHP_BIN=php CHROMIUM_BIN=/usr/bin/chromium python3 tests/browser_chess.py
PHP_BIN=php CHROMIUM_BIN=/usr/bin/chromium python3 tests/browser_recovery.py
```

Rollback the Phase 4 card markup, presenter calls, timestamp normalization, and
card-specific CSS together, preserving Phases 1–3. No database or credential
rollback is required. No branch, commit, PR, or production deployment is part
of this phase. Annotated PGN, generalized transpositions, wheel navigation,
engine analysis, and a full notebook visual-theme port remain separate scope.

Phase 4 results: PASS — 6 expanded review/metadata scenarios, 16 gameplay
scenarios, 25 recovery scenarios, PHP/Python syntax, and `git diff --check`.
Desktop and 390px/320px screenshot checks also passed. Unchanged backend/rules
suites were not rerun; production, physical-device, and screen-reader checks
were not performed.

## Phase 5 isolated annotated PGN review — 2026-11-07

`review.php` is an independent browser-only page linked from the public page's
Advanced details. Pasting a single PGN creates a separate static review board.
There is no database bootstrap, live-state controller, CAPTCHA, submission helper,
polling, URL import, upload, or browser-storage access on this page. The default
intake is local paste; curated owner games, file upload, and live-game annotation
attachments are not included. Initial implementation and verification were
local; repository integration does not perform production deployment.

The page reuses `packages/pgn-review/src/dom.js` for its board,
`src/state.js` for navigation, the existing chess.js/opaque ID assets, and scoped
package CSS. Its small controller receives no gameplay state or credentials.
Start/Back/Forward/Final, native-button Flip, scoped keyboard navigation, original
PGN copying and selected-FEN copying are independent of the live app. No framework,
bundler, engine, or additional third-party dependency is introduced.

`assets/pgn_annotations.js` follows the notebook's supplied-comment conventions
without importing its DOM-coupled initializer. It replays a validated main line,
retaining before/after FEN and annotations per ply. It accepts SAN suffix glyphs,
common quality NAGs, explicit Lichess-style quality sentences, numeric evaluation
changes, `[%eval ...]` including mate notation, and `SAN was best` comments.
Narrative mentions alone do not establish a classification. Suggestions must be
legal in the pre-move position to receive an arrow. All labels identify their
supplied origin; no best move, evaluation, accuracy, or quality is calculated.
Plain PGN receives no fabricated annotation.

Side variations are omitted with an explicit main-line notice. Standard-chess
FEN setups, including Black to move, are supported; other variants are rejected.
Malformed comments/variations, illegal main-line moves, extra games, oversized
input (200,000 characters), excessive nesting (32), and histories over 1,000 ply
are rejected rather than repaired or truncated. Invalid imports leave the last
successful review unchanged. Original source PGN remains available unchanged.

Imported headers/comments are rendered as text, never HTML or fetched links.
Annotation text, square-attached badges and suggested arrows toggle together.
The SVG arrow uses a normalized 10×10 coordinate space, including gutters, and
therefore follows resizing and Flip without observers. The annotation panel
states that suggestions refer to the position before the selected move. Check
indicators derive only from the bundled rules library. Clear removes the input,
source, board, and imported metadata and returns focus to the input. Clipboard
failure provides a manual-copy message; there is no silent persistence.

### Verification and rollback

```sh
node --test tests/pgn_annotations.test.js
PHP_BIN=php CHROMIUM_BIN=/usr/bin/chromium python3 tests/browser_annotations.py
PHP_BIN=php CHROMIUM_BIN=/usr/bin/chromium python3 tests/browser_review.py
PHP_BIN=php CHROMIUM_BIN=/usr/bin/chromium python3 tests/browser_chess.py
```

Parser tests cover supplied attribution, absent annotations, move association,
nested/semicolon comments and variations, legal/illegal suggestions, FEN setup,
malformed and bounded input, and HTML-like text. Browser checks at desktop,
390px and 320px cover toggling, flip/resize geometry, keyboard navigation,
literal text rendering, malformed-import preservation, replacement/clear,
touch targets and viewport fit. They abort and fail on any game API, external,
or write request; imported PGN must leave database/mail and browser storage
unchanged. The fixture explicitly copies the new entrypoint/CSS until committed.

Rollback the new page, three new assets, link in `index.php`, and Phase 5 tests
together. No live-game data, schema, token, stored history, or dependency rollback
is needed. General variation navigation, arbitrary annotation standards such as
`[%cal]`/`[%csl]`, live-game annotation injection, accuracy scoring, generated
analysis, generalized transpositions, and mouse-wheel navigation remain outside
this implementation.

Phase 5 results: PASS — 23 parser tests, 3 annotated-review browser scenarios,
6 live review/metadata regressions, 16 gameplay scenarios, PHP/JavaScript/Python
syntax, and `git diff --check`. Desktop and 320px annotation previews were
visually inspected. All browser imports ran in disposable fixtures, with API,
external, and write requests forbidden on the independent review page. Clipboard
permissions, physical devices, and screen readers were not independently tested.

