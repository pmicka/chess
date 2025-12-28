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
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Me vs the World Chess</title>
  <style>
    :root {
      --border: #e2e5e9;
      --card-radius: 12px;
      --card-shadow: 0 4px 12px rgba(0,0,0,0.04);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      background: #f7f7f8;
      color: #111;
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
    }
    .wrap {
      max-width: 760px;
      margin: 0 auto;
      padding: 20px 16px 40px;
    }
    h1 { margin: 0 0 8px; }
    .subhead { margin: 0 0 14px; color: #555; }
    .card {
      background: #fff;
      border: 1px solid var(--border);
      border-radius: var(--card-radius);
      padding: 16px;
      box-shadow: var(--card-shadow);
    }
    .card + .card { margin-top: 16px; }
    .board-container {
      width: 100%;
      max-width: 560px;
      margin: 0 auto;
    }
    .board-shell { position: relative; width: 100%; aspect-ratio: 1 / 1; }
    .board-shell::before { content: ''; display: block; padding-bottom: 100%; }
    @supports (aspect-ratio: 1 / 1) {
      .board-shell::before { display: none; padding-bottom: 0; }
    }
    #board { position: absolute; inset: 0; width: 100%; height: 100%; display: grid; grid-template-columns: repeat(10, 1fr); grid-template-rows: repeat(10, 1fr); border: 2px solid #111; border-radius: 10px; overflow: hidden; }
    .sq { display:flex; align-items:center; justify-content:center; font-size: 28px; user-select: none; }
    .label { display:flex; align-items:center; justify-content:center; font-size: 12px; color: #555; background: #f3f4f6; }
    .corner { background: transparent; }
    .light { background: #f0d9b5; }
    .dark  { background: #b58863; }
    .sq.pick { outline: 3px solid #0a84ff; outline-offset: -3px; }
    .sq.hint { box-shadow: inset 0 0 0 4px rgba(10,132,255,.35); }
    .sq.last { box-shadow: inset 0 0 0 4px rgba(255, 215, 0, 0.9); }
    #board.locked .sq { pointer-events: none; }
    .piece-svg { width: 80%; height: 80%; pointer-events: none; }
    button { padding: 10px 14px; border-radius: 10px; border: 1px solid #111; background: #111; color: #fff; cursor: pointer; font-size: 14px; }
    button:disabled { opacity: .4; cursor: not-allowed; }
    .controls { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
    .status-line { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 8px; font-size: 14px; }
    .selected-move { margin-top: 8px; font-size: 14px; }
    code { background: #f6f6f6; padding: 2px 6px; border-radius: 6px; }
    .muted { color: #555; }
    .error { color: #b00020; }
    .ok { color: #0a7d2c; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    textarea { width: 100%; min-height: 96px; font-family: ui-monospace, monospace; font-size: 12px; border-radius: 10px; border: 1px solid var(--border); padding: 10px; background: #f9fafb; }
    .copy-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 8px; }
    .copy-note { min-width: 70px; color: #0a7d2c; }
    .banner { display: none; margin-top: 12px; padding: 12px; background: #fff4ce; border: 1px solid #f0ad4e; border-radius: 10px; }
    .banner.show { display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; }
    .spinner {
      width: 16px;
      height: 16px;
      border: 2px solid #ddd;
      border-top-color: #111;
      border-radius: 50%;
      animation: spin 0.8s linear infinite;
      display: none;
    }
    .spinner.show { display: inline-block; }
    @keyframes spin { to { transform: rotate(360deg); } }
    .error-block {
      display: none;
      margin-top: 12px;
      padding: 12px;
      background: #fff0f0;
      border: 1px solid #f0b3b3;
      border-radius: 10px;
      color: #a0001f;
      white-space: pre-line;
    }
    .error-block.show { display: block; }
    .turnstile-wrap { margin-top: 12px; }
  </style>
  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
</head>
<body>
  <div class="wrap">
    <h1>Me vs the World Chess</h1>
    <p class="subhead">
      Visitors play <strong id="visitorColorLabel">black</strong>. Host plays <strong id="hostColorLabel">white</strong>.
      Turn: <strong id="turnLabel">…</strong>.
    </p>

    <div class="card">
      <div class="board-container">
        <div class="board-shell">
          <div id="board" aria-label="Chess board"></div>
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
      <p class="muted selected-move">
        Selected move: <code id="movePreview">none</code>
      </p>
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
    </div>
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
    let pendingMove = null; // {from,to,promotion?}
    let lastMoveSquares = null; // {from,to}
    let selectionStateFingerprint = null;
    let latestFetchedStateFingerprint = null;
    let queuedServerState = null;
    let selectionIsStale = false;
    let pollHandle = null;
    let submitting = false;

    const renderPiece = (p) => {
      if (!p) return '';
      const isWhite = p.color === 'w';
      const fill = isWhite ? '#f7f7f7' : '#2f2f2f';
      const stroke = '#5a5a5a';
      return `
        <svg class="piece-svg" viewBox="0 0 100 100" aria-hidden="true">
          <circle cx="50" cy="50" r="36" fill="${fill}" stroke="${stroke}" stroke-width="4"></circle>
        </svg>
      `;
    };

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
          div.innerHTML = renderPiece(piece);

          div.addEventListener('click', () => onSquareClick(sq));
          boardEl.appendChild(div);
        }
      }

      updateHighlights();
    }
    function clearSelection() {
      selectedSquare = null;
      pendingMove = null;
      selectionStateFingerprint = null;
      selectionIsStale = false;
      movePreview.textContent = 'none';
      btnSubmit.disabled = true;
      updateHighlights();
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

      // Attempt move
      const move = game.move({ from: selectedSquare, to: sq, promotion: 'q' }); // auto-queen for MVP
      if (!move) {
        // illegal move
        return;
      }

      pendingMove = { from: move.from, to: move.to, promotion: move.promotion || 'q', san: move.san };
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
      selectedSquare = null;
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
    }

    function formatTimestamp(ts) {
      const d = new Date(ts);
      if (Number.isNaN(d.getTime())) return ts || '';
      const pad = (n) => n.toString().padStart(2, '0');
      return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
    }

    function updateStatusMessage() {
      if (!state) return;

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

      try {
        game.load(state.fen);
      } catch (err) {
        blockForStateError('Invalid FEN from server. Refresh later.');
        return;
      }
      lastMoveSquares = deriveLastMoveSquares(state);

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

    // Load state on first render so visitors see the board immediately.
    fetchState().then(() => {
      clearErrors();
      startPolling();
    }).catch(handleStateError);

    btnRefresh.addEventListener('click', applyQueuedStateOrFetch);
    btnBannerRefresh.addEventListener('click', applyQueuedStateOrFetch);

    btnSubmit.addEventListener('click', async () => {
      if (!pendingMove || !state) return;
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
            promotion: pendingMove.promotion || 'q',
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

  </script>
</body>
</html>
