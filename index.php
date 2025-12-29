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

$chessAppConfig = [
    'currentFen' => $preloadedGame['fen'] ?? null,
    'movesPgn' => $preloadedGame['pgn'] ?? '',
    'initialFen' => 'start',
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
  <script>
    window.CHESS_APP = <?= json_encode($chessAppConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  </script>
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="apple-touch-icon" sizes="180x180" href="assets/icons//apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/icons//favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/icons//favicon-16x16.png">
  <title>Me vs the World Chess</title>
  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
</head>
<body>
  <div class="wrap">
    <h1>Me vs the World Chess</h1>
    <p class="subhead">
      Visitors play <strong id="visitorColorLabel">black</strong>. Host plays <strong id="hostColorLabel">white</strong>.
      Turn: <strong id="turnLabel">…</strong>.
    </p>

    <div class="card project-intro">
      <details class="project-details">
        <summary>What is this?</summary>
        <section aria-labelledby="projectIntroHeading">
          <h2 id="projectIntroHeading">About This Project</h2>
          <svg class="sigil-mark" viewBox="0 0 64 48" role="img" aria-hidden="true" focusable="false">
            <rect x="4" y="10" width="16" height="28" fill="none" stroke="currentColor" stroke-width="2" />
            <rect x="24" y="6" width="16" height="36" fill="none" stroke="currentColor" stroke-width="2" />
            <rect x="44" y="14" width="16" height="20" fill="currentColor" opacity="0.3" />
            <path d="M8 38h48" stroke="currentColor" stroke-width="2" />
          </svg>
          <p>This is a small experiment in building things by doing them.</p>
          <p>At its core, it’s an asynchronous chess simul: one board, one game, me versus anyone who wanders in. Moves unfold slowly over time—no matchmaking, no ratings, no timers—just a shared game and a bit of patience. Sides alternate from game to game, so sometimes you’ll play White and sometimes Black.</p>
          <p>Under the hood, it’s also a learning playground. This project exists to explore how modern tools fit together in the real world: PHP and SQLite on the backend, open-source chess libraries for rules and validation, GitHub for version control, and a growing amount of help from ChatGPT along the way. Wherever possible, it leans on permissive, open licenses—Creative Commons and MIT—because learning works best when ideas can be shared, borrowed, and improved.</p>
          <p>It’s intentionally simple, occasionally imperfect, and very much a work in progress. Things may break. Features may change. That’s part of the point.</p>
          <p>In the end, though, it’s still just a chessboard on the internet.</p>
          <p>One player on one side.</p>
          <p>The rest of the world on the other.</p>
        </section>
      </details>
    </div>

    <div class="card">
      <div class="board-container">
        <div class="board-shell">
          <div id="board" aria-label="Chess board"></div>
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
      <div class="turnstile-wrap">
        <div
          class="cf-turnstile"
          data-sitekey="<?= htmlspecialchars(TURNSTILE_SITE_KEY ?? '', ENT_QUOTES) ?>"
          data-callback="onTurnstileSuccess"
          data-expired-callback="onTurnstileExpired"
          data-error-callback="onTurnstileError"
        ></div>
      </div>
      <div class="controls">
        <button id="btnRefresh">Refresh</button>
        <button id="btnSubmit" disabled>Submit move</button>
      </div>
      <div class="status-line" aria-live="polite">
        <span id="statusSpinner" class="spinner" aria-hidden="true"></span>
        <span id="statusMsg" class="muted"></span>
      </div>
      <div class="history-row">
        <div class="history-controls">
          <button id="btnBack" type="button">Back</button>
          <button id="btnForward" type="button">Forward</button>
          <button id="btnLive" type="button">Live</button>
        </div>
        <p id="historyStatus" class="muted history-status" aria-live="polite"></p>
        <div id="historyNotice" class="banner" role="status" aria-live="polite">
          <span>Reviewing history — click Live to return before submitting a move.</span>
        </div>
      </div>
      <p class="muted selected-move">
        Selected move: <code id="movePreview">none</code>
      </p>
      <div id="promotionChooser" class="promotion-chooser" aria-live="polite">
        <div class="label">Promote to:</div>
        <div class="promotion-buttons">
          <button type="button" class="promo-btn active" data-piece="q">Queen</button>
          <button type="button" class="promo-btn" data-piece="r">Rook</button>
          <button type="button" class="promo-btn" data-piece="b">Bishop</button>
          <button type="button" class="promo-btn" data-piece="n">Knight</button>
        </div>
      </div>
      <div id="updateBanner" class="banner" role="status" aria-live="polite">
        <span>New server state available. Refresh to sync.</span>
        <button id="btnBannerRefresh">Refresh</button>
      </div>
      <div id="errorBox" class="error-block" role="alert" aria-live="polite"></div>
    </div>

    <div class="card">
      <h3>Game State</h3>
      <p class="muted">FEN:</p>
      <textarea id="fenBox" readonly></textarea>
      <div class="copy-row">
        <button id="copyFenBtn">Copy FEN</button>
        <span id="copyFenMsg" class="copy-note" aria-live="polite"></span>
      </div>
      <p class="muted">PGN:</p>
      <textarea id="pgnBox" readonly></textarea>
      <div class="copy-row">
        <button id="copyPgnBtn">Copy PGN</button>
        <span id="copyPgnMsg" class="copy-note" aria-live="polite"></span>
      </div>
      <p class="muted mono" id="debugBox"></p>
      <p class="score-line" aria-live="polite"><?= htmlspecialchars($scoreLineText, ENT_QUOTES, 'UTF-8') ?></p>
    </div>
    <footer class="app-footer">
      <p class="footer-note">Code: <a href="LICENSE">MIT</a> · Pieces: <a href="assets/pieces/lichess/LICENSE.txt">Lichess CC0</a> · <a href="https://github.com/pmicka/chess/" target="_blank" rel="noopener noreferrer">source</a></p>
    </footer>
  </div>

  <script src="assets/ui_helpers.js"></script>
  <script src="assets/chess.min.js"></script>
  <script>
    // Minimal board renderer + click-to-move UI using chess.js
    const boardEl = document.getElementById('board');
    const btnRefresh = document.getElementById('btnRefresh');
    const btnSubmit = document.getElementById('btnSubmit');
    const statusMsg = document.getElementById('statusMsg');
    const statusSpinner = document.getElementById('statusSpinner');
    const movePreview = document.getElementById('movePreview');
    const fenBox = document.getElementById('fenBox');
    const pgnBox = document.getElementById('pgnBox');
    const debugBox = document.getElementById('debugBox');
    const copyFenBtn = document.getElementById('copyFenBtn');
    const copyPgnBtn = document.getElementById('copyPgnBtn');
    const copyFenMsg = document.getElementById('copyFenMsg');
    const copyPgnMsg = document.getElementById('copyPgnMsg');
    const errorBox = document.getElementById('errorBox');
    const visitorColorLabel = document.getElementById('visitorColorLabel');
    const hostColorLabel = document.getElementById('hostColorLabel');
    const turnLabel = document.getElementById('turnLabel');
    const turnstileWidget = document.querySelector('.cf-turnstile');
    const updateBanner = document.getElementById('updateBanner');
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
    const appConfig = window.CHESS_APP || {};
    boardEl.classList.add('locked');

    window.turnstileToken = null;
    const game = new Chess();
    const uiHelpers = window.ChessUI || {};
    const filesBase = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];
    let state = null;

    // visitor plays black in your JSON example; but we read it from state
    let visitorColor = 'black';
    let hostColor = 'white';

    // selection state
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
    let gameOverState = { over: false, reason: null, winner: null };
    if (appConfig.orient) {
      visitorColor = appConfig.orient;
      hostColor = appConfig.orient === 'white' ? 'black' : 'white';
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

          // File labels (top/bottom)
          if (row === 0 || row === 9) {
            const fileLabel = document.createElement('div');
            fileLabel.className = 'label';
            const fileIdx = col - 1;
            fileLabel.textContent = files[fileIdx].toUpperCase();
            boardEl.appendChild(fileLabel);
            continue;
          }

          // Rank labels (left/right)
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
          div.dataset.square = sq;
          const pieceEl = renderPieceElement(piece);
          if (pieceEl) {
            div.appendChild(pieceEl);
          }

          div.addEventListener('click', () => onSquareClick(sq));
          boardEl.appendChild(div);
        }
      }

      updateHighlights();
    }

    function resetPromotionChooser() {
      promotionChoice = 'q';
      promotionButtons.forEach((btn) => {
        btn.classList.toggle('active', btn.dataset.piece === promotionChoice);
      });
      promotionChooser.classList.remove('show');
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
        const pick = boardEl.querySelector(`.sq[data-square="${selectedSquare}"]`);
        if (pick) pick.classList.add('pick');

        // show legal destinations from selectedSquare
        const moves = game.moves({square: selectedSquare, verbose: true});
        moves.forEach(m => {
          const hint = boardEl.querySelector(`.sq[data-square="${m.to}"]`);
          if (hint) hint.classList.add('hint');
        });
      }

      if (lastMoveSquares) {
        ['from', 'to'].forEach(key => {
          const target = boardEl.querySelector(`.sq[data-square="${lastMoveSquares[key]}"]`);
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

    function selectPromotionChoice(piece) {
      const nextChoice = (piece || 'q').toLowerCase();
      promotionChoice = nextChoice;
      promotionButtons.forEach((btn) => {
        btn.classList.toggle('active', btn.dataset.piece === promotionChoice);
      });
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
      return `${obj.updated_at ?? ''}|${obj.fen ?? ''}`;
    }

    function showUpdateBanner() {
      updateBanner.classList.add('show');
    }

    function hideUpdateBanner() {
      updateBanner.classList.remove('show');
      queuedServerState = null;
    }

    function onSquareClick(sq) {
      if (!state) return;
      if (window.__history && !window.__history.isLive) {
        setStatus('Reviewing history — click Live to return before submitting a move.', 'muted');
        return;
      }

      if (pendingMove && !selectedSquare) {
        clearSelection({ restore: true });
      }

      if (!isVisitorsTurn()) {
        return;
      }

      const turn = game.turn(); // 'w' or 'b'
      const visitorTurnChar = (visitorColor === 'white') ? 'w' : 'b';
      if (turn !== visitorTurnChar) {
        statusMsg.textContent = 'Local state mismatch. Hit Refresh.';
        return;
      }

      const piece = game.get(sq);

      // If nothing selected yet: only allow selecting your own piece
      if (!selectedSquare) {
        if (!piece) return;
        if (piece.color !== visitorTurnChar) return;
        selectedSquare = sq;
        updateHighlights();
        return;
      }

      // If selecting a different own piece, switch selection
      if (piece && piece.color === visitorTurnChar) {
        selectedSquare = sq;
        updateHighlights();
        return;
      }

      const promoInfo = promotionInfoForMove(selectedSquare, sq);
      const promoOptions = promoInfo.moves.map((m) => m.promotion).filter(Boolean);
      let promoToUse = promotionChoice;
      if (promoInfo.isPromotion) {
        if (!promoOptions.includes(promoToUse)) {
          promoToUse = promoOptions[0] || 'q';
        }
      } else {
        resetPromotionChooser();
      }

      if (!pendingBaseFen) {
        pendingBaseFen = game.fen();
      }

      // Attempt move
      const move = game.move({
        from: selectedSquare,
        to: sq,
        ...(promoInfo.isPromotion ? { promotion: promoToUse } : {}),
      });
      if (!move) {
        // illegal move
        if (pendingBaseFen) {
          try { game.load(pendingBaseFen); } catch (err) { /* ignore */ }
        }
        pendingBaseFen = null;
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
        promotionChooser.classList.add('show');
        promotionButtons.forEach((btn) => {
          btn.classList.toggle('active', btn.dataset.piece === pendingMove.promotion);
        });
        promotionChoice = pendingMove.promotion;
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
      if (window.turnstileToken) return true;
      setStatus('Solve the CAPTCHA before submitting.', 'error');
      btnSubmit.disabled = true;
      return false;
    }

    function onTurnstileSuccess(token) {
      window.turnstileToken = token;
    }

    function onTurnstileExpired() {
      window.turnstileToken = null;
    }

    function onTurnstileError() {
      window.turnstileToken = null;
    }

    function resetTurnstile() {
      if (window.turnstile && turnstileWidget) {
        window.turnstile.reset(turnstileWidget);
      }
      window.turnstileToken = null;
    }

    function setStatus(message, tone = 'muted', { showSpinner = false } = {}) {
      statusMsg.textContent = message;
      statusMsg.className = tone;
      statusSpinner.classList.toggle('show', showSpinner);
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
      if (window.__history && !window.__history.isLive) {
        btnSubmit.disabled = true;
      }
    }

    function formatTimestamp(ts) {
      const d = new Date(ts);
      if (Number.isNaN(d.getTime())) return ts || '';
      const pad = (n) => n.toString().padStart(2, '0');
      return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
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
          const turn = checker.turn(); // side to move is checkmated
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

      if (window.__history && !window.__history.isLive) {
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

      if (isVisitorsTurn()) {
        setStatus(`Your turn (${visitorColor}). Solve CAPTCHA to submit.`, 'ok');
        boardEl.classList.remove('locked');
      } else {
        setStatus(`Waiting on host move (${hostColor}). Last updated: ${formatTimestamp(state.updated_at)}`, 'muted');
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

    function buildHistoryTimeline(initialFen, movesPgn) {
      const startFen = (initialFen && initialFen !== 'start') ? initialFen : null;
      const seed = startFen ? new Chess(startFen) : new Chess();
      const timeline = [seed.fen()];
      const trimmed = (movesPgn || '').trim();
      if (!trimmed) return timeline;

      const parser = startFen ? new Chess(startFen) : new Chess();
      const loaded = parser.load_pgn(trimmed, { sloppy: true });
      if (!loaded) {
        throw new Error('Invalid PGN');
      }
      const historySans = parser.history();
      const replay = startFen ? new Chess(startFen) : new Chess();
      historySans.forEach((san, idx) => {
        const move = replay.move(san, { sloppy: true });
        if (!move) {
          throw new Error(`Illegal move at ply ${idx + 1}: ${san}`);
        }
        timeline.push(replay.fen());
      });
      return timeline;
    }

    function resetHistoryNotice() {
      historyNotice?.classList.remove('show');
    }

    function syncHistoryFromState(currentState) {
      const initialFen = appConfig.initialFen || 'start';
      const movesPgn = (currentState && typeof currentState.pgn === 'string')
        ? currentState.pgn
        : (appConfig.movesPgn || '');
      const fallbackFen = (currentState && currentState.fen) ? currentState.fen : (appConfig.currentFen || null);
      try {
        const timeline = buildHistoryTimeline(initialFen, movesPgn);
        const liveIndex = Math.max(0, timeline.length - 1);
        window.__history = {
          timeline,
          idx: liveIndex,
          liveIndex,
          get isLive() { return this.idx === this.liveIndex; },
        };
        historyStatus.textContent = 'Live';
        historyStatus.className = 'muted';
        resetHistoryNotice();
        return timeline[liveIndex] || fallbackFen;
      } catch (err) {
        const message = err && err.message ? err.message : String(err);
        historyStatus.textContent = `History unavailable: ${message}`;
        historyStatus.className = 'error';
        console.error('History reconstruction failed', err);
        window.__history = null;
        resetHistoryNotice();
        return fallbackFen;
      }
    }

    function updateHistoryUI() {
      const h = window.__history;
      if (!h || !Array.isArray(h.timeline)) {
        if (btnBack) btnBack.disabled = true;
        if (btnForward) btnForward.disabled = true;
        if (btnLive) btnLive.disabled = true;
        resetHistoryNotice();
        return;
      }
      if (btnBack) btnBack.disabled = h.idx <= 0;
      if (btnForward) btnForward.disabled = h.idx >= h.timeline.length - 1;
      if (btnLive) btnLive.disabled = h.isLive;
      const statusText = h.timeline.length > 1
        ? (h.isLive ? 'Live' : `Move ${h.idx} of ${h.timeline.length - 1}`)
        : 'Live';
      historyStatus.textContent = statusText;
      historyStatus.className = h.isLive ? 'muted' : 'ok';
      if (historyNotice) {
        historyNotice.classList.toggle('show', !h.isLive);
      }
      boardEl.classList.toggle('history-locked', Boolean(h && !h.isLive));
      if (!h.isLive) {
        btnSubmit.disabled = true;
      }
    }

    function applyHistoryPosition(targetIdx, { forceRender = false } = {}) {
      const h = window.__history;
      if (!h || !Array.isArray(h.timeline) || !h.timeline.length) {
        return;
      }
      const clamped = Math.max(0, Math.min(targetIdx, h.timeline.length - 1));
      if (!forceRender && clamped === h.idx) {
        updateHistoryUI();
        return;
      }
      h.idx = clamped;
      const targetFen = h.timeline[clamped];
      try {
        game.load(targetFen);
      } catch (err) {
        historyStatus.textContent = `History unavailable: ${err && err.message ? err.message : err}`;
        historyStatus.className = 'error';
        console.error('History load failed', err);
        window.__history = null;
        boardEl.classList.remove('history-locked');
        return;
      }
      clearSelection();
      lastMoveSquares = null;
      renderBoard();
      updateStatusMessage();
      updateHistoryUI();
    }

    function applyStateData(newState, { resetSelection = true } = {}) {
      state = newState;
      latestFetchedStateFingerprint = stateFingerprint(newState);
      visitorColor = state.visitor_color;
      hostColor = state.you_color;
      queuedServerState = null;
      selectionIsStale = false;
      clearErrors();

      visitorColorLabel.textContent = visitorColor;
      hostColorLabel.textContent = hostColor;
      turnLabel.textContent = state.turn_color;

      fenBox.value = state.fen;
      pgnBox.value = state.pgn;

      const fenFromHistory = syncHistoryFromState(state);
      const fenToLoad = fenFromHistory || state.fen;

      try {
        game.load(fenToLoad);
      } catch (err) {
        blockForStateError('Invalid FEN from server. Refresh later.');
        return;
      }
      lastMoveSquares = deriveLastMoveSquares(state);

      const detected = computeGameOverFromFen(fenToLoad);
      const forcedOver = state.status && state.status !== 'active';
      applyGameOverUI({
        over: forcedOver || detected.over,
        reason: detected.reason || (forcedOver ? 'finished' : null),
        winner: detected.winner || null,
      });

      debugBox.textContent = `game_id=${state.id} status=${state.status} updated_at=${state.updated_at}`;
      renderBoard();

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
    }

    function handleIncomingState(newState, { resetSelection = true, allowQueue = false } = {}) {
      latestFetchedStateFingerprint = stateFingerprint(newState);
      const currentFingerprint = stateFingerprint(state);
      const hasChanged = !currentFingerprint || currentFingerprint !== latestFetchedStateFingerprint;

      if (pendingMove && hasChanged && allowQueue) {
        queuedServerState = newState;
        selectionIsStale = true;
        setStatus('New server state available. Refresh to sync.', 'muted');
        btnSubmit.disabled = true;
        showUpdateBanner();
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
      }, 15000);
    }

    function applyQueuedStateOrFetch() {
      if (queuedServerState) {
        applyStateData(queuedServerState, { resetSelection: true });
        return;
      }
      fetchState({ resetSelection: true }).catch(handleStateError);
    }

    // Bootstrap from server-rendered values so the board is not empty before the first fetch.
    const bootstrapFen = syncHistoryFromState({
      fen: appConfig.currentFen || undefined,
      pgn: appConfig.movesPgn || '',
    });
    if (bootstrapFen) {
      try {
        game.load(bootstrapFen);
        renderBoard();
      } catch (err) {
        console.error('Initial history bootstrap failed', err);
      }
    }
    updateHistoryUI();

    // Load state on first render so visitors see the board immediately.
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
        const h = window.__history;
        const next = h ? h.idx - 1 : 0;
        applyHistoryPosition(next);
      });
    }
    if (btnForward) {
      btnForward.addEventListener('click', () => {
        const h = window.__history;
        const next = h ? h.idx + 1 : 0;
        applyHistoryPosition(next);
      });
    }
    if (btnLive) {
      btnLive.addEventListener('click', () => {
        const h = window.__history;
        const next = h ? h.liveIndex : 0;
        applyHistoryPosition(next, { forceRender: true });
      });
    }

    btnRefresh.addEventListener('click', applyQueuedStateOrFetch);
    btnBannerRefresh.addEventListener('click', applyQueuedStateOrFetch);
    if (gameOverRefresh) {
      gameOverRefresh.addEventListener('click', applyQueuedStateOrFetch);
    }

    btnSubmit.addEventListener('click', async () => {
      if (!pendingMove || !state) return;
      if (window.__history && !window.__history.isLive) {
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
            turnstile_token: window.turnstileToken,
            client_fen: game.fen()
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

        // Refresh canonical state from server
        await fetchState();

      } catch (err) {
        setStatus(err.message || 'Move rejected', 'error');
        resetTurnstile();
        setSubmittingState(false);
        return;
      }

      setSubmittingState(false);
    });

    function showErrors(errors) {
      if (!errors || !errors.length) {
        clearErrors();
        return;
      }
      errorBox.textContent = errors.map((line) => `• ${line}`).join('\n');
      errorBox.classList.add('show');
    }

    function clearCopyNotes() {
      copyFenMsg.textContent = '';
      copyPgnMsg.textContent = '';
    }

    async function copyText(value, targetMsgEl) {
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
    }

    copyFenBtn.addEventListener('click', () => copyText(fenBox.value, copyFenMsg));
    copyPgnBtn.addEventListener('click', () => copyText(pgnBox.value, copyPgnMsg));
    updateHistoryUI();

  </script>
</body>
</html>
