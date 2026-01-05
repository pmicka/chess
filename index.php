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
 *    - Include CAPTCHA token + updated FEN/PGN + move notation
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
        ORDER BY updated_at DESC, id DESC
        LIMIT 1
    ");
    $preloadedGame = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    error_log('index.php preload_state_failed: ' . $e->getMessage());
}

$scoreTotals = score_load();
$scoreLineText = sprintf(
    'overall: host %d · world %d · draws %d',
    $scoreTotals['host_wins'] ?? 0,
    $scoreTotals['world_wins'] ?? 0,
    $scoreTotals['draws'] ?? 0
);

$baseUrl = defined('BASE_URL') ? trim((string)BASE_URL) : '';
$canonicalUrl = 'https://patrickmicka.com/chess/';
$googleVerification = defined('GOOGLE_SITE_VERIFICATION') ? trim((string)GOOGLE_SITE_VERIFICATION) : '';
$pageTitle = 'The Internet Gambit | patrickmicka.com';
$pageDescription = 'Me vs. the world, one board at a time.';
$socialImageUrl = 'https://patrickmicka.com/chess/assets/og-chess-v1.png';

$chessAppConfig = [
    'score' => $scoreTotals,
    'score_line' => $scoreLineText,
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
  <meta property="og:url" content="https://patrickmicka.com/chess/" />
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
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>
  </script>
  <script type="application/json" id="appConfig"><?= json_encode($chessAppConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="apple-touch-icon" sizes="180x180" href="assets/icons//apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/icons//favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/icons//favicon-16x16.png">
  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
</head>
<body>
  <div class="wrap">
    <h1>Me vs the World Chess</h1>
    <p class="subhead meta-line">
      <span>Visitors play <strong id="visitorColorLabel">black</strong></span>
      <span>Host plays <strong id="hostColorLabel">white</strong></span>
      <a class="about-link" href="#about">About</a>
    </p>

      <div class="card stage-card">
        <div class="board-stack">
          <div class="board-container">
            <div class="board-shell">
              <div id="board" aria-label="Chess board" role="application"></div>
              <div id="promotionChooser" class="promotion-chooser" aria-live="polite" aria-label="Choose promotion piece">
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
          <div class="scoreboard" id="scoreboard" aria-label="Scoreboard">
            <div class="score-grid">
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
          </div>
        </div>
        <div id="gameOverBanner" class="gameover-banner" role="alert" aria-live="polite">
          <div>
            <h3 id="gameOverTitle"></h3>
          <p id="gameOverBody" class="muted" style="margin:0;"></p>
        </div>
        <div class="gameover-actions">
          <button id="gameOverRefresh" type="button">Refresh</button>
        </div>
        </div>
        <div class="control-bay">
          <div class="control-row action-row">
            <div class="status-stack">
              <div class="status-line" aria-live="polite">
                <span id="statusSpinner" class="spinner" aria-hidden="true"></span>
                <span id="statusMsg" class="muted"></span>
              </div>
            </div>
            <div class="action-stack">
              <div class="primary-buttons">
                <button id="btnSubmit" disabled>Submit move</button>
                <button id="btnRefresh">Refresh</button>
            </div>
            <div class="turnstile-wrap">
              <div
                class="cf-turnstile"
                data-sitekey="<?= htmlspecialchars(TURNSTILE_SITE_KEY ?? '', ENT_QUOTES) ?>"
                data-callback="onTurnstileSuccess"
                data-expired-callback="onTurnstileExpired"
                data-error-callback="onTurnstileError"
              ></div>
            </div>
          </div>
          </div>
          <div class="control-row review-row">
            <div class="history-controls">
              <div class="history-buttons">
                <button id="btnBack" type="button">Back</button>
                <button id="btnForward" type="button">Forward</button>
                <button id="btnLive" type="button">Live</button>
              </div>
              <span id="historyStatus" class="history-chip" aria-live="polite"></span>
            </div>
          </div>
          <div id="historyNotice" class="banner" role="status" aria-live="polite">
            <span>Reviewing history — click Live to return before submitting a move.</span>
          </div>
          <p class="muted selected-move">
            Selected move: <code id="movePreview">none</code>
          </p>
          <div id="updateBanner" class="banner" role="status" aria-live="polite">
            <span>New server state available. Refresh to sync.</span>
            <button id="btnBannerRefresh">Refresh</button>
          </div>
          <div id="errorBox" class="error-block" role="alert" aria-live="polite"></div>
          <div class="hud-details">
            <button id="detailsToggle" class="details-toggle" type="button" aria-expanded="false" aria-controls="hudDetailsPanel">Details</button>
            <div id="hudDetailsPanel" class="hud-details-panel" hidden>
              <div id="gameSnapshot" class="game-snapshot" aria-live="polite">
                <div class="snapshot-row">
                  <span class="snapshot-label muted">Opening</span>
                  <span class="snapshot-value" id="snapshotOpening">Unknown</span>
                </div>
                <div class="snapshot-row">
                  <span class="snapshot-label muted">Ply count</span>
                  <span class="snapshot-value" id="snapshotPly">0 ply · move 0</span>
                </div>
                <div class="snapshot-row">
                  <span class="snapshot-label muted">Last move</span>
                  <span class="snapshot-value" id="snapshotLastMove">—</span>
                </div>
                <div class="snapshot-row">
                  <span class="snapshot-label muted">Status</span>
                  <span class="snapshot-value" id="snapshotStatus">—</span>
                </div>
              </div>
              <div class="details-divider" aria-hidden="true"></div>
              <div class="details-advanced">
                <div class="details-advanced-header">
                  <p class="muted details-advanced-title">Advanced</p>
                  <div class="notation-controls">
                    <button id="toggleNotation" class="secondary ghost-button button-compact" type="button">Show notation</button>
                    <span id="notationStatus" class="muted" aria-live="polite"></span>
                  </div>
                </div>
                <div id="notationMount" class="notation-mount"></div>
              </div>
            </div>
          </div>
        </div>
      </div>

    <p id="scoreLine" class="score-line" aria-live="polite"><?= htmlspecialchars($scoreLineText, ENT_QUOTES, 'UTF-8') ?></p>
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
  </div>

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
  <script src="assets/chess.min.js"></script>
  <script>
    (() => {
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
      const movePreview = document.getElementById('movePreview');
      const errorBox = document.getElementById('errorBox');
      const scoreLineEl = document.getElementById('scoreLine');
      const scoreboardEl = document.getElementById('scoreboard');
      const scoreHostValue = document.getElementById('scoreHostValue');
      const scoreWorldValue = document.getElementById('scoreWorldValue');
      const scoreDrawValue = document.getElementById('scoreDrawValue');
      const scoreLastResult = document.getElementById('scoreLastResult');
      const visitorColorLabel = document.getElementById('visitorColorLabel');
      const hostColorLabel = document.getElementById('hostColorLabel');
      const turnstileWidget = document.querySelector('.cf-turnstile');
      const updateBanner = document.getElementById('updateBanner');
      const updateBannerText = updateBanner ? updateBanner.querySelector('span') : null;
      const btnBannerRefresh = document.getElementById('btnBannerRefresh');
      const promotionChooser = document.getElementById('promotionChooser');
      const promotionButtons = Array.from(document.querySelectorAll('.promo-btn'));
      const gameOverBanner = document.getElementById('gameOverBanner');
      const gameOverTitle = document.getElementById('gameOverTitle');
      const gameOverBody = document.getElementById('gameOverBody');
      const gameOverRefresh = document.getElementById('gameOverRefresh');
      const btnBack = document.getElementById('btnBack');
      const btnForward = document.getElementById('btnForward');
      const btnLive = document.getElementById('btnLive');
      const historyStatus = document.getElementById('historyStatus');
      const historyNotice = document.getElementById('historyNotice');
      const notationMount = document.getElementById('notationMount');
      const toggleNotationBtn = document.getElementById('toggleNotation');
      const notationStatus = document.getElementById('notationStatus');
      const detailsToggle = document.getElementById('detailsToggle');
      const hudDetailsPanel = document.getElementById('hudDetailsPanel');
      const snapshotBlock = document.getElementById('gameSnapshot');
      const snapshotOpeningEl = document.getElementById('snapshotOpening');
      const snapshotPlyEl = document.getElementById('snapshotPly');
      const snapshotLastMoveEl = document.getElementById('snapshotLastMove');
      const snapshotStatusEl = document.getElementById('snapshotStatus');
      const backToTopBtn = document.getElementById('backToTopButton');
      const prefersReducedMotion = (typeof window !== 'undefined' && window.matchMedia)
        ? window.matchMedia('(prefers-reduced-motion: reduce)')
        : null;

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
      const ecoLiteStore = {
        started: false,
        data: null,
        promise: null,
        load() {
          if (this.started) return this.promise;
          this.started = true;
          this.promise = fetch('assets/data/eco_lite.json', { cache: 'no-store' })
            .then((res) => {
              if (!res.ok) throw new Error('Failed to load ECO data');
              return res.json();
            })
            .then((json) => {
              this.data = Array.isArray(json) ? json : [];
              return this.data;
            })
            .catch((err) => {
              console.error('ECO lite load failed', err);
              this.data = null;
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

      function isHistoryLiveView() {
        const h = historyState;
        if (!h) return false;
        return viewState.mode === 'live' && getSelectedHistoryIndex() === h.liveIndex;
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

      window.onTurnstileSuccess = (token) => { turnstileToken = token; };
      window.onTurnstileExpired = () => { turnstileToken = null; };
      window.onTurnstileError = () => { turnstileToken = null; };

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
        boardEl.innerHTML = '';

        const layout = getOrientationLayout(visitorColor);
        const files = layout.files;
        const ranks = layout.ranks;

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
            div.setAttribute('aria-label', 'Board square');
            const pieceEl = renderPieceElement(piece);
            if (pieceEl) {
              div.appendChild(pieceEl);
            }

            div.addEventListener('click', () => onSquareClick(div.dataset.squareId));
            boardEl.appendChild(div);
          }
        }

        updateHighlights();
      }

      function resetPromotionChooser() {
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
        movePreview.textContent = 'none';
        btnSubmit.disabled = true;
        resetPromotionChooser();
        if (restore && hadPending) {
          renderBoard();
        } else {
          updateHighlights();
        }
      }

      function clearErrors() {
        errorBox.textContent = '';
        errorBox.classList.remove('show');
      }

      function updateHighlights() {
        const squares = boardEl.querySelectorAll('.sq');
        squares.forEach(el => {
          el.classList.remove('pick');
          el.classList.remove('hint');
          el.classList.remove('last');
        });

        if (selectedSquare) {
          const pick = findSquareEl(selectedSquare);
          if (pick) pick.classList.add('pick');

          const moves = game.moves({ square: selectedSquare, verbose: true });
          moves.forEach(m => {
            const hint = findSquareEl(m.to);
            if (hint) hint.classList.add('hint');
          });
        }

        if (lastMoveSquares) {
          ['from', 'to'].forEach(key => {
            const target = findSquareEl(lastMoveSquares[key]);
            if (target) target.classList.add('last');
          });
        }
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
              movePreview.textContent = `${move.san} (${move.from}→${move.to})`;
              btnSubmit.disabled = false;
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

      function showUpdateBanner(message = null) {
        if (message) setUpdateBannerMessage(message);
        updateBanner.classList.add('show');
      }

      function hideUpdateBanner() {
        updateBanner.classList.remove('show');
        queuedServerState = null;
      }

      function onSquareClick(opaqueSquareId) {
        if (!state) return;
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
        movePreview.textContent = `${move.san} (${move.from}→${move.to})`;
        btnSubmit.disabled = false;
        selectedSquare = null;
        renderBoard();
      }

      function ensureTokenPresent() {
        if (turnstileToken) return true;
        setStatus('Solve the CAPTCHA before submitting.', 'error');
        btnSubmit.disabled = true;
        return false;
      }

      function resetTurnstile() {
        if (window.turnstile && turnstileWidget) {
          window.turnstile.reset(turnstileWidget);
        }
        turnstileToken = null;
      }

      function setStatus(message, tone = 'muted', { showSpinner = false } = {}) {
        statusMsg.textContent = message;
        statusMsg.className = tone;
        statusSpinner.classList.toggle('show', showSpinner);
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

      function renderScoreLine(scoreData, fallbackLine = '') {
        renderScoreboard(scoreData);
        if (!scoreLineEl) return;
        if (scoreData && typeof scoreData === 'object') {
          const hostWins = Number.isFinite(Number(scoreData.host_wins)) ? Number(scoreData.host_wins) : 0;
          const worldWins = Number.isFinite(Number(scoreData.world_wins)) ? Number(scoreData.world_wins) : 0;
          const draws = Number.isFinite(Number(scoreData.draws)) ? Number(scoreData.draws) : 0;
          scoreLineEl.textContent = `overall: host ${hostWins} · world ${worldWins} · draws ${draws}`;
          scoreLineEl.dataset.updatedAt = scoreData.updated_at || '';
          return;
        }
        const fallback = fallbackLine || scoreLineEl.textContent;
        if (fallback) {
          scoreLineEl.textContent = fallback;
        }
      }

      function blockForStateError(message) {
        setStatus(message, 'error');
        btnSubmit.disabled = true;
        pendingMove = null;
        pendingBaseFen = null;
        selectedSquare = null;
        resetPromotionChooser();
        movePreview.textContent = 'none';
        boardEl.classList.add('locked');
        showErrors([message]);
        try {
          game.clear();
        } catch (err) {
          // ignore
        }
        renderBoard();
      }

      function setSubmittingState(isSubmitting) {
        submitting = isSubmitting;
        btnSubmit.disabled = true;
        btnRefresh.disabled = isSubmitting;
        btnBannerRefresh.disabled = isSubmitting;
        statusSpinner.classList.toggle('show', isSubmitting);
        if (!isSubmitting && pendingMove && !selectionIsStale) {
          btnSubmit.disabled = false;
        }
        if (historyState && !isHistoryLiveView()) {
          btnSubmit.disabled = true;
        }
      }

      function formatHumanTimestamp(ts) {
        const d = new Date(ts);
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
        let body = 'No winner this time. Next game will flip the sides as usual.';
        if (gameOverState.reason === 'checkmate') {
          title = 'Checkmate';
          if (hostWon) {
            body = 'You win this one. The board resets and the sides flip for the next round.';
          } else if (worldWon) {
            body = 'The world takes it. Same board, next game—sides flip and we go again.';
          }
        } else if (gameOverState.reason === 'stalemate' || gameOverState.reason === 'draw') {
          title = 'Draw';
          body = 'No winner this time. Next game will flip the sides as usual.';
        }

        if (gameOverTitle) gameOverTitle.textContent = title;
        if (gameOverBody) gameOverBody.textContent = body;
        if (gameOverBanner) gameOverBanner.classList.add('show');
        setStatus('Game over. Waiting for next game.', 'muted');
      }

      function updateStatusMessage() {
        if (!state) return;

        if (historyState && !isHistoryLiveView()) {
          setStatus('Reviewing history — click Live to return before submitting a move.', 'muted');
          boardEl.classList.add('locked');
          return;
        }

        if (gameOverState && gameOverState.over) {
          setStatus('Game over. Waiting for next game.', 'muted');
          boardEl.classList.add('locked');
          return;
        }

        if (state.status !== 'active') {
          setStatus(`Game is ${state.status || 'inactive'}.`, 'muted');
          boardEl.classList.add('locked');
          return;
        }

        const snapshotSans = getSnapshotSans();
        const lastSan = snapshotSans.length ? snapshotSans[snapshotSans.length - 1] : '';
        const lastMoveLine = buildLastMoveText(state, lastSan);

        if (isVisitorsTurn()) {
          setStatus('Your turn. Solve CAPTCHA to submit.', 'ok');
          boardEl.classList.remove('locked');
        } else {
          const waitingMsg = lastMoveLine && lastMoveLine !== '—'
            ? `Waiting on host move. Last move: ${lastMoveLine}`
            : 'Waiting on host move.';
          setStatus(waitingMsg, 'muted');
          boardEl.classList.add('locked');
        }
      }

      function deriveLastMoveSquares(currentState) {
        if (!currentState) return null;
        if (currentState.last_move_from && currentState.last_move_to) {
          return { from: currentState.last_move_from, to: currentState.last_move_to };
        }
        if (!currentState.pgn) return null;
        try {
          const replay = new Chess();
          replay.load_pgn(currentState.pgn);
          const hist = replay.history({ verbose: true });
          if (!hist.length) return null;
          const last = hist[hist.length - 1];
          return { from: last.from, to: last.to };
        } catch (err) {
          return null;
        }
      }

      function parseSansFromPgn(pgnText) {
        if (!pgnText || !pgnText.trim()) return [];
        const parser = new Chess();
        const loaded = parser.load_pgn(pgnText, { sloppy: true });
        if (!loaded) return [];
        return parser.history();
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
        if (!historyVerbose.length) return null;
        const seq = movesToUciString(historyVerbose);
        if (!seq) return null;
        const data = await ecoLiteStore.getData();
        if (!data || !data.length) return null;
        const current = seq.trim();
        let best = null;
        for (const entry of data) {
          const moves = (entry?.moves || '').trim();
          if (!moves) continue;
          if (current === moves || current.startsWith(`${moves} `)) {
            const plies = moves.split(/\\s+/).length;
            if (!best || plies > best.matchedPlies) {
              best = { eco: entry.eco, name: entry.name, matchedPlies: plies };
            }
          }
        }
        return best;
      }

      function formatMoveCount(plies) {
        const num = Number.isFinite(plies) ? Math.max(0, plies) : 0;
        const moveNum = Math.max(0, Math.ceil(num / 2));
        return `${num} ply · move ${moveNum}`;
      }

      async function renderGameSnapshot() {
        if (!snapshotBlock) return;
        const lookupId = ++openingLookupToken;
        const context = buildSnapshotContext();
        const lastMove = context.verboseMoves[context.verboseMoves.length - 1] || null;
        const lastMoveSan = lastMove
          ? (lastMove.san || `${lastMove.from}${lastMove.to}${lastMove.promotion || ''}`)
          : '';
        const lastMoveLine = buildLastMoveText(state, lastMoveSan);
        if (snapshotPlyEl) {
          snapshotPlyEl.textContent = formatMoveCount(context.plyCount);
        }
        if (snapshotLastMoveEl) {
          snapshotLastMoveEl.textContent = lastMoveLine || '—';
        }
        if (snapshotStatusEl) {
          const toMove = context.chess.turn() === 'w' ? 'White' : 'Black';
          const statusBits = [state?.status || 'active', `${toMove} to move`];
          if (context.chess.in_check()) {
            statusBits.push('check');
          }
          snapshotStatusEl.textContent = statusBits.join(' · ');
        }
        let openingText = 'Unknown';
        try {
          const opening = await getOpeningInfo(context.chess);
          if (opening) {
            openingText = `${opening.eco} — ${opening.name}`;
          }
        } catch (err) {
          console.error('Opening lookup failed', err);
        }
        if (lookupId !== openingLookupToken) return;
        if (snapshotOpeningEl) {
          snapshotOpeningEl.textContent = openingText;
        }
      }

      function buildHistoryTimeline(initialFen, movesPgn) {
        const startFen = (initialFen && initialFen !== 'start') ? initialFen : null;
        const seed = startFen ? new Chess(startFen) : new Chess();
        const timeline = [seed.fen()];
        const historySans = [];
        const trimmed = (movesPgn || '').trim();
        if (!trimmed) return { timeline, historySans };

        const parser = startFen ? new Chess(startFen) : new Chess();
        const loaded = parser.load_pgn(trimmed, { sloppy: true });
        if (!loaded) {
          throw new Error('Invalid PGN');
        }
        historySans.push(...parser.history());
        const replay = startFen ? new Chess(startFen) : new Chess();
        historySans.forEach((san, idx) => {
          const move = replay.move(san, { sloppy: true });
          if (!move) {
            throw new Error(`Illegal move at ply ${idx + 1}: ${san}`);
          }
          timeline.push(replay.fen());
        });
        return { timeline, historySans };
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
        try {
          const historyBundle = buildHistoryTimeline(initialFen, movesPgn);
          const timeline = historyBundle?.timeline || [];
          const sans = historyBundle?.historySans || [];
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
            liveIndex,
            idx: getSelectedHistoryIndex(),
            get isLive() { return isHistoryLiveView(); },
          };
          updateHistoryUI();
          return timeline[getSelectedHistoryIndex()] || fallbackFen;
        } catch (err) {
          const message = err && err.message ? err.message : String(err);
          historyStatus.textContent = `History unavailable: ${message}`;
          historyStatus.className = 'history-chip error';
          console.error('History reconstruction failed', err);
          historyState = null;
          updateViewState({ mode: 'live', selectedPly: 0, latestPly: 0 });
          resetHistoryNotice();
          return fallbackFen;
        }
      }

      function updateHistoryUI() {
        const h = historyState;
        if (!h || !Array.isArray(h.timeline)) {
          if (btnBack) btnBack.disabled = true;
          if (btnForward) btnForward.disabled = true;
          if (btnLive) btnLive.disabled = true;
          resetHistoryNotice();
          boardEl.classList.remove('history-locked');
          if (historyStatus) {
            historyStatus.textContent = 'Live';
            historyStatus.className = 'history-chip muted';
          }
          return;
        }
        const selectedIdx = getSelectedHistoryIndex();
        const isLive = isHistoryLiveView();
        historyState.idx = selectedIdx;
        if (btnBack) btnBack.disabled = selectedIdx <= 0;
        if (btnForward) btnForward.disabled = selectedIdx >= h.timeline.length - 1;
        if (btnLive) btnLive.disabled = isLive;
        const statusText = h.timeline.length > 1
          ? (isLive ? 'Live' : `Move ${selectedIdx} of ${h.timeline.length - 1}`)
          : 'Live';
        historyStatus.textContent = statusText;
        historyStatus.className = 'history-chip';
        historyStatus.classList.toggle('muted', isLive);
        historyStatus.classList.toggle('ok', !isLive);
        if (historyNotice) {
          historyNotice.classList.toggle('show', !isLive);
        }
        boardEl.classList.toggle('history-locked', Boolean(h && !isLive));
        if (!isLive) {
          btnSubmit.disabled = true;
        }
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
        const targetFen = h.timeline[clamped];
        if (!forceRender && targetFen === game.fen()) {
          updateHistoryUI();
          updateStatusMessage();
          renderGameSnapshot();
          return;
        }
        try {
          game.load(targetFen);
        } catch (err) {
          historyStatus.textContent = `History unavailable: ${err && err.message ? err.message : err}`;
          historyStatus.className = 'history-chip error';
          console.error('History load failed', err);
          historyState = null;
          boardEl.classList.remove('history-locked');
          return;
        }
        clearSelection();
        lastMoveSquares = isHistoryLiveView() ? deriveLastMoveSquares(state) : null;
        renderBoard();
        updateStatusMessage();
        updateHistoryUI();
        renderGameSnapshot();
      }

      function applyHistoryPosition(targetIdx, { forceRender = false, mode = null, allowAutoLive = false } = {}) {
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
        fenLabel.textContent = 'FEN';
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
        fenBox.setAttribute('aria-label', 'Current FEN');
        fenBlock.appendChild(fenHeader);
        fenBlock.appendChild(fenBox);

        const pgnBlock = document.createElement('div');
        pgnBlock.className = 'notation-block';
        const pgnHeader = document.createElement('div');
        pgnHeader.className = 'notation-header';
        const pgnLabel = document.createElement('p');
        pgnLabel.className = 'notation-label muted';
        pgnLabel.textContent = 'PGN';
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
        pgnBox.setAttribute('aria-label', 'Current PGN');
        pgnBlock.appendChild(pgnHeader);
        pgnBlock.appendChild(pgnBox);

        wrapper.appendChild(fenBlock);
        wrapper.appendChild(pgnBlock);
        notationMount.appendChild(wrapper);

        notationElements = { fenBox, pgnBox, copyFenBtn, copyPgnBtn, copyFenMsg, copyPgnMsg };
        notationVisible = true;
        toggleNotationBtn.textContent = 'Hide notation';
        notationStatus.textContent = 'Notation visible';

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
              temp.value = text;
              temp.style.position = 'fixed';
              temp.style.opacity = '0';
              document.body.appendChild(temp);
              temp.select();
              document.execCommand('copy');
              document.body.removeChild(temp);
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
        toggleNotationBtn.textContent = 'Show notation';
        notationStatus.textContent = 'Notation hidden';
      }

      function setDetailsVisibility(show) {
        if (!hudDetailsPanel || !detailsToggle) return;
        hudDetailsPanel.hidden = !show;
        detailsToggle.setAttribute('aria-expanded', show ? 'true' : 'false');
      }

      function applyStateData(newState, { resetSelection = true } = {}) {
        const canonicalState = stateStore.set(newState);
        latestFetchedStateFingerprint = stateFingerprint(canonicalState);
        visitorColor = canonicalState?.visitor_color;
        hostColor = canonicalState?.you_color;
        queuedServerState = null;
        selectionIsStale = false;
        clearErrors();

        renderScoreLine(canonicalState?.score, canonicalState?.score_line);

        visitorColorLabel.textContent = visitorColor;
        hostColorLabel.textContent = hostColor;

        setNotationData({ fen: canonicalState.fen, pgn: canonicalState.pgn });

        const fenFromHistory = syncHistoryFromState(canonicalState);
        const selectedIdx = getSelectedHistoryIndex();
        const fenToLoad = (historyState && Array.isArray(historyState.timeline) && historyState.timeline[selectedIdx])
          ? historyState.timeline[selectedIdx]
          : (fenFromHistory || canonicalState.fen);
        const shouldRenderPosition = (viewState.mode === 'live') || (fenToLoad !== game.fen());

        if (shouldRenderPosition) {
          try {
            game.load(fenToLoad);
          } catch (err) {
            blockForStateError('Invalid FEN from server. Refresh later.');
            return;
          }
        }
        const liveSquares = deriveLastMoveSquares(canonicalState);
        lastMoveSquares = isHistoryLiveView() ? liveSquares : null;

        const detected = computeGameOverFromFen(fenToLoad);
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

        handlePromotionInterrupted(incomingFingerprint, { queued: allowQueue });

        if (hasChanged) {
          cancelPromotionFlow();
        }

        if (hasChanged && allowQueue && historyState && !isHistoryLiveView()) {
          queuedServerState = newState;
          selectionIsStale = true;
          setStatus('Live game updated. Click Live to return.', 'muted');
          btnSubmit.disabled = true;
          showUpdateBanner('Live game updated. Click Live to return.');
          return;
        }

        if (pendingMove && hasChanged && allowQueue) {
          queuedServerState = newState;
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
      }

      async function fetchState(options = {}) {
        const { resetSelection = true, allowQueue = false, silent = false } = options;
        if (!silent) {
          setStatus('Loading…', 'muted', { showSpinner: true });
        }

        const res = await fetch('api/state.php', { cache: 'no-store' });
        if (!res.ok) throw new Error('Failed to load state');
        const json = await res.json();
        if (!json || json.ok === false) {
          const message = (json && json.message) ? json.message : 'Failed to load state';
          throw new Error(message);
        }

        handleIncomingState(json, { resetSelection, allowQueue });
      }

      function handleStateError(err) {
        blockForStateError(err.message || 'Failed to load state');
      }

      function startPolling() {
        if (pollHandle) return;
        pollHandle = setInterval(() => {
          fetchState({ resetSelection: false, allowQueue: true, silent: true }).catch((err) => {
            console.error('Polling failed', err);
          });
        }, 20000);
      }

      function applyQueuedStateOrFetch({ forceLive = false } = {}) {
        if (forceLive) {
          updateViewState({ mode: 'live' });
        }
        cancelPromotionFlow();
        if (queuedServerState) {
          applyStateData(queuedServerState, { resetSelection: true });
          return;
        }
        fetchState({ resetSelection: true }).catch(handleStateError);
      }

      function showErrors(errors) {
        if (!errors || !errors.length) {
          clearErrors();
          return;
        }
        errorBox.textContent = errors.map((line) => `• ${line}`).join('\n');
        errorBox.classList.add('show');
      }

      renderScoreLine(appConfig.score, appConfig.score_line);
      renderBoard();
      updateHistoryUI();
      notationStatus.textContent = 'Notation hidden';

      fetchState().then(() => {
        clearErrors();
        startPolling();
      }).catch(handleStateError);

      promotionButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
          selectPromotionChoice(btn.dataset.piece);
          promotionChooser.classList.add('show');
        });
      });
      resetPromotionChooser();

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
          const h = historyState;
          const next = h ? h.liveIndex : 0;
          applyHistoryPosition(next, { forceRender: true, mode: 'live' });
        });
      }

      document.addEventListener('keydown', (ev) => {
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
          const h = historyState;
          const latest = h ? h.liveIndex : viewState.latestPly;
          applyHistoryPosition(latest, { forceRender: true, mode: 'live' });
        }
      });

      btnRefresh.addEventListener('click', applyQueuedStateOrFetch);
      btnBannerRefresh.addEventListener('click', applyQueuedStateOrFetch);
      if (gameOverRefresh) {
        gameOverRefresh.addEventListener('click', applyQueuedStateOrFetch);
      }

      btnSubmit.addEventListener('click', async () => {
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

        clearErrors();
        setSubmittingState(true);
        setStatus('Submitting…', 'muted', { showSpinner: true });

        try {
          const res = await fetch('api/visitor_move.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json'
            },
            body: JSON.stringify({
              last_move_san: pendingMove.san,
              from: pendingMove.from,
              to: pendingMove.to,
              promotion: pendingMove && pendingMove.requiresPromotion ? pendingMove.promotion : '',
              last_known_updated_at: state.updated_at,
              turnstile_token: turnstileToken
            })
          });

          let json = {};
          try {
            json = await res.json();
          } catch (parseErr) {
            json = {};
          }

          const readableErrors = [];
          if (json && typeof json === 'object') {
            if (json.error) readableErrors.push(String(json.error));
            if (json.turnstile_errors) {
              if (Array.isArray(json.turnstile_errors)) {
                json.turnstile_errors.forEach((msg) => readableErrors.push(String(msg)));
              } else {
                readableErrors.push(String(json.turnstile_errors));
              }
            }
          }

          if (!res.ok || json.error) {
            const errorMessage = readableErrors.length ? readableErrors[0] : 'Move rejected';
            const displayErrors = readableErrors.length ? readableErrors : [errorMessage];
            showErrors(displayErrors);
            throw new Error(errorMessage);
          }

          setStatus('Move accepted. Waiting on host.', 'ok');
          pendingMove = null;
          pendingBaseFen = null;
          resetPromotionChooser();
          resetTurnstile();

          await fetchState();

        } catch (err) {
          setStatus(err.message || 'Move rejected', 'error');
          resetTurnstile();
          setSubmittingState(false);
          return;
        }

        setSubmittingState(false);
      });

      if (toggleNotationBtn) {
        toggleNotationBtn.addEventListener('click', () => {
          if (notationVisible) {
            hideNotation();
          } else {
            showNotation();
          }
        });
      }

      if (detailsToggle && hudDetailsPanel) {
        setDetailsVisibility(false);
        detailsToggle.addEventListener('click', () => {
          const nextState = hudDetailsPanel.hidden;
          setDetailsVisibility(nextState);
          if (nextState) {
            const behavior = (prefersReducedMotion && prefersReducedMotion.matches) ? 'auto' : 'smooth';
            hudDetailsPanel.scrollIntoView({ block: 'nearest', behavior });
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
