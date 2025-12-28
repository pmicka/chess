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
require_once __DIR__ . '/db.php';

$tokenValue = isset($_GET['token']) ? trim($_GET['token']) : '';
$db = get_db();
$tokenRow = fetch_valid_host_token($db, $tokenValue);

if ($tokenRow) {
    $gameStmt = $db->prepare("SELECT id, status FROM games WHERE id = :id LIMIT 1");
    $gameStmt->execute([':id' => $tokenRow['game_id']]);
    $gameRow = $gameStmt->fetch(PDO::FETCH_ASSOC);

    if (!$gameRow || ($gameRow['status'] ?? '') !== 'active') {
        $tokenRow = null;
    }
}

if (!$tokenRow) {
    $latestGameStmt = $db->query("
        SELECT id, host_color, turn_color, status
        FROM games
        WHERE status = 'active'
        ORDER BY updated_at DESC
        LIMIT 1
    ");
    $latestGame = $latestGameStmt->fetch(PDO::FETCH_ASSOC);

    if ($latestGame && ($latestGame['status'] ?? '') === 'active' && $latestGame['turn_color'] === $latestGame['host_color']) {
        $freshTokenInfo = ensure_host_move_token($db, (int)$latestGame['id']);
        if (!empty($freshTokenInfo['token']) && $freshTokenInfo['token'] !== $tokenValue) {
            $params = [
                'token' => $freshTokenInfo['token'],
                'fresh' => '1',
            ];
            header('Location: ' . BASE_URL . '/my_move.php?' . http_build_query($params));
            exit;
        }
    }
}

if (!$tokenRow) {
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Host Move - Invalid Link</title>
</head>
<body>
  <p>Invalid or expired link.</p>
</body>
</html>
<?php
    exit;
}

$tokenExpiresDisplay = ($tokenRow['expires_at_dt'] instanceof DateTimeInterface)
    ? $tokenRow['expires_at_dt']->format('Y-m-d H:i:s T')
    : null;

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Host Move - Me vs the World Chess</title>
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
    .extra-actions { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; align-items: center; font-size: 14px; }
  </style>
</head>
<body>
  <div class="wrap">
    <h1>Host Move</h1>
    <?php if (isset($_GET['fresh']) && $_GET['fresh'] === '1'): ?>
      <p class="ok">Issued a fresh link for your current turn.</p>
    <?php endif; ?>
    <p class="subhead">
      You play <strong id="hostColorLabel">white</strong>. Visitors play <strong id="visitorColorLabel">black</strong>.
      Turn: <strong id="turnLabel">...</strong>.
    </p>
    <?php if ($tokenExpiresDisplay): ?>
    <p class="muted">This link expires at <strong><?php echo htmlspecialchars($tokenExpiresDisplay, ENT_QUOTES, 'UTF-8'); ?></strong>.</p>
    <?php endif; ?>

    <div class="card">
      <div class="board-container">
        <div class="board-shell">
          <div id="board" aria-label="Chess board"></div>
        </div>
      </div>
      <div class="controls">
        <button id="btnRefresh">Refresh</button>
        <button id="btnSubmit" disabled>Submit move</button>
      </div>
      <div class="status-line" aria-live="polite">
        <span id="statusSpinner" class="spinner" aria-hidden="true"></span>
        <span id="statusMsg" class="muted"></span>
        <span id="lastUpdated" class="muted">Last updated: ...</span>
      </div>
      <p class="muted selected-move">
        Selected move: <code id="movePreview">none</code>
      </p>
      <div class="extra-actions">
        <button id="btnCopyLink" type="button">Copy this link</button>
        <button id="btnResend" type="button">Resend link to my email</button>
        <span id="copyStatus" class="muted"></span>
        <span id="resendStatus" class="muted"></span>
      </div>
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

  <script src="assets/chess.min.js"></script>
  <script>
    window.hostToken = <?php echo json_encode($tokenValue); ?>;
  </script>
  <script>
    const boardEl = document.getElementById('board');
    const btnRefresh = document.getElementById('btnRefresh');
    const btnSubmit = document.getElementById('btnSubmit');
    const btnCopyLink = document.getElementById('btnCopyLink');
    const btnResend = document.getElementById('btnResend');
    const statusMsg = document.getElementById('statusMsg');
    const statusSpinner = document.getElementById('statusSpinner');
    const copyStatus = document.getElementById('copyStatus');
    const resendStatus = document.getElementById('resendStatus');
    const movePreview = document.getElementById('movePreview');
    const fenBox = document.getElementById('fenBox');
    const pgnBox = document.getElementById('pgnBox');
    const copyFenBtn = document.getElementById('copyFenBtn');
    const copyPgnBtn = document.getElementById('copyPgnBtn');
    const copyFenMsg = document.getElementById('copyFenMsg');
    const copyPgnMsg = document.getElementById('copyPgnMsg');
    const debugBox = document.getElementById('debugBox');
    const lastUpdatedEl = document.getElementById('lastUpdated');
    const visitorColorLabel = document.getElementById('visitorColorLabel');
    const hostColorLabel = document.getElementById('hostColorLabel');
    const turnLabel = document.getElementById('turnLabel');
    const hostToken = window.hostToken || '';

    const game = new Chess();
    let state = null;

    let visitorColor = 'black';
    let youColor = 'white';

    let selectedSquare = null;
    let pendingMove = null; // {from,to,promotion,san}
    let lastUpdatedTs = null;

    function setStatus(message, tone = 'muted', { showSpinner = false } = {}) {
      statusMsg.textContent = message;
      statusMsg.className = tone;
      if (statusSpinner) {
        statusSpinner.classList.toggle('show', showSpinner);
      }
    }

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

    function getLocalColor() {
      return youColor || 'white';
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
          const piece = board[8 - rank][fileIndex];

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
      movePreview.textContent = 'none';
      btnSubmit.disabled = true;
      updateHighlights();
    }

    function formatTimestamp(ts) {
      if (!ts) return 'unknown';
      const d = new Date(ts);
      if (Number.isNaN(d.getTime())) return ts;
      return d.toLocaleString();
    }

    function updateLastUpdated(displayTs, className = 'muted') {
      if (!lastUpdatedEl) return;
      lastUpdatedEl.textContent = `Last updated: ${displayTs}`;
      lastUpdatedEl.className = className;
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
      setStatus('Loading…', 'muted', { showSpinner: true });
      clearSelection();
      updateLastUpdated('Updating...', 'muted');
      btnRefresh.disabled = true;

      try {
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

        game.reset();
        let loadedFromPgn = false;
        if (state.pgn) {
          try {
            game.load_pgn(state.pgn);
            loadedFromPgn = true;
          } catch (err) {
            loadedFromPgn = false;
          }
        }

        if (!loadedFromPgn) {
          game.load(state.fen);
        }

        debugBox.textContent = `game_id=${state.id} status=${state.status} updated_at=${state.updated_at}`;
        renderBoard();

        lastUpdatedTs = state.updated_at || null;
        updateLastUpdated(formatTimestamp(lastUpdatedTs), 'muted');

        setStatus(isYourTurn() ? 'Your turn.' : 'Waiting on visitors.', isYourTurn() ? 'ok' : 'muted');
        btnSubmit.disabled = true;
        if (statusSpinner) statusSpinner.classList.remove('show');
      } catch (err) {
        setStatus(err.message || 'Failed to load state', 'error');
        updateLastUpdated('Failed to load', 'error');
        throw err;
      } finally {
        btnRefresh.disabled = false;
      }
    }

    btnRefresh.addEventListener('click', () => fetchState().catch(err => {
      setStatus(err.message || 'Failed to refresh', 'error');
      updateLastUpdated('Failed to refresh', 'error');
    }));

    btnSubmit.addEventListener('click', async () => {
      if (!hostToken) {
        setStatus('Missing token. Please use the link from your email.', 'error');
        return;
      }
      if (!pendingMove || !state) return;
      btnSubmit.disabled = true;
      setStatus('Submitting…', 'muted', { showSpinner: true });

      const payload = {
        fen: game.fen(),
        pgn: game.pgn(),
        move: pendingMove.san,
        token: hostToken,
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

        setStatus(json.message || 'Move accepted. Visitors may move now.', 'ok');
        await fetchState();
      } catch (err) {
        setStatus(err.message || 'Move rejected', 'error');
        btnSubmit.disabled = false;
      }
    });

    btnCopyLink.addEventListener('click', async () => {
      copyStatus.textContent = '';
      copyStatus.className = 'muted';
      const link = window.location.href;
      try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          await navigator.clipboard.writeText(link);
          copyStatus.textContent = 'Copied.';
          copyStatus.className = 'ok';
        } else {
          throw new Error('Clipboard unavailable');
        }
      } catch (err) {
        copyStatus.textContent = 'Copy blocked. Please copy manually.';
        copyStatus.className = 'error';
      }
    });

    btnResend.addEventListener('click', async () => {
      if (!hostToken) {
        resendStatus.textContent = 'Missing token. Use your email link.';
        resendStatus.className = 'error';
        return;
      }

      resendStatus.textContent = 'Sending...';
      resendStatus.className = 'muted';
      btnResend.disabled = true;
      try {
        const res = await fetch('api/my_move_submit.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ action: 'resend', token: hostToken }),
        });
        const json = await res.json();
        if (!res.ok || json.error || (json.ok === false)) {
          const errMsg = json.error || json.message || 'Failed.';
          throw new Error(errMsg);
        }
        resendStatus.textContent = json.message || 'Sent.';
        resendStatus.className = 'ok';
      } catch (err) {
        resendStatus.textContent = 'Failed.';
        resendStatus.className = 'error';
      } finally {
        btnResend.disabled = false;
      }
    });

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

    fetchState().catch(err => {
      setStatus(err.message || 'Failed to load', 'error');
      updateLastUpdated('Failed to load', 'error');
    });
  </script>
</body>
</html>
