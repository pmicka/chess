<?php
// index.php — Public page (visitors)
/**
 * Me vs the World Chess — Public Game Page
 *
 * Intent:
 * - This is the public-facing page where visitors collectively play against the host.
 * - Visitors may submit a move ONLY when it is the visitors' turn.
 * - Visitor move submission is gated by CAPTCHA to reduce spam/bot abuse.
 *
 * High-level flow:
 * 1) Load current game state from /api/state.php (FEN/PGN/turn/status).
 * 2) Render a board UI and allow interaction only for the visitor side.
 * 3) Use chess.js client-side to prevent obviously illegal moves.
 * 4) On submit:
 *    - Include CAPTCHA token, coordinates, promotion, game ID, revision, and retry ID
 *    - POST to /api/visitor_move.php
 *
 * Security model (MVP):
 * - Client-side legality checks are helpful but not authoritative.
 * - Server MUST enforce turn order and single-move acceptance (locking).
 * - Server verifies CAPTCHA and rejects out-of-turn or duplicate submissions.
 *
 * Notes:
 * - The host plays asynchronously via emailed, single-use links (see my_move.php).
 * - Host color flips each completed game (implemented server-side).
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/score.php';

$preloadedGame = null;
try {
    require_once __DIR__ . '/db.php';
    $db = get_db();
    $stmt = $db->query("
        SELECT id, host_color AS you_color, visitor_color, turn_color, status, fen, pgn, last_move_san, updated_at
        FROM games
        ORDER BY id DESC
        LIMIT 1
    ");
    $preloadedGame = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    error_log('index.php preload_state_failed: ' . $e->getMessage());
}

try { $scoreTotals = score_load(); } catch (Throwable $e) { $scoreTotals = score_defaults(); }
$baseUrl = defined('BASE_URL') ? trim((string)BASE_URL) : '';
$canonicalUrl = 'https://patrickmicka.com/chess/';
$googleVerification = defined('GOOGLE_SITE_VERIFICATION') ? trim((string)GOOGLE_SITE_VERIFICATION) : '';
$pageTitle = 'The Internet Gambit | patrickmicka.com';
$pageDescription = 'Me vs. the world, one board at a time.';
$socialImageUrl = 'https://patrickmicka.com/chess/assets/og-chess-v1.png';

$chessAppConfig = [
    'score' => $scoreTotals,
];

if (!empty($preloadedGame['visitor_color'])) {
    $chessAppConfig['orient'] = $preloadedGame['visitor_color'];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
  <meta name="description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8') ?>" />
  <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') ?>" />
  <?php if ($googleVerification !== ''): ?>
    <meta name="google-site-verification" content="<?= htmlspecialchars($googleVerification, ENT_QUOTES, 'UTF-8') ?>" />
  <?php endif; ?>
  <meta property="og:type" content="website" />
  <meta property="og:title" content="The Internet Gambit" />
  <meta property="og:description" content="Me vs. the world, one board at a time." />
  <meta property="og:url" content="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') ?>" />
  <meta property="og:image" content="<?= htmlspecialchars($socialImageUrl, ENT_QUOTES, 'UTF-8') ?>" />
  <meta property="og:image:secure_url" content="<?= htmlspecialchars($socialImageUrl, ENT_QUOTES, 'UTF-8') ?>" />
  <meta property="og:image:width" content="1200" />
  <meta property="og:image:height" content="630" />
  <meta property="og:image:alt" content="The Internet Gambit — Me vs. the world, one board at a time." />
  <meta name="twitter:card" content="summary_large_image" />
  <meta name="twitter:title" content="The Internet Gambit" />
  <meta name="twitter:description" content="Me vs. the world, one board at a time." />
  <meta name="twitter:image" content="<?= htmlspecialchars($socialImageUrl, ENT_QUOTES, 'UTF-8') ?>" />
  <meta name="theme-color" content="#0b0b0b" />
  <link rel="manifest" href="/chess/manifest.webmanifest">
  <script type="application/ld+json">
    <?= json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'SoftwareApplication',
        'name' => 'Me vs the World — Asynchronous Chess Experiment',
        'description' => $pageDescription,
        'url' => $canonicalUrl,
        'applicationCategory' => 'Game',
        'operatingSystem' => 'Web',
        'author' => [
            '@type' => 'Person',
            'name' => 'Patrick Micka',
        ],
        'license' => $canonicalUrl . 'LICENSE',
        'codeRepository' => 'https://github.com/pmicka/chess',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n" ?>
  </script>
  <script type="application/json" id="appConfig"><?= json_encode($chessAppConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
  <link rel="stylesheet" href="assets/css/style.css?v=<?= substr(hash_file('sha256', __DIR__ . '/assets/css/style.css'), 0, 12) ?>">
  <link rel="apple-touch-icon" sizes="180x180" href="assets/icons/apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/icons/favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/icons/favicon-16x16.png">
  <script id="turnstileApi" src="https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&amp;onload=onTurnstileReady" defer></script>
</head>
<body class="public-game-page">
  <a class="game-skip-link" href="#board">Skip to chessboard</a>
  <main class="wrap public-game">
    <h1>The Internet Gambit</h1>
    <p class="subhead meta-line">
      <span>Visitors play <strong id="visitorColorLabel">black</strong></span>
      <span>Host plays <strong id="hostColorLabel">white</strong></span>
      <a class="about-link" href="#about">About</a>
    </p>

    <div class="card stage-card">
      <div class="board-stack">
        <div class="board-container">
          <div class="board-shell">
            <div id="board" aria-label="Chess board" role="group" aria-describedby="boardHelp" tabindex="-1"></div>
            <div id="promotionChooser" class="promotion-chooser" role="group" aria-live="polite" aria-label="Choose promotion piece">
              <div class="promotion-band" role="group" aria-label="Promotion options">
                <div class="promotion-buttons">
                  <button type="button" class="promo-btn active" data-piece="q" aria-label="Promote to Queen">
                    <img class="promotion-piece" alt="" src="assets/pieces/lichess/wQ.svg">
                  </button>
                  <button type="button" class="promo-btn" data-piece="r" aria-label="Promote to Rook">
                    <img class="promotion-piece" alt="" src="assets/pieces/lichess/wR.svg">
                  </button>
                  <button type="button" class="promo-btn" data-piece="b" aria-label="Promote to Bishop">
                    <img class="promotion-piece" alt="" src="assets/pieces/lichess/wB.svg">
                  </button>
                  <button type="button" class="promo-btn" data-piece="n" aria-label="Promote to Knight">
                    <img class="promotion-piece" alt="" src="assets/pieces/lichess/wN.svg">
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
        <p id="boardHelp" class="visually-hidden">Use arrow keys to explore the board, Enter or Space to select, and Escape to cancel. Home and End move to the edges of the current row.</p>
        <div class="control-row review-row">
          <div class="history-controls">
            <div class="history-buttons">
              <button id="btnStart" type="button" disabled>Start</button>
              <button id="btnBack" type="button">Back</button>
              <button id="btnForward" type="button">Forward</button>
              <button id="btnLive" type="button">Live</button>
            </div>
            <button id="btnFlip" type="button" aria-pressed="false" disabled>Flip board</button>
            <span id="historyStatus" class="history-chip" aria-live="polite"></span>
          </div>
        </div>
        <div id="historyNotice" class="banner" role="status" aria-live="polite">
          <span id="snapshotStatus">Review position</span>
          <span class="review-last-move">Last move: <span id="snapshotLastMove">—</span></span>
        </div>
      </div>
      <div class="control-bay">
        <section class="live-game-summary" aria-labelledby="liveGameTitle">
          <div class="live-game-heading">
            <h2 id="liveGameTitle">Game <span id="liveGameId"></span></h2>
            <span id="liveGameStatus" class="muted">Loading…</span>
          </div>
          <dl class="live-game-facts">
            <div><dt>Latest move</dt><dd id="liveLastMove">—</dd></div>
            <div><dt>Last updated</dt><dd><time id="liveUpdatedAt">—</time></dd></div>
          </dl>
        </section>
        <div id="gameOverBanner" class="gameover-banner" role="alert" aria-live="polite">
          <div>
            <h3 id="gameOverTitle"></h3>
            <p id="gameOverBody" class="muted"></p>
          </div>
          <div class="gameover-actions">
            <button id="gameOverRefresh" type="button">Refresh</button>
          </div>
        </div>
        <div class="control-row action-row">
          <div class="status-stack" hidden>
            <div class="status-line" aria-live="polite">
              <span id="statusSpinner" class="spinner" aria-hidden="true"></span>
              <span id="statusMsg" class="muted"></span>
            </div>
          </div>
          <div class="move-preview-row" hidden>
            <p class="muted selected-move" hidden>
              Not submitted: <code id="movePreview">none</code>
            </p>
            <button id="btnCancel" class="secondary button-compact" type="button" aria-label="Cancel selection" hidden>Cancel</button>
          </div>
          <p id="submissionHelp" class="submission-help" role="status" aria-live="polite"></p>
          <div class="action-stack">
            <div class="primary-buttons">
              <button id="btnSubmit" aria-describedby="submissionHelp" disabled>Submit move</button>
              <button id="btnRefresh">Refresh</button>
            </div>
            <div class="turnstile-wrap" hidden>
              <div
                id="turnstileWidget"
                data-sitekey="<?= htmlspecialchars(TURNSTILE_SITE_KEY ?? '', ENT_QUOTES) ?>"
              ></div>
            </div>
          </div>
        </div>
        <p id="connectionNotice" class="connection-notice" role="status" aria-live="polite" hidden></p>
        <div id="updateBanner" class="banner" role="status" aria-live="polite">
          <span>New server state available. Refresh to sync.</span>
          <button id="btnBannerRefresh">Refresh</button>
        </div>
        <section class="scoreboard live-game-card" id="scoreboard" aria-labelledby="allTimeTitle">
          <h2 class="live-score-heading muted" id="allTimeTitle">All-time score</h2>
          <div class="score-grid" role="group" aria-label="All-time score">
            <div class="score-item">
              <span class="score-label">Host</span>
              <span class="score-value" id="scoreHostValue">0</span>
            </div>
            <div class="score-item">
              <span class="score-label">World</span>
              <span class="score-value" id="scoreWorldValue">0</span>
            </div>
            <div class="score-item">
              <span class="score-label">Draws</span>
              <span class="score-value" id="scoreDrawValue">0</span>
            </div>
          </div>
          <p class="score-last muted" id="scoreLastResult" aria-live="polite"></p>
        </section>
        <section class="position-info" aria-label="Displayed position">
          <div id="gameSnapshot" class="position-facts">
            <span><span class="muted">Opening: </span><span id="snapshotOpening">Unknown</span></span>
            <span id="snapshotPly" class="muted">Move 1</span>
          </div>
          <div class="notation-controls">
            <button id="toggleNotation" class="secondary ghost-button button-compact" type="button" aria-expanded="false" aria-controls="notationMount">Show FEN / PGN</button>
            <a href="review.php">Import PGN</a>
          </div>
          <div id="notationMount" class="notation-mount"></div>
        </section>
      </div>
    </div>

    <section id="about" class="about-section" aria-labelledby="about-heading">
      <h2 id="about-heading">About this project</h2>
      <p class="about-lede">This is a small experiment in building things by doing them.</p>
      <p>At its core, it’s an asynchronous chess simul: one board, one game, me versus anyone who wanders in. Moves unfold slowly over time—no matchmaking, no ratings, no timers—just a shared game and a bit of patience. Sides alternate from game to game, so sometimes you’ll play White and sometimes Black.</p>
      <p>Under the hood, it’s also a learning playground. This project exists to explore how modern tools fit together in the real world: PHP and SQLite on the backend, open-source chess libraries for rules and validation, GitHub for version control, and a growing amount of help from ChatGPT along the way. Wherever possible, it leans on permissive, open licenses—Creative Commons and MIT—because learning works best when ideas can be shared, borrowed, and improved.</p>
      <p>It’s intentionally simple, occasionally imperfect, and very much a work in progress. Things may break. Features may change. That’s part of the point.</p>
      <p>In the end, though, it’s still just a chessboard on the internet.</p>
      <p class="about-stanza"><em>One player on one side.<br>The rest of the world on the other.</em></p>
    </section>
    <footer class="app-footer">
      <p class="footer-note">Code: <a href="LICENSE">MIT</a> · Pieces: <a href="assets/pieces/lichess/LICENSE.txt">Lichess CC0</a> · <a href="https://github.com/pmicka/chess/" target="_blank" rel="noopener noreferrer">source</a></p>
    </footer>
  </main>

  <button type="button" id="backToTopButton" class="back-to-top" aria-label="Back to top">
    <svg
      viewBox="0 0 24 24"
      width="16"
      height="16"
      aria-hidden="true"
      focusable="false"
      fill="none"
      stroke="currentColor"
      stroke-width="2"
      stroke-linecap="round"
      stroke-linejoin="round"
    >
      <path d="M18 15.5 12 9.5 6 15.5" />
    </svg>
  </button>

  <script src="assets/ui_helpers.js"></script>
  <script src="assets/board_id_map.js"></script>
  <!-- PGN review module extracted to packages/pgn-review for reuse. -->
  <script src="assets/chess.min.js"></script>
  <script src="assets/submission.js"></script>
  <script>
    (() => {
      const submissions = window.ChessSubmission('api/visitor_move.php', 'chess-visitor-submission');
      // Test protocol (promotion refresh safety):
      // 1) Make a promotion-eligible move and leave the chooser open.
      // 2) Trigger a server refresh (wait for poll or click Refresh).
      // 3) Chooser closes, selection clears, and status reads “Game updated while choosing promotion; please retry.”

      const parseAppConfig = () => {
        const el = document.getElementById('appConfig');
        if (!el) return {};
        try {
          return JSON.parse(el.textContent || '{}');
        } catch (err) {
          return {};
        }
      };

      const appConfig = parseAppConfig();
      const DEBUG = Boolean(appConfig && appConfig.debug);
      const boardIdMap = (window.BoardIdMap && typeof window.BoardIdMap.createBoardIdMap === 'function')
        ? window.BoardIdMap.createBoardIdMap()
        : null;

      if (!boardIdMap) {
        console.error('Board ID map unavailable.');
        return;
      }

      const boardEl = document.getElementById('board');
      const btnRefresh = document.getElementById('btnRefresh');
      const btnSubmit = document.getElementById('btnSubmit');
      const statusMsg = document.getElementById('statusMsg');
      const statusSpinner = document.getElementById('statusSpinner');
      const submissionHelp = document.getElementById('submissionHelp');
      const btnCancel = document.getElementById('btnCancel');
      const connectionNotice = document.getElementById('connectionNotice');
      const movePreview = document.getElementById('movePreview');
      const selectedMoveRow = document.querySelector('.selected-move');
      const movePreviewRow = document.querySelector('.move-preview-row');
      const scoreboardEl = document.getElementById('scoreboard');
      const scoreHostValue = document.getElementById('scoreHostValue');
      const scoreWorldValue = document.getElementById('scoreWorldValue');
      const scoreDrawValue = document.getElementById('scoreDrawValue');
      const scoreLastResult = document.getElementById('scoreLastResult');
      const visitorColorLabel = document.getElementById('visitorColorLabel');
      const hostColorLabel = document.getElementById('hostColorLabel');
      const turnstileWidget = document.getElementById('turnstileWidget');
      const updateBanner = document.getElementById('updateBanner');
      const updateBannerText = updateBanner ? updateBanner.querySelector('span') : null;
      const btnBannerRefresh = document.getElementById('btnBannerRefresh');
      const promotionChooser = document.getElementById('promotionChooser');
      const promotionButtons = Array.from(document.querySelectorAll('.promo-btn'));
      const gameOverBanner = document.getElementById('gameOverBanner');
      const gameOverTitle = document.getElementById('gameOverTitle');
      const gameOverBody = document.getElementById('gameOverBody');
      const gameOverRefresh = document.getElementById('gameOverRefresh');
      const btnStart = document.getElementById('btnStart');
      const btnFlip = document.getElementById('btnFlip');
      const btnBack = document.getElementById('btnBack');
      const btnForward = document.getElementById('btnForward');
      const btnLive = document.getElementById('btnLive');
      const historyStatus = document.getElementById('historyStatus');
      const historyNotice = document.getElementById('historyNotice');
      const notationMount = document.getElementById('notationMount');
      const toggleNotationBtn = document.getElementById('toggleNotation');
      const snapshotBlock = document.getElementById('gameSnapshot');
      const snapshotOpeningEl = document.getElementById('snapshotOpening');
      const snapshotPlyEl = document.getElementById('snapshotPly');
      const snapshotLastMoveEl = document.getElementById('snapshotLastMove');
      const snapshotStatusEl = document.getElementById('snapshotStatus');
      const liveGameId = document.getElementById('liveGameId');
      const liveGameStatus = document.getElementById('liveGameStatus');
      const liveLastMove = document.getElementById('liveLastMove');
      const liveUpdatedAt = document.getElementById('liveUpdatedAt');
      const backToTopBtn = document.getElementById('backToTopButton');
      const prefersReducedMotion = (typeof window !== 'undefined' && window.matchMedia)
        ? window.matchMedia('(prefers-reduced-motion: reduce)')
        : null;
      const snapshotRenderCache = { opening: null, ply: null, lastMove: null, status: null };

      boardEl.classList.add('locked');

      const game = new Chess();
      const uiHelpers = window.ChessUI || {};
      const filesBase = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];
      const VIEW_STATE_KEYS = {
        mode: 'chess_viewMode',
        selected: 'chess_selectedPly',
        ts: 'chess_viewStateTs',
      };
      const VIEW_STATE_MAX_AGE_MS = 2 * 60 * 60 * 1000; // 2 hours
      let state = null;
      let visitorColor = appConfig.orient || 'black';
      // Display preference only: player ownership and move coordinates never depend on it.
      let boardFlipped = false;
      let hostColor = visitorColor === 'white' ? 'black' : 'white';
      let selectedSquare = null;
      let pendingMove = null; // {from,to,promotion?,san,requiresPromotion?}
      let pendingBaseFen = null;
      let lastMoveSquares = null; // {from,to}
      let selectionStateFingerprint = null;
      let latestFetchedStateFingerprint = null;
      let queuedServerState = null;
      let selectionIsStale = false;
      let pollHandle = null;
      let submitting = false;
      let promotionChoice = 'q';
      let promotionPending = false;
      let promotionColor = null;
      let gameOverState = { over: false, reason: null, winner: null };
      let notationVisible = false;
      let notationElements = null;
      let notationData = { fen: '', pgn: '' };
      let historyState = null;
      let viewState = { mode: 'live', selectedPly: 0, latestPly: 0 };
      let turnstileToken = null;
      let ecoLoadPrimed = false;
      let openingLookupToken = 0;
      let focusedSquare = null;
      let verificationMessage = '';
      let refreshingState = false;
      let stateUnavailable = false;
      let connectionFailed = false;
      let turnstileWidgetId = null;
      let renderingVerification = false;
      let verificationScriptFailed = false;
      let verificationRenderFailed = false;
      let stateRequestId = 0;
      function hydrateEcoEntries(rawEntries) {
        if (!Array.isArray(rawEntries)) return [];
        return rawEntries.map((entry, idx) => {
          const movesArray = typeof entry?.moves === 'string'
            ? entry.moves.split(/\s+/).filter(Boolean)
            : (Array.isArray(entry?.movesArray) ? entry.movesArray : []);
          return {
            eco: entry.eco,
            name: entry.name,
            moves: entry.moves,
            movesArray,
            movesLen: movesArray.length,
            order: idx,
          };
        });
      }

      const ecoLiteStore = {
        started: false,
        data: null,
        promise: null,
        loadFromStorage() {
          if (typeof localStorage === 'undefined') return null;
          try {
            const raw = localStorage.getItem('chess_eco_lite_cache_v1');
            if (!raw) return null;
            const parsed = JSON.parse(raw);
            if (!parsed || !Array.isArray(parsed.data)) return null;
            return hydrateEcoEntries(parsed.data);
          } catch (err) {
            return null;
          }
        },
        persist(data) {
          if (typeof localStorage === 'undefined') return;
          try {
            localStorage.setItem('chess_eco_lite_cache_v1', JSON.stringify({ data }));
          } catch (err) {
            // ignore storage failures
          }
        },
        load() {
          if (this.promise) return this.promise;
          const cached = this.loadFromStorage();
          if (cached && Array.isArray(cached)) {
            this.data = cached;
            this.promise = Promise.resolve(this.data);
            this.started = true;
            return this.promise;
          }
          this.started = true;
          this.promise = fetch('assets/data/eco_lite.json', { cache: 'force-cache' })
            .then((res) => {
              if (!res.ok) throw new Error('Failed to load ECO data');
              return res.json();
            })
            .then((json) => {
              const hydrated = hydrateEcoEntries(json);
              this.data = hydrated;
              this.persist(json);
              return hydrated;
            })
            .catch((err) => {
              console.error('ECO lite load failed', err);
              this.data = null;
              this.promise = null;
              this.started = false;
              throw err;
            });
          return this.promise;
        },
        async getData() {
          if (!this.data && ecoLoadPrimed) {
            try {
              await this.load();
            } catch (err) {
              return null;
            }
          }
          return this.data;
        },
      };

      const stateStore = {
        current: null,
        set(next) {
          this.current = next ? { ...next } : null;
          state = this.current;
          return this.current;
        },
        get() { return this.current; },
      };

      function updateMovePreviewDisplay(text) {
        if (movePreview) {
          movePreview.textContent = text || '';
        }
        if (selectedMoveRow) {
          const hasMove = Boolean(text);
          selectedMoveRow.hidden = !hasMove;
          selectedMoveRow.setAttribute('aria-hidden', hasMove ? 'false' : 'true');
        }
      }

      function persistViewState() {
        if (typeof sessionStorage === 'undefined') return;
        try {
          sessionStorage.setItem(VIEW_STATE_KEYS.mode, viewState.mode);
          sessionStorage.setItem(VIEW_STATE_KEYS.selected, String(viewState.selectedPly));
          sessionStorage.setItem(VIEW_STATE_KEYS.ts, String(Date.now()));
        } catch (err) {
          // ignore storage failures
        }
      }

      function restoreViewState() {
        if (typeof sessionStorage === 'undefined') return;
        try {
          const tsRaw = sessionStorage.getItem(VIEW_STATE_KEYS.ts);
          const ts = Number(tsRaw);
          if (!Number.isFinite(ts) || (Date.now() - ts) > VIEW_STATE_MAX_AGE_MS) {
            return;
          }
          const mode = sessionStorage.getItem(VIEW_STATE_KEYS.mode);
          const selectedRaw = sessionStorage.getItem(VIEW_STATE_KEYS.selected);
          const selected = Number(selectedRaw);
          if (mode === 'live' || mode === 'review') {
            viewState.mode = mode;
          }
          if (Number.isFinite(selected) && selected >= 0) {
            viewState.selectedPly = selected;
          }
        } catch (err) {
          // ignore storage failures
        }
      }

      function clampSelectedPly(idx) {
        const h = historyState;
        const latest = Number.isFinite(viewState.latestPly) ? viewState.latestPly : (h ? h.liveIndex : 0);
        const maxIdx = h && Array.isArray(h.timeline) ? h.timeline.length - 1 : latest;
        const limit = Math.max(0, Number.isFinite(maxIdx) ? maxIdx : 0);
        if (!Number.isFinite(idx)) return limit;
        return Math.max(0, Math.min(idx, limit));
      }

      function getSelectedHistoryIndex() {
        return clampSelectedPly(viewState.selectedPly);
      }

      function isLiveMode() {
        return viewState.mode === 'live';
      }

      function isHistoryLiveView() {
        const h = historyState;
        if (!h) return isLiveMode();
        return isLiveMode() && getSelectedHistoryIndex() === h.liveIndex;
      }

      function isTypingContext(ev) {
        const target = ev && ev.target;
        if (!target) return false;
        const tag = (target.tagName || '').toLowerCase();
        const editable = target.isContentEditable;
        return editable || tag === 'input' || tag === 'textarea' || tag === 'select';
      }

      function updateViewState(partial = {}) {
        viewState = { ...viewState, ...partial };
        persistViewState();
      }

      restoreViewState();
      updateMovePreviewDisplay('');

      window.onTurnstileSuccess = (token) => {
        turnstileToken = token;
        if (!submitting) setStatus('');
        verificationMessage = '';
        syncSubmissionControls();
      };
      window.onTurnstileExpired = () => {
        turnstileToken = null;
        verificationMessage = 'Verification expired. Complete the CAPTCHA again.';
        if (!submitting) setStatus('');
        syncSubmissionControls();
      };
      window.onTurnstileError = () => {
        turnstileToken = null;
        verificationMessage = 'Verification failed. Retry the CAPTCHA; if it stays unavailable, reload the page.';
        if (!submitting) setStatus('');
        syncSubmissionControls();
      };
      window.onTurnstileTimeout = () => {
        turnstileToken = null;
        verificationMessage = 'Verification timed out. Complete the CAPTCHA again.';
        if (!submitting) setStatus('');
        syncSubmissionControls();
      };

      // Explicit rendering starts verification only for a candidate or unresolved
      // retry. This changes presentation timing, never the server's CAPTCHA gate.
      window.onTurnstileReady = () => { verificationScriptFailed = false; syncSubmissionControls(); };
      document.getElementById('turnstileApi').addEventListener('error', () => {
        verificationScriptFailed = true;
        syncSubmissionControls();
      });

      function syncVerificationWidget() {
        const needed = isHistoryLiveView() && (Boolean(submissions.pending) ||
          (pendingMove && isVisitorsTurn() && !gameOverState.over && !selectionIsStale &&
           !queuedServerState && !stateUnavailable && !connectionFailed && !refreshingState));
        turnstileWidget.parentElement.hidden = !needed;
        if (!needed) {
          if (turnstileWidgetId !== null && window.turnstile?.remove) {
            window.turnstile.remove(turnstileWidgetId);
          }
          turnstileWidgetId = null;
          verificationRenderFailed = false;
          turnstileToken = null;
          verificationMessage = '';
          return;
        }
        if (turnstileWidgetId !== null || renderingVerification || verificationRenderFailed) return;
        if (!window.turnstile?.render) {
          verificationMessage = verificationScriptFailed
            ? 'Verification could not load. Check your connection or content blocker, then reload the page.'
            : 'Loading CAPTCHA. If it does not appear, check your connection or content blocker and reload.';
          return;
        }
        renderingVerification = true;
        try {
          verificationMessage = '';
          turnstileWidgetId = window.turnstile.render(turnstileWidget, {
            sitekey: turnstileWidget.dataset.sitekey,
            size: 'flexible',
            theme: 'dark',
            callback: window.onTurnstileSuccess,
            'expired-callback': window.onTurnstileExpired,
            'error-callback': window.onTurnstileError,
            'timeout-callback': window.onTurnstileTimeout,
          });
          if (turnstileWidgetId == null) throw new Error('Widget unavailable');
        } catch (_) {
          turnstileWidgetId = null;
          verificationRenderFailed = true;
          verificationMessage = 'Verification could not start. Reload the page to retry.';
        } finally {
          renderingVerification = false;
        }
      }

      function syncSubmissionControls() {
        syncVerificationWidget();
        let reason;
        let ready = false;
        const live = isHistoryLiveView();
        if (submitting) reason = 'Submitting your move. Please wait.';
        else if (!live) reason = 'Reviewing a position. Return to Live before submitting.';
        else if (submissions.pending) {
          // Receipt recovery remains available without a fresh CAPTCHA. A new
          // application of the stored move still verifies CAPTCHA on the server.
          ready = true;
          reason = 'Previous outcome unconfirmed. Retry the same move safely or refresh to check.';
        } else if (refreshingState) reason = 'Refreshing the live game…';
        else if (!state || stateUnavailable) reason = 'Game unavailable. Use Refresh to try again.';
        else if (connectionFailed) reason = 'Connection lost. Refresh successfully before submitting.';
        else if (selectionIsStale || queuedServerState || (selectionStateFingerprint &&
                 selectionStateFingerprint !== latestFetchedStateFingerprint)) {
          reason = 'The live game changed. Refresh before selecting another move.';
        } else if (gameOverState.over || state.status !== 'active') reason = '';
        else if (!isVisitorsTurn()) reason = 'Moves happen asynchronously. The board refreshes automatically.';
        else if (!pendingMove) reason = selectedSquare ? 'Choose a highlighted destination.' : 'Choose a piece, then a destination.';
        else if (!turnstileToken) reason = verificationMessage || 'Complete the CAPTCHA to submit this move.';
        else {
          ready = true;
          reason = 'Verified. Submit move to confirm your selection.';
        }
        btnSubmit.disabled = !ready;
        const submitLabel = submitting ? 'Submitting…' : submissions.pending ? 'Retry same move' : 'Submit move';
        if (btnSubmit.textContent !== submitLabel) btnSubmit.textContent = submitLabel;
        if (submissionHelp.textContent !== reason) submissionHelp.textContent = reason;
        submissionHelp.hidden = !reason || Boolean(statusMsg.textContent);
        movePreviewRow.hidden = !(pendingMove || selectedSquare) || Boolean(submissions.pending);
        btnCancel.hidden = !(pendingMove || selectedSquare) || Boolean(submissions.pending);
        btnCancel.disabled = submitting;
        boardEl.setAttribute('aria-busy', String(submitting || refreshingState));
      }

      function cancelSelectedMove() {
        if (submitting || submissions.pending) return;
        clearSelection({ restore: true });
        lastMoveSquares = isHistoryLiveView() ? deriveLastMoveSquares(state) : getSelectedHistoryMove();
        updateHighlights();
        updateStatusMessage();
        syncSubmissionControls();
        findSquareEl(focusedSquare)?.focus({ preventScroll: true });
      }

      function squareId(logical) {
        return boardIdMap.toOpaque(logical);
      }

      function findSquareEl(logical) {
        const opaque = squareId(logical);
        if (!opaque) return null;
        return boardEl.querySelector(`.sq[data-square-id="${opaque}"]`);
      }

      function renderPiecePlaceholder(piece) {
        if (!piece) return null;
        const isWhite = piece.color === 'w';
        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', '0 0 100 100');
        svg.setAttribute('aria-hidden', 'true');
        svg.setAttribute('class', 'piece-svg');
        const circle = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
        circle.setAttribute('cx', '50');
        circle.setAttribute('cy', '50');
        circle.setAttribute('r', '36');
        circle.setAttribute('fill', isWhite ? '#f7f7f7' : '#2f2f2f');
        circle.setAttribute('stroke', '#5a5a5a');
        circle.setAttribute('stroke-width', '4');
        svg.appendChild(circle);
        return svg;
      }

      function renderPieceElement(piece) {
        if (!piece) return null;
        if (uiHelpers && typeof uiHelpers.renderPieceEl === 'function') {
          const el = uiHelpers.renderPieceEl(piece.color, piece.type);
          if (el) return el;
        }
        return renderPiecePlaceholder(piece);
      }

      function algebraic(file, rank) { return file + rank; }

      function getOrientationLayout(color) {
        if (uiHelpers && typeof uiHelpers.orientationLayout === 'function') {
          return uiHelpers.orientationLayout(color);
        }
        const isBlack = String(color).toLowerCase() === 'black';
        return {
          files: isBlack ? [...filesBase].reverse() : [...filesBase],
          ranks: isBlack ? [1, 2, 3, 4, 5, 6, 7, 8] : [8, 7, 6, 5, 4, 3, 2, 1],
        };
      }

      function renderBoard() {
        const restoreFocus = boardEl.contains(document.activeElement);
        boardEl.innerHTML = '';

        const displayOrientation = boardFlipped
          ? (visitorColor === 'white' ? 'black' : 'white')
          : visitorColor;
        const layout = getOrientationLayout(displayOrientation);
        const files = layout.files;
        const ranks = layout.ranks;
        if (!focusedSquare) focusedSquare = visitorColor === 'white' ? 'e2' : 'e7';

        for (let row = 0; row < 10; row++) {
          for (let col = 0; col < 10; col++) {
            const isCorner = (row === 0 || row === 9) && (col === 0 || col === 9);
            if (isCorner) {
              const corner = document.createElement('div');
              corner.className = 'label corner';
              boardEl.appendChild(corner);
              continue;
            }

            if (row === 0 || row === 9) {
              const fileLabel = document.createElement('div');
              fileLabel.className = 'label';
              const fileIdx = col - 1;
              fileLabel.textContent = files[fileIdx].toUpperCase();
              boardEl.appendChild(fileLabel);
              continue;
            }

            if (col === 0 || col === 9) {
              const rankLabel = document.createElement('div');
              rankLabel.className = 'label';
              const rankIdx = row - 1;
              rankLabel.textContent = ranks[rankIdx];
              boardEl.appendChild(rankLabel);
              continue;
            }

            const file = files[col - 1];
            const rank = ranks[row - 1];
            const sq = algebraic(file, rank);
            const fileIndex = filesBase.indexOf(file);
            const piece = game.get(sq);

            const div = document.createElement('div');
            div.className = 'sq ' + (((fileIndex + rank) % 2 === 0) ? 'light' : 'dark');
            div.dataset.squareId = squareId(sq);
            div.setAttribute('role', 'button');
            div.tabIndex = sq === focusedSquare ? 0 : -1;
            div.addEventListener('focus', () => {
              focusedSquare = sq;
              boardEl.querySelectorAll('.sq').forEach(el => { el.tabIndex = el === div ? 0 : -1; });
            });
            div.addEventListener('keydown', (ev) => {
              if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End', 'Enter', ' ', 'Escape'].includes(ev.key)) {
                ev.preventDefault();
                ev.stopPropagation();
              } else return;
              if (ev.key === 'Enter' || ev.key === ' ') {
                onSquareClick(div.dataset.squareId);
              } else if (ev.key === 'Escape') {
                cancelSelectedMove();
              } else {
                const squares = Array.from(boardEl.querySelectorAll('.sq'));
                const idx = squares.indexOf(div);
                const rowStart = Math.floor(idx / 8) * 8;
                let target = idx;
                if (ev.key === 'ArrowLeft') target = Math.max(rowStart, idx - 1);
                if (ev.key === 'ArrowRight') target = Math.min(rowStart + 7, idx + 1);
                if (ev.key === 'ArrowUp') target = Math.max(idx % 8, idx - 8);
                if (ev.key === 'ArrowDown') target = Math.min(56 + idx % 8, idx + 8);
                if (ev.key === 'Home') target = rowStart;
                if (ev.key === 'End') target = rowStart + 7;
                squares[target]?.focus();
              }
            });
            const pieceEl = renderPieceElement(piece);
            if (pieceEl) {
              pieceEl.setAttribute('aria-hidden', 'true');
              div.appendChild(pieceEl);
            }

            div.addEventListener('click', () => onSquareClick(div.dataset.squareId));
            boardEl.appendChild(div);
          }
        }

        updateHighlights();
        if (restoreFocus) findSquareEl(focusedSquare)?.focus({ preventScroll: true });
      }

      function resetPromotionChooser() {
        if (promotionChooser?.contains(document.activeElement)) {
          findSquareEl(focusedSquare)?.focus({ preventScroll: true });
        }
        promotionChoice = 'q';
        promotionPending = false;
        promotionColor = null;
        updatePromotionButtons();
        if (promotionChooser) {
          promotionChooser.classList.remove('show');
        }
      }

      function isPromotionFlowActive() {
        const chooserVisible = promotionChooser?.classList.contains('show');
        return chooserVisible || promotionPending || (pendingMove && pendingMove.requiresPromotion);
      }

      function cancelPromotionFlow({ restore = false } = {}) {
        if (!isPromotionFlowActive()) return false;
        clearSelection({ restore });
        resetPromotionChooser();
        btnSubmit.disabled = true;
        return true;
      }

      function handlePromotionInterrupted(newFingerprint, { queued = false } = {}) {
        const baseline = selectionStateFingerprint;
        if (!isPromotionFlowActive() || !baseline || !newFingerprint) return;
        if (baseline === newFingerprint) return;
        cancelPromotionFlow({ restore: true });
        setStatus('Game updated while choosing promotion; please retry.', 'muted');
        showUpdateBanner(queued ? 'Live game updated. Click Live to return.' : null);
      }

      function clearSelection({ restore = false } = {}) {
        const hadPending = Boolean(pendingBaseFen);
        if (restore && pendingBaseFen) {
          try {
            game.load(pendingBaseFen);
          } catch (err) {
            // ignore restore errors
          }
        }
        selectedSquare = null;
        pendingMove = null;
        pendingBaseFen = null;
        selectionStateFingerprint = null;
        selectionIsStale = false;
        updateMovePreviewDisplay('');
        btnSubmit.disabled = true;
        resetPromotionChooser();
        if (restore && hadPending) {
          renderBoard();
        } else {
          updateHighlights();
        }
      }

      function updateHighlights() {
        const squares = boardEl.querySelectorAll('.sq');
        squares.forEach(el => {
          el.classList.remove('pick');
          el.classList.remove('hint');
          el.classList.remove('hint-capture');
          el.classList.remove('last');
          el.classList.remove('review-check');
        });

        if (selectedSquare) {
          const pick = findSquareEl(selectedSquare);
          if (pick) pick.classList.add('pick');

          const moves = game.moves({ square: selectedSquare, verbose: true });
          moves.forEach(m => {
            const hint = findSquareEl(m.to);
            if (hint) {
              hint.classList.add('hint');
              if (m.flags.includes('c') || m.flags.includes('e')) hint.classList.add('hint-capture');
            }
          });
        }

        if (lastMoveSquares) {
          ['from', 'to'].forEach(key => {
            const target = findSquareEl(lastMoveSquares[key]);
            if (target) target.classList.add('last');
          });
        }
        if (!isLiveMode() && game.in_check()) {
          const checkedColor = game.turn();
          for (const file of filesBase) {
            for (let rank = 1; rank <= 8; rank++) {
              const sq = `${file}${rank}`;
              const piece = game.get(sq);
              if (piece?.type === 'k' && piece.color === checkedColor) {
                findSquareEl(sq)?.classList.add('review-check');
              }
            }
          }
        }
        const names = { p: 'pawn', n: 'knight', b: 'bishop', r: 'rook', q: 'queen', k: 'king' };
        const locked = !state || stateUnavailable || connectionFailed || refreshingState || submitting ||
          Boolean(submissions.pending) || !isHistoryLiveView() || !isVisitorsTurn() ||
          gameOverState.over || selectionIsStale || Boolean(queuedServerState) || promotionPending;
        squares.forEach(el => {
          const sq = boardIdMap.toLogical(el.dataset.squareId);
          const piece = game.get(sq);
          const description = piece ? `${piece.color === 'w' ? 'White' : 'Black'} ${names[piece.type]}` : 'empty';
          const flags = [el.classList.contains('pick') ? 'selected' : '',
            el.classList.contains('hint') ? 'legal destination' : '',
            el.classList.contains('hint-capture') ? 'capture' : '',
            el.classList.contains('last') ? 'last move' : ''].filter(Boolean);
          el.setAttribute('aria-label', [sq, description, ...flags].join(', '));
          el.setAttribute('aria-pressed', String(el.classList.contains('pick')));
          el.setAttribute('aria-disabled', String(locked));
        });
        syncSubmissionControls();
      }

      function promotionInfoForMove(from, to) {
        const moves = game.moves({ square: from, verbose: true });
        const matches = moves.filter((m) => m.to === to);
        const promotionMoves = matches.filter((m) => m.promotion);
        return {
          isPromotion: promotionMoves.length > 0,
          moves: promotionMoves,
        };
      }

      function updatePromotionButtons() {
        const colorPrefix = promotionColor === 'b' ? 'b' : 'w';
        promotionButtons.forEach((btn) => {
          btn.classList.toggle('active', btn.dataset.piece === promotionChoice);
          btn.setAttribute('aria-pressed', String(btn.dataset.piece === promotionChoice));
          const img = btn.querySelector('img');
          if (img) {
            const pieceCode = (btn.dataset.piece || 'q').toUpperCase();
            img.src = `assets/pieces/lichess/${colorPrefix}${pieceCode}.svg`;
          }
        });
        if (promotionChooser) {
          promotionChooser.setAttribute('aria-hidden', promotionPending ? 'false' : 'true');
        }
      }

      function selectPromotionChoice(piece) {
        const nextChoice = (piece || 'q').toLowerCase();
        promotionChoice = nextChoice;
        if (pendingMove && pendingMove.requiresPromotion) {
          promotionPending = true;
        }
        updatePromotionButtons();
        if (promotionChooser && pendingMove && pendingMove.requiresPromotion && pendingBaseFen) {
          try {
            game.load(pendingBaseFen);
            const move = game.move({
              from: pendingMove.from,
              to: pendingMove.to,
              promotion: promotionChoice,
            });
            if (move) {
              pendingMove = {
                from: move.from,
                to: move.to,
                promotion: move.promotion || promotionChoice,
                san: move.san,
                requiresPromotion: true,
              };
              lastMoveSquares = { from: move.from, to: move.to };
              updateMovePreviewDisplay(`${move.san} (${move.from}→${move.to})`);
              syncSubmissionControls();
              renderBoard();
            }
          } catch (err) {
            setStatus('Invalid promotion selection', 'error');
          }
        }
      }

      function isVisitorsTurn() {
        if (!state) return false;
        return state.turn_color === visitorColor && state.status === 'active';
      }

      function stateFingerprint(obj) {
        if (!obj) return null;
        return [
          obj.id ?? '',
          obj.revision ?? '',
          obj.status ?? '',
          obj.updated_at ?? '',
          obj.fen ?? '',
          obj.turn_color ?? '',
          (obj.pgn || '').length
        ].join('|');
      }

      function setUpdateBannerMessage(message) {
        if (updateBannerText && message) {
          updateBannerText.textContent = message;
        }
      }

      function showUpdateBanner(message = 'New server state available. Refresh to sync.') {
        setUpdateBannerMessage(message || 'New server state available. Refresh to sync.');
        updateBanner.classList.add('show');
      }

      function hideUpdateBanner() {
        updateBanner.classList.remove('show');
        queuedServerState = null;
      }

      function onSquareClick(opaqueSquareId) {
        if (submissions.pending || submitting || refreshingState || stateUnavailable || connectionFailed || promotionPending || gameOverState.over) return;
        if (!state) return;
        if (selectionIsStale || queuedServerState) { syncSubmissionControls(); return; }
        if (historyState && !isHistoryLiveView()) {
          setStatus('Reviewing history — click Live to return before submitting a move.', 'muted');
          return;
        }

        if (pendingMove && !selectedSquare) {
          clearSelection({ restore: true });
        }

        if (!isVisitorsTurn()) {
          return;
        }

        const sq = boardIdMap.toLogical(opaqueSquareId);
        if (!sq) return;

        const turn = game.turn();
        const visitorTurnChar = (visitorColor === 'white') ? 'w' : 'b';
        if (turn !== visitorTurnChar) {
          statusMsg.textContent = 'Local state mismatch. Hit Refresh.';
          return;
        }

        const piece = game.get(sq);

        if (!selectedSquare) {
          if (!piece) return;
          if (piece.color !== visitorTurnChar) return;
          selectedSquare = sq;
          updateHighlights();
          updateStatusMessage();
          return;
        }

        if (piece && piece.color === visitorTurnChar) {
          selectedSquare = sq;
          updateHighlights();
          return;
        }

        const promoInfo = promotionInfoForMove(selectedSquare, sq);
        const promoOptions = promoInfo.moves.map((m) => m.promotion).filter(Boolean);
        let promoToUse = promotionChoice;
        if (promoInfo.isPromotion) {
          promotionPending = true;
          promotionColor = visitorTurnChar;
          if (!promoOptions.includes(promoToUse)) {
            promoToUse = promoOptions[0] || 'q';
          }
        } else {
          resetPromotionChooser();
        }

        if (!pendingBaseFen) {
          pendingBaseFen = game.fen();
        }

        const move = game.move({
          from: selectedSquare,
          to: sq,
          ...(promoInfo.isPromotion ? { promotion: promoToUse } : {}),
        });
        if (!move) {
          if (pendingBaseFen) {
            try { game.load(pendingBaseFen); } catch (err) { /* ignore */ }
          }
          pendingBaseFen = null;
          resetPromotionChooser();
          setStatus('That destination is not legal. Choose a highlighted square.', 'error');
          syncSubmissionControls();
          return;
        }

        pendingMove = {
          from: move.from,
          to: move.to,
          promotion: promoInfo.isPromotion ? (move.promotion || promoToUse) : '',
          san: move.san,
          requiresPromotion: promoInfo.isPromotion,
        };
        if (promoInfo.isPromotion) {
          promotionChoice = pendingMove.promotion;
          updatePromotionButtons();
          if (promotionChooser) {
            promotionChooser.classList.add('show');
          }
        } else {
          promotionPending = false;
          promotionColor = null;
        }
        selectionStateFingerprint = stateFingerprint(state);
        selectionIsStale = false;
        lastMoveSquares = { from: move.from, to: move.to };
        updateMovePreviewDisplay(`${move.san} (${move.from}→${move.to})`);
        syncSubmissionControls();
        selectedSquare = null;
        renderBoard();
        updateStatusMessage();
        if (promotionPending) promotionButtons.find(btn => btn.dataset.piece === promotionChoice)?.focus();
      }

      function ensureTokenPresent() {
        if (turnstileToken) return true;
        verificationMessage = verificationMessage || 'Complete the CAPTCHA before submitting.';
        syncSubmissionControls();
        setStatus(verificationMessage, 'error');
        btnSubmit.disabled = true;
        return false;
      }

      function resetTurnstile() {
        turnstileToken = null;
        verificationMessage = '';
        if (window.turnstile && turnstileWidgetId !== null) {
          window.turnstile.reset(turnstileWidgetId);
        }
        syncSubmissionControls();
      }

      function setStatus(message, tone = 'muted', { showSpinner = false } = {}) {
        statusMsg.parentElement.setAttribute('aria-live', tone === 'error' ? 'assertive' : 'polite');
        if (statusMsg.textContent !== message) statusMsg.textContent = message;
        statusMsg.className = tone;
        statusSpinner.classList.toggle('show', showSpinner);
        statusMsg.closest('.status-stack').hidden = !message;
        submissionHelp.hidden = Boolean(message) || !submissionHelp.textContent;
      }

      function renderLiveMetadata(currentState) {
        if (!currentState) return;
        liveGameId.textContent = currentState.id == null ? '' : `#${currentState.id}`;
        const status = currentState.status || 'Unknown';
        const visitorTurn = currentState.turn_color === currentState.visitor_color;
        liveGameStatus.textContent = status === 'active' ? (visitorTurn ? 'Your turn' : 'Host to move') : 'Game over';
        liveLastMove.textContent = currentState.last_move_san || '—';
        // SQLite timestamps are UTC; use an unambiguous machine-readable value.
        const iso = normalizeTimestamp(currentState.updated_at);
        liveUpdatedAt.textContent = formatHumanTimestamp(iso) || '—';
        if (iso && Number.isFinite(Date.parse(iso))) liveUpdatedAt.setAttribute('datetime', iso);
        else liveUpdatedAt.removeAttribute('datetime');
        renderScoreboard(currentState.score);
      }

      function renderScoreboard(scoreData) {
        if (!scoreboardEl || !scoreData || typeof scoreData !== 'object') return;
        const hostWins = Number.isFinite(Number(scoreData.host_wins)) ? Number(scoreData.host_wins) : 0;
        const worldWins = Number.isFinite(Number(scoreData.world_wins)) ? Number(scoreData.world_wins) : 0;
        const draws = Number.isFinite(Number(scoreData.draws)) ? Number(scoreData.draws) : 0;
        if (scoreHostValue) scoreHostValue.textContent = hostWins;
        if (scoreWorldValue) scoreWorldValue.textContent = worldWins;
        if (scoreDrawValue) scoreDrawValue.textContent = draws;
        if (scoreLastResult) {
          const friendlyMap = { host: 'Host win', world: 'World win', draw: 'Draw' };
          const lastRaw = (scoreData && typeof scoreData.last_result === 'string') ? scoreData.last_result : '';
          if (lastRaw) {
            const friendly = friendlyMap[lastRaw] || lastRaw;
            scoreLastResult.textContent = `Last result: ${friendly}`;
            scoreLastResult.classList.add('show');
          } else {
            scoreLastResult.textContent = '';
            scoreLastResult.classList.remove('show');
          }
        }
        scoreboardEl.dataset.updatedAt = scoreData?.updated_at || '';
      }

      function blockForStateError(message) {
        stateUnavailable = true;
        setStatus(message, 'error');
        btnSubmit.disabled = true;
        pendingMove = null;
        pendingBaseFen = null;
        selectedSquare = null;
        resetPromotionChooser();
        updateMovePreviewDisplay('');
        boardEl.classList.add('locked');
        try {
          game.clear();
        } catch (err) {
          // ignore
        }
        renderBoard();
      }

      function setSubmittingState(isSubmitting) {
        submitting = isSubmitting;
        if (btnFlip) btnFlip.disabled = isSubmitting || !state;
        btnSubmit.disabled = true;
        btnRefresh.disabled = isSubmitting;
        btnBannerRefresh.disabled = isSubmitting;
        statusSpinner.classList.toggle('show', isSubmitting);
        promotionButtons.forEach(btn => { btn.disabled = isSubmitting; });
        if (gameOverRefresh) gameOverRefresh.disabled = isSubmitting;
        updateHistoryUI();
        updateHighlights();
      }

      function normalizeTimestamp(ts) {
        const raw = String(ts || '').trim();
        return /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(raw)
          ? `${raw.replace(' ', 'T')}Z` : raw;
      }

      function formatHumanTimestamp(ts) {
        const normalized = normalizeTimestamp(ts);
        if (!normalized) return '';
        const d = new Date(normalized);
        if (Number.isNaN(d.getTime())) return '';
        return d.toLocaleString(undefined, {
          month: 'short',
          day: 'numeric',
          year: 'numeric',
          hour: 'numeric',
          minute: '2-digit',
        });
      }

      function buildLastMoveText(currentState, fallbackSan = '') {
        if (!currentState) return '—';
        const tsText = formatHumanTimestamp(currentState.updated_at);
        const san = (currentState.last_move_san || fallbackSan || '').trim();
        if (san && tsText) return `${san} \u2022 ${tsText}`;
        if (tsText) return tsText;
        return san || '—';
      }

      function normalizeColor(color) {
        if (uiHelpers && typeof uiHelpers.normalizeColor === 'function') {
          return uiHelpers.normalizeColor(color);
        }
        return String(color).toLowerCase() === 'black' ? 'black' : 'white';
      }

      function computeGameOverFromFen(fen) {
        const base = { over: false, reason: null, winner: null };
        if (!fen) return base;
        try {
          const checker = new Chess(fen);
          const checkmate = checker.in_checkmate();
          const stalemate = checker.in_stalemate();
          const draw = checker.in_draw();
          let winner = null;
          if (checkmate) {
            const turn = checker.turn();
            winner = turn === 'w' ? 'b' : 'w';
          }
          let reason = null;
          if (checkmate) reason = 'checkmate';
          else if (stalemate) reason = 'stalemate';
          else if (draw) reason = 'draw';
          return {
            over: checkmate || stalemate || draw,
            reason,
            winner,
          };
        } catch (err) {
          return base;
        }
      }

      function applyGameOverUI(info) {
        gameOverState = info || { over: false, reason: null, winner: null };
        const isOver = gameOverState.over === true;
        boardEl.classList.toggle('locked', isOver);
        btnSubmit.disabled = true;
        if (!isOver) {
          if (gameOverBanner) gameOverBanner.classList.remove('show');
          return;
        }

        const winnerColor = gameOverState.winner === 'w' ? 'white' : (gameOverState.winner === 'b' ? 'black' : null);
        const hostColorNorm = normalizeColor(hostColor);
        const visitorColorNorm = normalizeColor(visitorColor);
        const hostWon = winnerColor && winnerColor === hostColorNorm;
        const worldWon = winnerColor && winnerColor === visitorColorNorm;
        let title = 'Game over';
        let body = 'Waiting for the host to start the next game. Sides will switch.';
        if (gameOverState.reason === 'checkmate') {
          title = 'Checkmate';
          if (hostWon) {
            body = 'Host wins. Waiting for the host to start the next game; sides will switch.';
          } else if (worldWon) {
            body = 'World wins. Waiting for the host to start the next game; sides will switch.';
          }
        } else if (gameOverState.reason === 'stalemate' || gameOverState.reason === 'draw') {
          title = 'Draw';
          body = 'Draw. Waiting for the host to start the next game; sides will switch.';
        }

        if (gameOverTitle) gameOverTitle.textContent = title;
        if (gameOverBody) gameOverBody.textContent = body;
        if (gameOverBanner) gameOverBanner.classList.add('show');
        setStatus('Game over. Waiting for next game.', 'muted');
      }

      function updateStatusMessage() {
        // The game heading shows turn/status; submissionHelp shows the next step.
        // statusMsg is reserved for transient loading, errors, and confirmations.
        setStatus('');
        boardEl.classList.toggle('locked', !state || !isHistoryLiveView() ||
          gameOverState.over || !isVisitorsTurn());
        syncSubmissionControls();
      }

      function deriveLastMoveSquares(currentState) {
        if (!currentState) return null;
        if (currentState.last_move_from && currentState.last_move_to) {
          return { from: currentState.last_move_from, to: currentState.last_move_to };
        }
        const normalizedPgn = normalizeMovetext(currentState.pgn);
        if (!normalizedPgn) return null;
        try {
          const replay = new Chess();
          const loaded = replay.load_pgn(normalizedPgn);
          if (!loaded) return null;
          const hist = replay.history({ verbose: true });
          if (!hist.length) return null;
          const last = hist[hist.length - 1];
          return { from: last.from, to: last.to };
        } catch (err) {
          return null;
        }
      }


      function stripSafePgnNonMoveContent(pgnText) {
        return (pgnText || '')
          .replace(/^\s*\[[^\]\r\n]*\]\s*$/gm, ' ')
          .replace(/\{[^}]*\}/g, ' ')
          .replace(/;[^\r\n]*/g, ' ')
          .replace(/\([^()]*\)/g, ' ')
          .replace(/\$\d+/g, ' ')
          .replace(/\b(?:1-0|0-1|1\/2-1\/2|\*)\b/g, ' ')
          .replace(/\s+/g, ' ')
          .trim();
      }

      function loadPgnHistorySans(pgnText, { allowNormalization = false } = {}) {
        const source = allowNormalization
          ? normalizeMovetext(pgnText)
          : stripSafePgnNonMoveContent(pgnText);
        if (!source) return { historySans: [], normalizedPgn: '' };
        const parser = new Chess();
        const loaded = parser.load_pgn(source, { sloppy: true });
        if (!loaded) return null;
        const historySans = parser.history();
        return {
          historySans,
          normalizedPgn: buildPgnFromSansMoves(historySans) || source,
        };
      }

      function parseSansFromPgn(pgnText) {
        const rawResult = loadPgnHistorySans(pgnText, { allowNormalization: false });
        const fallbackResult = loadPgnHistorySans(pgnText, { allowNormalization: true });
        const best = [rawResult, fallbackResult]
          .filter(Boolean)
          .sort((a, b) => b.historySans.length - a.historySans.length)[0];
        return best ? best.historySans : [];
      }

      function getSnapshotSans() {
        if (historyState && Array.isArray(historyState.historySans)) {
          return historyState.historySans;
        }
        return parseSansFromPgn(state?.pgn || '');
      }

      function movesToUciString(verboseHistory) {
        if (!Array.isArray(verboseHistory) || !verboseHistory.length) return '';
        return verboseHistory.map((m) => `${m.from}${m.to}${m.promotion || ''}`).join(' ').trim();
      }

      function buildMoveTokensFromHistory(verboseHistory) {
        if (!Array.isArray(verboseHistory) || !verboseHistory.length) return [];
        return verboseHistory.map((m) => `${m.from}${m.to}`);
      }

      const GENERIC_OPENING_NAMES = new Set([
        "king's pawn game",
        'center game',
        'italian game',
        'scotch game',
      ]);

      const GENERIC_OPENING_PATTERNS = [/\bopening$/i];

      function isGenericUmbrellaName(name) {
        if (!name) return false;
        const normalized = name.trim().toLowerCase();
        if (GENERIC_OPENING_NAMES.has(normalized)) return true;
        return GENERIC_OPENING_PATTERNS.some((re) => re.test(normalized));
      }

      const PLY_NUMBERED_PATTERN = /\b\d+\.\.\./;

      function isPlyNumberedPgn(text) {
        return PLY_NUMBERED_PATTERN.test(text || '');
      }

      function buildPgnFromSansMoves(sansMoves) {
        if (!Array.isArray(sansMoves) || sansMoves.length === 0) return '';
        const parts = [];
        sansMoves.forEach((san, idx) => {
          const moveNo = Math.floor(idx / 2) + 1;
          if (idx % 2 === 0) {
            parts.push(`${moveNo}. ${san}`);
          } else {
            parts.push(san);
          }
        });
        return parts.join(' ').trim();
      }

      function normalizePlyNumberedPGN(text) {
        const tokens = (text || '').trim().split(/\s+/).filter(Boolean);
        if (!tokens.length) return '';

        const entries = [];
        for (let i = 0; i < tokens.length; i += 1) {
          const match = tokens[i].match(/^(\d+)\.(\.\.)?$/);
          if (!match) continue;
          const san = tokens[i + 1];
          if (!san) break;
          entries.push({
            num: parseInt(match[1], 10),
            color: match[2] ? 'b' : 'w',
            san,
          });
          i += 1; // skip SAN token
        }

        if (!entries.length) return text.trim();

        const needsRenumber = entries.some((entry, idx) => entry.num !== Math.floor(idx / 2) + 1);
        const moveMap = new Map();

        entries.forEach((entry, idx) => {
          const moveNum = needsRenumber ? (Math.floor(idx / 2) + 1) : entry.num;
          if (!moveMap.has(moveNum)) {
            moveMap.set(moveNum, { white: null, black: null });
          }
          const slot = moveMap.get(moveNum);
          if (entry.color === 'b') {
            if (!slot.black) slot.black = entry.san;
          } else if (!slot.white) {
            slot.white = entry.san;
          }
        });

        const orderedNumbers = Array.from(moveMap.keys()).sort((a, b) => a - b);
        const parts = [];
        orderedNumbers.forEach((num) => {
          const slot = moveMap.get(num);
          if (!slot) return;
          if (slot.white) {
            parts.push(`${num}. ${slot.white}${slot.black ? ` ${slot.black}` : ''}`);
          } else if (slot.black) {
            parts.push(`${num}... ${slot.black}`);
          }
        });

        return parts.join(' ').trim();
      }

      function normalizeMovetext(pgnText) {
        const trimmed = (pgnText || '').trim();
        if (!trimmed) return '';
        if (isPlyNumberedPgn(trimmed)) {
          const normalized = normalizePlyNumberedPGN(trimmed);
          if (normalized) return normalized;
        }
        return trimmed;
      }

      function deriveDisplayPgn(rawPgn, sansMoves) {
        if (Array.isArray(sansMoves) && sansMoves.length) {
          const rendered = buildPgnFromSansMoves(sansMoves);
          return normalizeMovetext(rendered);
        }
        return normalizeMovetext(rawPgn || '');
      }

      if (DEBUG) {
        const shortBroken = '1. e4 2... e5 2. Nc3 3... Nc6';
        const shortNormalized = normalizePlyNumberedPGN(shortBroken);
        console.assert(shortNormalized === '1. e4 e5 2. Nc3 Nc6', `Short PGN normalization failed: ${shortNormalized}`);

        const fullBroken = '1. e4 2... e5 2. Nc3 3... Nc6 3. Nd5 4... Nf6 4. Nxf6+ 5... Qxf6 5. Nf3 6... Bc5 6. c3 7... O-O 7. d4 8... exd4 8. Bg5 9... Qg6 9. cxd4 10... Qxe4+ 10. Be2 11... Nxd4 11. Nxd4 12... Bxd4 12. O-O 13... Bxb2 13. Rb1 14... Be5 14. Bf3 15... Qf5 15. Be3 16... d6 16. Bxb7 17... Bxb7 17. Rxb7 18... Qc8 18. Rxa7 19... Rxa7 19. Bxa7 20... c5 20. f4 21... Bd4+ 21. Kh1 22... Qa6 22. h3 23... Qxa7 23. Qb3 24... Rb8 24. Qd5 25... Ra8 25. Qxd6 26... Qxa2 26. Qc6 27... c4 27. g4 28... c3 28. f5 29... c2 29. f6 30. Bxf6 30... Bxf6 30. C1c1';
        const fullNormalized = normalizePlyNumberedPGN(fullBroken);
        console.assert(fullNormalized.includes('1. e4 e5'), 'Full PGN should contain a normalized first reply');
        console.assert(!/\b2\.\.\.\s*e5\b/.test(fullNormalized), 'Full PGN should not retain ply-numbered black reply');
      }

      function buildSnapshotContext() {
        const snapshot = {
          chess: new Chess(),
          verboseMoves: [],
          sequence: '',
          plyCount: 0,
          totalPlies: 0,
        };
        const sansMoves = getSnapshotSans();
        snapshot.totalPlies = sansMoves.length;
        const targetPly = historyState ? clampSelectedPly(viewState.selectedPly) : sansMoves.length;
        const limit = Math.max(0, Math.min(targetPly, sansMoves.length));
        try {
          const replay = new Chess();
          for (let i = 0; i < limit; i += 1) {
            const mv = replay.move(sansMoves[i], { sloppy: true });
            if (!mv) break;
          }
          snapshot.chess = replay;
          snapshot.verboseMoves = replay.history({ verbose: true }) || [];
          snapshot.sequence = movesToUciString(snapshot.verboseMoves);
          snapshot.plyCount = snapshot.verboseMoves.length;
        } catch (err) {
          console.error('Snapshot rebuild failed', err);
          try {
            if (state?.fen) {
              snapshot.chess.load(state.fen);
            }
          } catch (err2) {
            // ignore
          }
        }
        return snapshot;
      }

      async function getOpeningInfo(chessInstance) {
        if (!chessInstance) return null;
        const historyVerbose = chessInstance.history({ verbose: true }) || [];
        const gameMoveTokens = buildMoveTokensFromHistory(historyVerbose);
        if (!gameMoveTokens.length) return null;
        const data = await ecoLiteStore.getData();
        if (!data || !data.length) return null;
        const matches = [];
        let hasFourPlyOrLonger = false;

        for (const entry of data) {
          const movesArray = entry?.movesArray || [];
          const movesLen = entry?.movesLen || movesArray.length || 0;
          if (!movesLen || movesLen > gameMoveTokens.length) continue;
          const isShortLine = movesLen <= 2;
          let isPrefix = true;
          for (let i = 0; i < movesLen; i += 1) {
            if (movesArray[i] !== gameMoveTokens[i]) {
              isPrefix = false;
              break;
            }
          }
          if (!isPrefix) continue;
          if (movesLen >= 4) {
            hasFourPlyOrLonger = true;
          }
          // Short prefixes are ambiguous across many ECO lines, so mark them generic.
          matches.push({
            eco: entry.eco,
            name: entry.name,
            movesLen,
            isGeneric: isShortLine ? true : isGenericUmbrellaName(entry.name),
            order: entry.order ?? 0,
          });
        }

        // Avoid returning umbrella 2-ply entries when a longer line matches.
        const filteredMatches = hasFourPlyOrLonger
          ? matches.filter((m) => m.movesLen > 2)
          : matches;

        if (!filteredMatches.length) return null;

        // Longest-prefix wins because eco_lite.json includes many ambiguous 2-ply umbrella lines.
        filteredMatches.sort((a, b) => {
          if (a.movesLen !== b.movesLen) return b.movesLen - a.movesLen;
          if (a.isGeneric !== b.isGeneric) return a.isGeneric ? 1 : -1;
          if (a.eco !== b.eco) return a.eco.localeCompare(b.eco);
          if (a.name !== b.name) return a.name.localeCompare(b.name);
          return (a.order || 0) - (b.order || 0);
        });

        const best = filteredMatches[0];
        if (DEBUG) {
          console.debug('Opening match', {
            sample: gameMoveTokens.slice(0, 10),
            eco: best?.eco,
            name: best?.name,
            plies: best?.movesLen,
          });
        }
        return best ? { eco: best.eco, name: best.name, matchedPlies: best.movesLen } : null;
      }

      async function renderGameSnapshot() {
        if (!snapshotBlock) return;
        const lookupId = ++openingLookupToken;
        const context = buildSnapshotContext();
        const lastMove = context.verboseMoves[context.verboseMoves.length - 1] || null;
        const lastMoveSan = lastMove
          ? (lastMove.san || `${lastMove.from}${lastMove.to}${lastMove.promotion || ''}`)
          : '';
        const lastMoveLine = isLiveMode() ? buildLastMoveText(state, lastMoveSan) : lastMoveSan;
        const fullmove = Number(context.chess.fen().split(/\s+/)[5]) || 1;
        const plyText = `Move ${fullmove}`;
        if (snapshotPlyEl && snapshotRenderCache.ply !== plyText) {
          snapshotRenderCache.ply = plyText;
          snapshotPlyEl.textContent = plyText;
        }
        const lastMoveText = lastMoveLine || '—';
        if (snapshotLastMoveEl && snapshotRenderCache.lastMove !== lastMoveText) {
          snapshotRenderCache.lastMove = lastMoveText;
          snapshotLastMoveEl.textContent = lastMoveText;
        }
        const toMove = context.chess.turn() === 'w' ? 'White' : 'Black';
        const statusBits = [isLiveMode() ? (state?.status || 'active') : 'Review position', `${toMove} to move`];
        if (context.chess.in_checkmate()) {
          statusBits.push('checkmate');
        } else if (context.chess.in_check()) {
          statusBits.push('check');
        } else if (context.chess.in_stalemate()) {
          statusBits.push('stalemate');
        }
        const statusText = statusBits.join(' · ');
        if (snapshotStatusEl && snapshotRenderCache.status !== statusText) {
          snapshotRenderCache.status = statusText;
          snapshotStatusEl.textContent = statusText;
        }
        let openingText = snapshotRenderCache.opening || 'Unknown';
        try {
          const opening = await getOpeningInfo(context.chess);
          if (opening) {
            openingText = `${opening.eco} — ${opening.name}`;
          } else if (!snapshotRenderCache.opening) {
            openingText = 'Unknown';
          }
        } catch (err) {
          console.error('Opening lookup failed', err);
        }
        if (lookupId !== openingLookupToken) return;
        if (snapshotOpeningEl && snapshotRenderCache.opening !== openingText) {
          snapshotRenderCache.opening = openingText;
          snapshotOpeningEl.textContent = openingText;
        }
      }

      function buildHistoryTimeline(initialFen, movesPgn) {
        const startFen = (initialFen && initialFen !== 'start') ? initialFen : null;
        const seed = startFen ? new Chess(startFen) : new Chess();
        const baseTimeline = [seed.fen()];
        const rawMovetext = stripSafePgnNonMoveContent(movesPgn);
        if (!rawMovetext) return { timeline: baseTimeline, historySans: [], verboseMoves: [], normalizedPgn: '' };

        const attempts = [
          { label: 'raw', pgn: rawMovetext },
          { label: 'normalized-fallback', pgn: normalizeMovetext(movesPgn) },
        ];
        let best = null;
        const errors = [];

        attempts.forEach((attempt) => {
          if (!attempt.pgn) return;
          try {
            const parser = startFen ? new Chess(startFen) : new Chess();
            const loaded = parser.load_pgn(attempt.pgn, { sloppy: true });
            if (!loaded) {
              errors.push(`${attempt.label}: invalid PGN`);
              return;
            }
            const historySans = parser.history();
            const replay = startFen ? new Chess(startFen) : new Chess();
            const timeline = [seed.fen()];
            const verboseMoves = [];
            for (let idx = 0; idx < historySans.length; idx += 1) {
              const move = replay.move(historySans[idx], { sloppy: true });
              if (!move) throw new Error(`Illegal move at ply ${idx + 1}: ${historySans[idx]}`);
              verboseMoves.push(move);
              timeline.push(replay.fen());
            }
            const candidate = {
              timeline,
              historySans,
              verboseMoves,
              normalizedPgn: buildPgnFromSansMoves(historySans) || attempt.pgn,
              source: attempt.label,
            };
            if (!best || candidate.historySans.length > best.historySans.length) {
              best = candidate;
            }
          } catch (err) {
            errors.push(`${attempt.label}: ${err && err.message ? err.message : String(err)}`);
          }
        });

        if (!best) {
          throw new Error(errors.length ? errors.join('; ') : 'Invalid PGN');
        }
        return best;
      }

      function resetHistoryNotice() {
        historyNotice?.classList.remove('show');
      }

      function syncHistoryFromState(currentState) {
        const initialFen = 'start';
        const movesPgn = (currentState && typeof currentState.pgn === 'string')
          ? currentState.pgn
          : '';
        const fallbackFen = (currentState && currentState.fen) ? currentState.fen : null;
        const fallbackPgn = normalizeMovetext(movesPgn);
        try {
          const historyBundle = buildHistoryTimeline(initialFen, movesPgn);
          const timeline = historyBundle?.timeline || [];
          const sans = historyBundle?.historySans || [];
          const finalTimelineFen = timeline.length ? timeline[timeline.length - 1] : null;
          if (fallbackFen && finalTimelineFen && finalTimelineFen !== fallbackFen) {
            throw new Error('PGN history does not match the canonical live position');
          }
          const liveIndex = Math.max(0, timeline.length - 1);
          const maxIdx = Math.max(0, timeline.length - 1);
          const desiredIdx = viewState.mode === 'live'
            ? liveIndex
            : Math.max(0, Math.min(viewState.selectedPly, maxIdx));
          updateViewState({
            latestPly: liveIndex,
            selectedPly: viewState.mode === 'live' ? liveIndex : desiredIdx,
          });
          historyState = {
            timeline,
            historySans: sans,
            verboseMoves: historyBundle.verboseMoves,
            normalizedPgn: historyBundle?.normalizedPgn || fallbackPgn,
            liveIndex,
            idx: getSelectedHistoryIndex(),
            get isLive() { return isHistoryLiveView(); },
          };
          updateHistoryUI();
          const fenForSelection = timeline[getSelectedHistoryIndex()] || fallbackFen;
          return {
            fen: fenForSelection,
            normalizedPgn: historyBundle?.normalizedPgn || fallbackPgn,
          };
        } catch (err) {
          const message = err && err.message ? err.message : String(err);
          historyStatus.textContent = `History unavailable: ${message}`;
          historyStatus.className = 'history-chip error';
          console.error('History reconstruction failed', err);
          historyState = null;
          updateViewState({ mode: 'live', selectedPly: 0, latestPly: 0 });
          resetHistoryNotice();
          return {
            fen: fallbackFen,
            normalizedPgn: fallbackPgn,
          };
        }
      }

      function updateHistoryUI() {
        const h = historyState;
        if (!h || !Array.isArray(h.timeline)) {
          if (btnStart) btnStart.disabled = true;
          if (btnBack) btnBack.disabled = true;
          if (btnForward) btnForward.disabled = true;
          if (btnLive) btnLive.disabled = true;
          resetHistoryNotice();
          boardEl.classList.remove('history-locked');
          if (historyStatus) {
            historyStatus.textContent = 'Live';
            historyStatus.hidden = true;
            historyStatus.className = 'history-chip muted';
          }
          return;
        }
        const selectedIdx = getSelectedHistoryIndex();
        const isLive = isHistoryLiveView();
        historyState.idx = selectedIdx;
        if (btnStart) btnStart.disabled = submitting || selectedIdx <= 0;
        if (btnBack) btnBack.disabled = submitting || selectedIdx <= 0;
        if (btnForward) btnForward.disabled = submitting || selectedIdx >= h.timeline.length - 1;
        if (btnLive) btnLive.disabled = submitting || isLive;
        const statusText = h.timeline.length > 1
          ? (isLive ? 'Live' : `Reviewing ply ${selectedIdx} of ${h.timeline.length - 1}`)
          : 'Live';
        historyStatus.textContent = statusText;
        historyStatus.hidden = isLive;
        historyStatus.className = 'history-chip';
        historyStatus.classList.toggle('muted', isLive);
        historyStatus.classList.toggle('reviewing', !isLive);
        if (historyNotice) {
          historyNotice.classList.toggle('show', !isLive);
        }
        boardEl.classList.toggle('history-locked', Boolean(h && !isLive));
        syncSubmissionControls();
      }

      function getSelectedHistoryMove() {
        return historyState?.verboseMoves?.[getSelectedHistoryIndex() - 1] || null;
      }

      function renderHistoryPosition({ forceRender = false } = {}) {
        const h = historyState;
        if (!h || !Array.isArray(h.timeline) || !h.timeline.length) {
          return;
        }
        const clamped = clampSelectedPly(viewState.selectedPly);
        if (clamped !== viewState.selectedPly) {
          updateViewState({ selectedPly: clamped });
        }
        historyState.idx = clamped;
        const targetFen = isHistoryLiveView()
          ? (state?.fen || h.timeline[clamped])
          : (h.timeline[clamped] || state?.fen);
        if (!forceRender && targetFen === game.fen()) {
          updateHistoryUI();
          updateStatusMessage();
          renderGameSnapshot();
          return;
        }
        try {
          if (!game.load(targetFen)) throw new Error('Invalid history FEN');
        } catch (err) {
          historyStatus.textContent = `History unavailable: ${err && err.message ? err.message : err}`;
          historyStatus.className = 'history-chip error';
          console.error('History load failed', err);
          historyState = null;
          boardEl.classList.remove('history-locked');
          return;
        }
        clearSelection();
        lastMoveSquares = isHistoryLiveView() ? deriveLastMoveSquares(state) : getSelectedHistoryMove();
        renderBoard();
        updateStatusMessage();
        updateHistoryUI();
        renderGameSnapshot();
      }

      function applyHistoryPosition(targetIdx, { forceRender = false, mode = null, allowAutoLive = false } = {}) {
        if (submitting) return;
        const h = historyState;
        if (!h || !Array.isArray(h.timeline) || !h.timeline.length) {
          return;
        }
        const latest = h.liveIndex;
        let nextMode = mode || viewState.mode || 'live';
        let clamped = clampSelectedPly(targetIdx);
        if (nextMode === 'live') {
          clamped = latest;
        } else if (allowAutoLive && clamped >= latest) {
          nextMode = 'live';
          clamped = latest;
        }
        updateViewState({
          mode: nextMode,
          selectedPly: clamped,
        });
        renderHistoryPosition({ forceRender });
      }

      function setNotationData({ fen = '', pgn = '' }) {
        notationData = { fen: fen || '', pgn: pgn || '' };
        if (notationVisible && notationElements) {
          notationElements.fenBox.textContent = notationData.fen;
          notationElements.pgnBox.textContent = notationData.pgn;
          notationElements.copyFenMsg.textContent = '';
          notationElements.copyPgnMsg.textContent = '';
        }
      }

      function showNotation() {
        if (!notationMount || notationVisible) return;
        const wrapper = document.createElement('div');
        wrapper.className = 'notation-grid';
        const fenBlock = document.createElement('div');
        fenBlock.className = 'notation-block';
        const fenHeader = document.createElement('div');
        fenHeader.className = 'notation-header';
        const fenLabel = document.createElement('p');
        fenLabel.className = 'notation-label muted';
        fenLabel.textContent = 'Live-game FEN';
        const fenActions = document.createElement('div');
        fenActions.className = 'notation-actions';
        const copyFenBtn = document.createElement('button');
        copyFenBtn.type = 'button';
        copyFenBtn.className = 'secondary ghost-button button-compact';
        copyFenBtn.textContent = 'Copy FEN';
        const copyFenMsg = document.createElement('span');
        copyFenMsg.className = 'copy-note';
        copyFenMsg.setAttribute('aria-live', 'polite');
        fenActions.appendChild(copyFenBtn);
        fenActions.appendChild(copyFenMsg);
        fenHeader.appendChild(fenLabel);
        fenHeader.appendChild(fenActions);
        const fenBox = document.createElement('pre');
        fenBox.className = 'notation-readout notation-readout--fen';
        fenBox.setAttribute('tabindex', '0');
        fenBox.setAttribute('role', 'region');
        fenBox.setAttribute('aria-label', 'Canonical live-game FEN');
        fenBlock.appendChild(fenHeader);
        fenBlock.appendChild(fenBox);

        const pgnBlock = document.createElement('div');
        pgnBlock.className = 'notation-block';
        const pgnHeader = document.createElement('div');
        pgnHeader.className = 'notation-header';
        const pgnLabel = document.createElement('p');
        pgnLabel.className = 'notation-label muted';
        pgnLabel.textContent = 'Live-game PGN';
        const pgnActions = document.createElement('div');
        pgnActions.className = 'notation-actions';
        const copyPgnBtn = document.createElement('button');
        copyPgnBtn.type = 'button';
        copyPgnBtn.className = 'secondary ghost-button button-compact';
        copyPgnBtn.textContent = 'Copy PGN';
        const copyPgnMsg = document.createElement('span');
        copyPgnMsg.className = 'copy-note';
        copyPgnMsg.setAttribute('aria-live', 'polite');
        pgnActions.appendChild(copyPgnBtn);
        pgnActions.appendChild(copyPgnMsg);
        pgnHeader.appendChild(pgnLabel);
        pgnHeader.appendChild(pgnActions);
        const pgnBox = document.createElement('pre');
        pgnBox.className = 'notation-readout notation-readout--pgn';
        pgnBox.setAttribute('tabindex', '0');
        pgnBox.setAttribute('role', 'region');
        pgnBox.setAttribute('aria-label', 'Canonical live-game PGN');
        pgnBlock.appendChild(pgnHeader);
        pgnBlock.appendChild(pgnBox);

        wrapper.appendChild(fenBlock);
        wrapper.appendChild(pgnBlock);
        notationMount.appendChild(wrapper);

        notationElements = { fenBox, pgnBox, copyFenBtn, copyPgnBtn, copyFenMsg, copyPgnMsg };
        notationVisible = true;
        toggleNotationBtn.textContent = 'Hide FEN / PGN';
        toggleNotationBtn.setAttribute('aria-expanded', 'true');

        const clearCopyNotes = () => {
          notationElements.copyFenMsg.textContent = '';
          notationElements.copyPgnMsg.textContent = '';
        };

        const copyText = async (value, targetMsgEl) => {
          clearCopyNotes();
          const text = value || '';
          try {
            if (navigator.clipboard && navigator.clipboard.writeText) {
              await navigator.clipboard.writeText(text);
            } else {
              const temp = document.createElement('textarea');
              const previousFocus = document.activeElement;
              temp.value = text;
              temp.style.position = 'fixed';
              temp.style.opacity = '0';
              document.body.appendChild(temp);
              temp.select();
              let copied;
              try {
                copied = document.execCommand('copy');
              } finally {
                temp.remove();
                if (previousFocus?.isConnected) previousFocus.focus({ preventScroll: true });
              }
              if (!copied) throw new Error('Clipboard unavailable');
            }
            targetMsgEl.textContent = 'Copied';
          } catch (err) {
            targetMsgEl.textContent = 'Copy failed';
          }
        };

        copyFenBtn.addEventListener('click', () => copyText(fenBox.textContent, copyFenMsg));
        copyPgnBtn.addEventListener('click', () => copyText(pgnBox.textContent, copyPgnMsg));
        setNotationData(notationData);
      }

      function hideNotation() {
        if (!notationVisible || !notationMount) return;
        notationMount.innerHTML = '';
        notationElements = null;
        notationVisible = false;
        toggleNotationBtn.textContent = 'Show FEN / PGN';
        toggleNotationBtn.setAttribute('aria-expanded', 'false');
      }

      function warnOnLiveFenTurnMismatch(currentState, loadedFen) {
        if (!currentState || !loadedFen || !currentState.turn_color) return;
        const parts = String(loadedFen).trim().split(/\s+/);
        const fenTurn = parts[1] === 'b' ? 'black' : (parts[1] === 'w' ? 'white' : null);
        const serverTurn = normalizeColor(currentState.turn_color);
        if (fenTurn && serverTurn && fenTurn !== serverTurn) {
          setStatus(`Diagnostic: canonical FEN says ${fenTurn} to move, but server turn_color says ${serverTurn}.`, 'error');
        }
      }

      function applyStateData(newState, { resetSelection = true } = {}) {
        const gameChanged = state && String(state.id) !== String(newState?.id);
        if (gameChanged) {
          boardFlipped = false;
          if (btnFlip) btnFlip.setAttribute('aria-pressed', 'false');
        }
        const canonicalState = stateStore.set(newState);
        latestFetchedStateFingerprint = stateFingerprint(canonicalState);
        visitorColor = canonicalState?.visitor_color;
        hostColor = canonicalState?.you_color;
        queuedServerState = null;
        selectionIsStale = false;

        renderLiveMetadata(canonicalState);

        visitorColorLabel.textContent = visitorColor;
        hostColorLabel.textContent = hostColor;

        const historyResult = syncHistoryFromState(canonicalState) || {};
        const normalizedPgnForDisplay = historyResult.normalizedPgn
          || deriveDisplayPgn(canonicalState.pgn, parseSansFromPgn(canonicalState.pgn));
        setNotationData({ fen: canonicalState.fen, pgn: normalizedPgnForDisplay });

        const selectedIdx = getSelectedHistoryIndex();
        const historyFenForSelection = historyState && Array.isArray(historyState.timeline)
          ? historyState.timeline[selectedIdx]
          : null;
        // Live board rendering is driven by the canonical API FEN. PGN-derived
        // history FENs are only authoritative while reviewing history.
        const preservePreview = isLiveMode() && !resetSelection && pendingMove &&
          selectionStateFingerprint === stateFingerprint(canonicalState);
        const fenToLoad = isLiveMode()
          ? (preservePreview ? game.fen() : canonicalState.fen)
          : (historyFenForSelection || canonicalState.fen);
        const shouldRenderPosition = gameChanged || isLiveMode() || (fenToLoad !== game.fen());

        if (shouldRenderPosition) {
          try {
            if (!game.load(fenToLoad)) throw new Error('Invalid position FEN');
          } catch (err) {
            blockForStateError(isLiveMode()
              ? 'Invalid canonical FEN from server. Refresh later.'
              : 'Invalid FEN from server. Refresh later.');
            return;
          }
        }
        const liveSquares = deriveLastMoveSquares(canonicalState);
        lastMoveSquares = preservePreview
          ? { from: pendingMove.from, to: pendingMove.to }
          : (isHistoryLiveView() ? liveSquares : getSelectedHistoryMove());

        // An unsubmitted mating preview must not end the canonical live game.
        const detected = computeGameOverFromFen(isLiveMode() ? canonicalState.fen : fenToLoad);
        const forcedOver = canonicalState.status && canonicalState.status !== 'active';
        applyGameOverUI({
          over: forcedOver || detected.over,
          reason: detected.reason || (forcedOver ? 'finished' : null),
          winner: detected.winner || null,
        });

        if (document && document.body) {
          document.body.dataset.gameId = canonicalState.id || '';
        }
        if (shouldRenderPosition) {
          renderBoard();
        }

        updateStatusMessage();
        if (isLiveMode() && !preservePreview) {
          warnOnLiveFenTurnMismatch(canonicalState, fenToLoad);
        }

        if (resetSelection) {
          clearSelection();
        } else {
          updateHighlights();
          if (!pendingMove) {
            btnSubmit.disabled = true;
          }
        }

        updateHistoryUI();
        hideUpdateBanner();
        if (btnFlip) btnFlip.disabled = submitting;
        if (!ecoLoadPrimed) {
          ecoLoadPrimed = true;
          ecoLiteStore.load().catch((err) => console.error('ECO dataset preload failed', err));
        }
        renderGameSnapshot();
      }

      function handleIncomingState(newState, { resetSelection = true, allowQueue = false } = {}) {
        const incomingFingerprint = stateFingerprint(newState);
        latestFetchedStateFingerprint = incomingFingerprint;
        const currentFingerprint = stateFingerprint(state);
        const hasChanged = !currentFingerprint || currentFingerprint !== incomingFingerprint;
        const promotionInterrupted = hasChanged && isPromotionFlowActive();

        handlePromotionInterrupted(incomingFingerprint, { queued: allowQueue });

        if (hasChanged) {
          cancelPromotionFlow();
        }

        if (hasChanged && allowQueue && historyState && !isHistoryLiveView()) {
          queuedServerState = newState;
          // Present fetched facts without applying the queued position or permissions.
          renderLiveMetadata(newState);
          selectionIsStale = true;
          setStatus('Live game updated. Click Live to return.', 'muted');
          btnSubmit.disabled = true;
          showUpdateBanner('Live game updated. Click Live to return.');
          return;
        }

        if (pendingMove && hasChanged && allowQueue) {
          queuedServerState = newState;
          renderLiveMetadata(newState);
          selectionIsStale = true;
          setStatus('New server state available. Refresh to sync.', 'muted');
          btnSubmit.disabled = true;
          const bannerMsg = historyState && !isHistoryLiveView()
            ? 'Live game updated. Click Live to return.'
            : 'New server state available. Refresh to sync.';
          showUpdateBanner(bannerMsg);
          return;
        }

        const shouldResetSelection = resetSelection || hasChanged;
        applyStateData(newState, { resetSelection: shouldResetSelection });
        if (promotionInterrupted) {
          setStatus('Game updated while choosing promotion; please retry.', 'muted');
        }
      }

      async function fetchState(options = {}) {
        const { resetSelection = true, allowQueue = false, silent = false } = options;
        const requestId = ++stateRequestId;
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 15000);
        if (!silent) {
          refreshingState = true;
          syncSubmissionControls();
          setStatus('Loading…', 'muted', { showSpinner: true });
        }

        try {
          const recovered = !submitting ? await submissions.recover() : null;
          const res = await fetch('api/state.php', { cache: 'no-store', signal: controller.signal });
          if (!res.ok) throw new Error('Failed to load state');
          const json = await res.json();
          if (!json || json.ok === false) {
            const message = (json && json.message) ? json.message : 'Failed to load state';
            throw new Error(message);
          }
          if (requestId !== stateRequestId) return;

          stateUnavailable = false;
          connectionFailed = false;
          connectionNotice.hidden = true;
          handleIncomingState(json, recovered
            ? { resetSelection: true, allowQueue: false }
            : { resetSelection, allowQueue });
          startPolling();
          if (submissions.pending && !submitting) {
            setStatus('Previous move outcome unconfirmed. Submit move to retry safely.', 'error');
            syncSubmissionControls();
          } else if (recovered) {
            setStatus('Move accepted. Current board refreshed.', 'ok');
          }
        } catch (err) {
          // A newer refresh owns the UI; an older failure must not lock it.
          if (requestId === stateRequestId) throw err;
        } finally {
          clearTimeout(timeout);
          if (requestId === stateRequestId) {
            if (!silent) refreshingState = false;
            updateHighlights();
          }
        }
      }

      function handleStateError(err) {
        blockForStateError(err.message || 'Failed to load state');
      }

      function startPolling() {
        if (pollHandle) return;
        pollHandle = setInterval(() => {
          if (submitting || refreshingState) return;
          fetchState({ resetSelection: false, allowQueue: true, silent: true }).catch((err) => {
            connectionFailed = true;
            connectionNotice.textContent = 'Could not refresh the live game. Use Refresh to reconnect before submitting.';
            connectionNotice.hidden = false;
            syncSubmissionControls();
            updateHighlights();
            console.error('Polling failed', err);
          });
        }, 20000);
      }

      function applyQueuedStateOrFetch({ forceLive = false } = {}) {
        if (submitting) return;
        if (forceLive) {
          updateViewState({ mode: 'live' });
        }
        cancelPromotionFlow();
        if (queuedServerState) {
          applyStateData(queuedServerState, { resetSelection: true });
          return;
        }
        if (forceLive && state) {
          applyStateData(state, { resetSelection: true });
        }
        fetchState({ resetSelection: true }).catch(handleStateError);
      }

      renderScoreboard(appConfig.score);
      renderBoard();
      updateHistoryUI();

      fetchState().then(() => {
        startPolling();
      }).catch(handleStateError);

      btnCancel.addEventListener('click', cancelSelectedMove);
      promotionChooser.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); cancelSelectedMove(); }
        // Arrow keys here must not navigate the historical game behind the chooser.
        if (['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(ev.key)) ev.stopPropagation();
      });

      promotionButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
          selectPromotionChoice(btn.dataset.piece);
          promotionChooser.classList.add('show');
        });
      });
      resetPromotionChooser();

      if (btnFlip) {
        btnFlip.addEventListener('click', () => {
          if (!state || submitting) return;
          boardFlipped = !boardFlipped;
          btnFlip.setAttribute('aria-pressed', String(boardFlipped));
          // Rerender the current position, including any preview; do not reload FEN.
          renderBoard();
        });
      }
      if (btnStart) {
        btnStart.addEventListener('click', () => {
          applyHistoryPosition(0, { forceRender: true, mode: 'review' });
        });
      }
      if (btnBack) {
        btnBack.addEventListener('click', () => {
          const next = getSelectedHistoryIndex() - 1;
          applyHistoryPosition(next, { forceRender: true, mode: 'review' });
        });
      }
      if (btnForward) {
        btnForward.addEventListener('click', () => {
          const next = getSelectedHistoryIndex() + 1;
          applyHistoryPosition(next, { forceRender: true, mode: 'review', allowAutoLive: true });
        });
      }
      if (btnLive) {
        btnLive.addEventListener('click', () => {
          applyQueuedStateOrFetch({ forceLive: true });
        });
      }

      document.addEventListener('keydown', (ev) => {
        if (ev.key === 'Escape' && (pendingMove || selectedSquare)) {
          ev.preventDefault(); cancelSelectedMove(); return;
        }
        if (isTypingContext(ev)) return;
        if (ev.key === 'ArrowLeft') {
          ev.preventDefault();
          const next = getSelectedHistoryIndex() - 1;
          applyHistoryPosition(next, { forceRender: true, mode: 'review' });
        } else if (ev.key === 'ArrowRight') {
          ev.preventDefault();
          const next = getSelectedHistoryIndex() + 1;
          applyHistoryPosition(next, { forceRender: true, mode: 'review', allowAutoLive: true });
        } else if (ev.key === 'Home') {
          ev.preventDefault();
          applyHistoryPosition(0, { forceRender: true, mode: 'review' });
        } else if (ev.key === 'End') {
          ev.preventDefault();
          applyQueuedStateOrFetch({ forceLive: true });
        }
      });

      btnRefresh.addEventListener('click', applyQueuedStateOrFetch);
      btnBannerRefresh.addEventListener('click', applyQueuedStateOrFetch);
      if (gameOverRefresh) {
        gameOverRefresh.addEventListener('click', applyQueuedStateOrFetch);
      }

      btnSubmit.addEventListener('click', async () => {
        syncSubmissionControls();
        if (submitting || btnSubmit.disabled) return;
        if (submissions.pending) { await sendVisitorMove(submissions.pending); return; }
        if (!pendingMove || !state) return;
        if (historyState && !isHistoryLiveView()) {
          setStatus('Reviewing history — click Live to return before submitting a move.', 'error');
          return;
        }
        if (gameOverState && gameOverState.over) {
          setStatus('Game is over. Wait for the next game.', 'error');
          return;
        }
        if (!ensureTokenPresent()) return;

        const latestKnownFingerprint = latestFetchedStateFingerprint || stateFingerprint(state);
        if (
          selectionStateFingerprint &&
          latestKnownFingerprint &&
          selectionStateFingerprint !== latestKnownFingerprint
        ) {
          setStatus('Server state changed. Refresh before submitting.', 'error');
          btnSubmit.disabled = true;
          showUpdateBanner();
          return;
        }

        if (selectionIsStale || queuedServerState) {
          setStatus('Server state changed. Refresh before submitting.', 'error');
          btnSubmit.disabled = true;
          showUpdateBanner();
          return;
        }

        await sendVisitorMove({from:pendingMove.from, to:pendingMove.to,
          promotion:pendingMove.requiresPromotion ? pendingMove.promotion : '',
          game_id:state.id, expected_revision:state.revision});
      });

      async function sendVisitorMove(payload) {
        setSubmittingState(true);
        setStatus('Submitting…', 'muted', { showSpinner: true });
        try {
          const recovered = await submissions.recover();
          if (!recovered) {
            if (!ensureTokenPresent()) { setSubmittingState(false); return; }
            await submissions.send(payload, {turnstile_token:turnstileToken});
          }
        } catch (err) {
          setStatus(err.message || 'Outcome unconfirmed. Retry Submit move to check.', 'error');
          resetTurnstile();
          setSubmittingState(false);
          return;
        }
        pendingMove = null;
        pendingBaseFen = null;
        updateMovePreviewDisplay('');
        resetPromotionChooser();
        resetTurnstile();
        setSubmittingState(false);
        try { await fetchState(); }
        catch (_) {
          stateUnavailable = true;
          syncSubmissionControls();
          updateHighlights();
          setStatus('Move accepted. Board refresh failed; use Refresh to reload it.', 'ok');
        }
      }

      if (toggleNotationBtn) {
        toggleNotationBtn.addEventListener('click', () => {
          if (notationVisible) {
            hideNotation();
          } else {
            showNotation();
          }
        });
      }

      function updateBackToTopVisibility() {
        if (!backToTopBtn) return;
        if (window.scrollY > 200) {
          backToTopBtn.classList.add('visible');
        } else {
          backToTopBtn.classList.remove('visible');
        }
      }

      if (backToTopBtn) {
        updateBackToTopVisibility();
        window.addEventListener('scroll', updateBackToTopVisibility, { passive: true });
        backToTopBtn.addEventListener('click', (ev) => {
          ev.preventDefault();
          const behavior = (prefersReducedMotion && prefersReducedMotion.matches) ? 'auto' : 'smooth';
          window.scrollTo({ top: 0, behavior });
        });
      }
    })();
  </script>
</body>
</html>
