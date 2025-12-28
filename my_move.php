<?php
/**
 * Me vs the World Chess - Host Move Page (Token-Gated)
 *
 * Intent:
 * - This page is for the host to play their move when notified by email.
 * - Access is granted via a single-use token included in the email link.
 * - No CAPTCHA is required here because the token is the security boundary.
 *
 * High-level flow:
 * 1) Host visits /my_move.php?token=...
 * 2) Page loads current state from /api/state.php for display.
 * 3) Host makes a move (client-side chess.js legality checks).
 * 4) Submit to /api/my_move_submit.php along with token, updated FEN/PGN, and move notation.
 *
 * Security model:
 * - Server must validate token: exists, purpose, not used, not expired.
 * - Server must validate it is the host's turn and game is active.
 *
 * Game lifecycle:
 * - Host may optionally end a game (checkmate/stalemate/resign workflow later)
 * - Ending a game starts a new one and flips host color for the next game.
 */
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Host Move - Me vs the World Chess</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 24px; }
    .wrap { max-width: 980px; margin: 0 auto; }
    .row { display: flex; gap: 24px; flex-wrap: wrap; }
    .card { border: 1px solid #ddd; border-radius: 12px; padding: 16px; }
    #board { width: 360px; height: 360px; display: grid; grid-template-columns: repeat(8, 1fr); border: 2px solid #111; }
    .sq { display:flex; align-items:center; justify-content:center; font-size: 28px; user-select: none; }
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
</head>
<body>
  <div class="wrap">
    <h1>Host Move</h1>
    <p class="muted">
      You play <strong id="hostColorLabel">white</strong>. Visitors play <strong id="visitorColorLabel">black</strong>.
      Turn: <strong id="turnLabel">...</strong>.
    </p>

    <div class="row">
      <div class="card">
        <div id="board" aria-label="Chess board"></div>
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

    const game = new Chess();
    let state = null;

    let visitorColor = 'black';
    let youColor = 'white';

    let selectedSquare = null;
    let pendingMove = null; // {from,to,promotion,san}

    const pieceToChar = (p) => {
      const map = {
       'p':'p','r':'r','n':'n','b':'b','q':'q','k':'k',
       'P':'P','R':'R','N':'N','B':'B','Q':'Q','K':'K'
      };
      const key = (p.color === 'w') ? p.type.toUpperCase() : p.type;
      return map[key] || '?';
    };

    function algebraic(file, rank) { return file + rank; }

    function renderBoard() {
      boardEl.innerHTML = '';

      const board = game.board();
      const files = ['a','b','c','d','e','f','g','h'];

      for (let r = 8; r >= 1; r--) {
        for (let f = 0; f < 8; f++) {
          const file = files[f];
          const sq = algebraic(file, r);
          const piece = board[8 - r][f];

          const div = document.createElement('div');
          div.className = 'sq ' + (((f + r) % 2 === 0) ? 'light' : 'dark');
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

        const moves = game.moves({square: selectedSquare, verbose: true});
        moves.forEach(m => {
          const hint = boardEl.querySelector(`.sq[data-square="${m.to}"]`);
          if (hint) hint.classList.add('hint');
        });
      }
    }

    function isYourTurn() {
      if (!state) return false;
      return state.turn_color === youColor && state.status === 'active';
    }

    function onSquareClick(sq) {
      statusMsg.textContent = '';
      if (!state) return;

      if (!isYourTurn()) {
        statusMsg.textContent = 'Not your turn yet. Refresh for updates.';
        statusMsg.className = 'muted';
        return;
      }

      const turn = game.turn(); // 'w' or 'b'
      const yourTurnChar = (youColor === 'white') ? 'w' : 'b';
      if (turn !== yourTurnChar) {
        statusMsg.textContent = 'Local state mismatch. Hit Refresh.';
        statusMsg.className = 'error';
        return;
      }

      const piece = game.get(sq);

      if (!selectedSquare) {
        if (!piece || piece.color !== yourTurnChar) return;
        selectedSquare = sq;
        updateHighlights();
        return;
      }

      if (piece && piece.color === yourTurnChar) {
        selectedSquare = sq;
        updateHighlights();
        return;
      }

      const move = game.move({ from: selectedSquare, to: sq, promotion: 'q' });
      if (!move) {
        return;
      }

      pendingMove = { from: move.from, to: move.to, promotion: move.promotion || 'q', san: move.san };
      movePreview.textContent = `${move.san} (${move.from}->${move.to})`;
      btnSubmit.disabled = false;
      selectedSquare = null;
      renderBoard();
    }

    async function fetchState() {
      statusMsg.textContent = 'Loading...';
      statusMsg.className = 'muted';
      clearSelection();

      const res = await fetch('api/state.php', { cache: 'no-store' });
      if (!res.ok) throw new Error('Failed to load state');
      const json = await res.json();

      state = json;

      visitorColor = state.visitor_color;
      youColor = state.you_color;

      visitorColorLabel.textContent = visitorColor;
      hostColorLabel.textContent = youColor;
      turnLabel.textContent = state.turn_color;

      fenBox.value = state.fen;
      pgnBox.value = state.pgn;

      game.load(state.fen);

      debugBox.textContent = `game_id=${state.id} status=${state.status} updated_at=${state.updated_at}`;
      renderBoard();

      statusMsg.textContent = isYourTurn() ? 'Your turn.' : 'Waiting on visitors.';
      statusMsg.className = isYourTurn() ? 'ok' : 'muted';
      btnSubmit.disabled = true;
    }

    btnRefresh.addEventListener('click', () => fetchState().catch(err => {
      statusMsg.textContent = err.message;
      statusMsg.className = 'error';
    }));

    btnSubmit.addEventListener('click', async () => {
      if (!pendingMove || !state) return;
      btnSubmit.disabled = true;
      statusMsg.textContent = 'Submitting...';
      statusMsg.className = 'muted';

      const payload = {
        fen: game.fen(),
        pgn: game.pgn(),
        move: pendingMove.san,
      };

      try {
        const res = await fetch('api/my_move_submit.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload),
        });

        const json = await res.json();
        if (!res.ok || json.error) {
          throw new Error(json.error || 'Move rejected');
        }

        statusMsg.textContent = json.message || 'Move accepted. Visitors may move now.';
        statusMsg.className = 'ok';
        await fetchState();
      } catch (err) {
        statusMsg.textContent = err.message;
        statusMsg.className = 'error';
        btnSubmit.disabled = false;
      }
    });

    fetchState().catch(err => {
      statusMsg.textContent = err.message;
      statusMsg.className = 'error';
    });
  </script>
</body>
</html>
