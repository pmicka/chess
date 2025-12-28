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
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 24px; }
    .wrap { max-width: 980px; margin: 0 auto; }
    .row { display: flex; gap: 24px; flex-wrap: wrap; }
    .card { border: 1px solid #ddd; border-radius: 12px; padding: 16px; }
    .board-container { max-width: 520px; width: min(90vw, 520px); }
    .board-shell { position: relative; width: 100%; aspect-ratio: 1 / 1; }
    .board-shell::before { content: ''; display: block; padding-bottom: 100%; }
    @supports (aspect-ratio: 1 / 1) {
      .board-shell::before { display: none; padding-bottom: 0; }
    }
    #board { position: absolute; inset: 0; width: 100%; height: 100%; display: grid; grid-template-columns: repeat(10, 1fr); grid-template-rows: repeat(10, 1fr); border: 2px solid #111; border-radius: 8px; overflow: hidden; }
    .sq { display:flex; align-items:center; justify-content:center; font-size: 28px; user-select: none; }
    .label { display:flex; align-items:center; justify-content:center; font-size: 12px; color: #555; background: #f8f8f8; }
    .corner { background: transparent; }
    .light { background: #f0d9b5; }
    .dark  { background: #b58863; }
    .sq.pick { outline: 3px solid #0a84ff; outline-offset: -3px; }
    .sq.hint { box-shadow: inset 0 0 0 4px rgba(10,132,255,.35); }
    button { padding: 10px 14px; border-radius: 10px; border: 1px solid #333; background: #111; color: #fff; cursor: pointer; }
    button:disabled { opacity: .4; cursor: not-allowed; }
    code { background: #f6f6f6; padding: 2px 6px; border-radius: 6px; }
    .muted { color: #555; }
    .error { color: #b00020; }
    .ok { color: #0a7d2c; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    textarea { width: 100%; min-height: 90px; font-family: ui-monospace, monospace; font-size: 12px; }
  </style>
  <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>
</head>
<body>
  <div class="wrap">
    <h1>Me vs the World Chess</h1>
    <p class="muted">
      Visitors play <strong id="visitorColorLabel">black</strong>. Host plays <strong id="hostColorLabel">white</strong>.
      Turn: <strong id="turnLabel">…</strong>.
    </p>

    <div class="row">
      <div class="card">
        <div class="board-container">
          <div class="board-shell">
            <div id="board" aria-label="Chess board"></div>
          </div>
        </div>
        <div style="margin-top:12px;">
          <div
            class="cf-turnstile"
            data-sitekey="<?= htmlspecialchars(TURNSTILE_SITE_KEY ?? '', ENT_QUOTES) ?>"
            data-callback="onTurnstileSuccess"
            data-expired-callback="onTurnstileExpired"
            data-error-callback="onTurnstileError"
          ></div>
        </div>
        <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
          <button id="btnRefresh">Refresh</button>
          <button id="btnSubmit" disabled>Submit move</button>
          <span id="statusMsg" class="muted"></span>
        </div>
        <p class="muted" style="margin-top:10px;">
          Selected move: <code id="movePreview">none</code>
        </p>
      </div>

      <div class="card" style="flex:1; min-width: 320px;">
        <h3>Game State</h3>
        <p class="muted">FEN:</p>
        <textarea id="fenBox" readonly></textarea>
        <p class="muted">PGN:</p>
        <textarea id="pgnBox" readonly></textarea>
        <p class="muted mono" id="debugBox"></p>
      </div>
    </div>
  </div>

  <script src="assets/chess.min.js"></script>
  <script>
    // Minimal board renderer + click-to-move UI using chess.js
    const boardEl = document.getElementById('board');
    const btnRefresh = document.getElementById('btnRefresh');
    const btnSubmit = document.getElementById('btnSubmit');
    const statusMsg = document.getElementById('statusMsg');
    const movePreview = document.getElementById('movePreview');
    const fenBox = document.getElementById('fenBox');
    const pgnBox = document.getElementById('pgnBox');
    const debugBox = document.getElementById('debugBox');
    const visitorColorLabel = document.getElementById('visitorColorLabel');
    const hostColorLabel = document.getElementById('hostColorLabel');
    const turnLabel = document.getElementById('turnLabel');
    const turnstileWidget = document.querySelector('.cf-turnstile');

    window.turnstileToken = null;
    const game = new Chess();
    let state = null;

    // visitor plays black in your JSON example; but we read it from state
    let visitorColor = 'black';
    let hostColor = 'white';

    // selection state
    let selectedSquare = null;
    let pendingMove = null; // {from,to,promotion?}

    const pieceToChar = (p) => {
      // Using simple unicode pieces. p: {type, color} from chess.js board()
      const map = {
        'p':'♟','r':'♜','n':'♞','b':'♝','q':'♛','k':'♚',
        'P':'♙','R':'♖','N':'♘','B':'♗','Q':'♕','K':'♔'
      };
      const key = (p.color === 'w') ? p.type.toUpperCase() : p.type;
      return map[key] || '?';
    };

    function algebraic(file, rank) { return file + rank; }

    function getLocalColor() {
      return visitorColor || 'black';
    }

    function getFileOrder() {
      const files = ['a','b','c','d','e','f','g','h'];
      return getLocalColor() === 'black' ? [...files].reverse() : files;
    }

    function getRankOrder() {
      const ranks = [1,2,3,4,5,6,7,8];
      return getLocalColor() === 'black' ? ranks : [...ranks].reverse();
    }

    function renderBoard() {
      boardEl.innerHTML = '';

      const board = game.board();
      const filesBase = ['a','b','c','d','e','f','g','h'];
      const files = getFileOrder();
      const ranks = getRankOrder();

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
          const piece = board[8 - rank][fileIndex];

          const div = document.createElement('div');
          div.className = 'sq ' + (((fileIndex + rank) % 2 === 0) ? 'light' : 'dark');
          div.dataset.square = sq;
          div.textContent = piece ? pieceToChar(piece) : '';

          div.addEventListener('click', () => onSquareClick(sq));
          boardEl.appendChild(div);
        }
      }

      updateHighlights();
    }

    function clearSelection() {
      selectedSquare = null;
      pendingMove = null;
      movePreview.textContent = 'none';
      btnSubmit.disabled = true;
      updateHighlights();
    }

    function updateHighlights() {
      const squares = boardEl.querySelectorAll('.sq');
      squares.forEach(el => {
        el.classList.remove('pick');
        el.classList.remove('hint');
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
    }

    function isVisitorsTurn() {
      if (!state) return false;
      return state.turn_color === visitorColor && state.status === 'active';
    }

    function onSquareClick(sq) {
      statusMsg.textContent = '';
      if (!state) return;

      if (!isVisitorsTurn()) {
        statusMsg.textContent = 'Not your turn. Refresh to see if the host has moved.';
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
      movePreview.textContent = `${move.san} (${move.from}→${move.to})`;
      btnSubmit.disabled = false;
      selectedSquare = null;
      renderBoard();
    }

    function ensureTokenPresent() {
      if (window.turnstileToken) return true;
      statusMsg.textContent = 'Solve the CAPTCHA before submitting.';
      statusMsg.className = 'error';
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

    async function fetchState() {
      statusMsg.textContent = 'Loading…';
      clearSelection();

      const res = await fetch('api/state.php', { cache: 'no-store' });
      if (!res.ok) throw new Error('Failed to load state');
      const json = await res.json();

      // Your current endpoint returns the flat object, not wrapped in {ok:true}
      state = json;

      visitorColor = state.visitor_color;
      hostColor = state.you_color;

      visitorColorLabel.textContent = visitorColor;
      hostColorLabel.textContent = hostColor;
      turnLabel.textContent = state.turn_color;

      fenBox.value = state.fen;
      pgnBox.value = state.pgn;

      game.load(state.fen);

      debugBox.textContent = `game_id=${state.id} status=${state.status} updated_at=${state.updated_at}`;
      renderBoard();

      statusMsg.textContent = isVisitorsTurn() ? 'Your turn.' : 'Waiting on host.';
      statusMsg.className = isVisitorsTurn() ? 'ok' : 'muted';

      // Disable submit unless a move is pending
      btnSubmit.disabled = true;
    }

    function handleStateError(err) {
      statusMsg.textContent = err.message || 'Failed to load state';
      statusMsg.className = 'error';
    }

    // Load state on first render so visitors see the board immediately.
    fetchState().catch(handleStateError);

    btnRefresh.addEventListener('click', () => fetchState().catch(handleStateError));

btnSubmit.addEventListener('click', async () => {
  if (!pendingMove || !state) return;
  if (!ensureTokenPresent()) return;

  btnSubmit.disabled = true;
  statusMsg.textContent = 'Submitting...';

  try {
    const res = await fetch('api/visitor_move.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        fen: game.fen(),
        pgn: game.pgn(),
      last_move_san: pendingMove.san,
      turnstile_token: window.turnstileToken
    })
  });

    const json = await res.json();

    if (!res.ok || json.error) {
      throw new Error(json.error || 'Move rejected');
    }

    statusMsg.textContent = 'Move accepted. Waiting on host.';
    pendingMove = null;
    resetTurnstile();

    // Refresh canonical state from server
    await fetchState();

  } catch (err) {
    statusMsg.textContent = err.message;
    statusMsg.className = 'error';
    resetTurnstile();
    btnSubmit.disabled = false;
  }
});

  </script>
</body>
</html>
