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
require_once __DIR__ . '/lib/score.php';

log_db_path_info('my_move.php');

$tokenValue = '';
if (isset($_GET['token'])) {
    $tokenValue = trim((string)$_GET['token']);
} elseif (isset($_GET['t'])) {
    $tokenValue = trim((string)$_GET['t']);
}
$db = null;
try {
    $db = get_db();
} catch (Throwable $e) {
    http_response_code(503);
    echo 'Database unavailable: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
}
$tokenValidation = validate_host_token($db, $tokenValue, false, false);
$tokenRow = (($tokenValidation['ok'] ?? false) && ($tokenValidation['code'] ?? '') === 'ok') ? ($tokenValidation['row'] ?? null) : null;

if ($tokenRow) {
    $gameStmt = $db->prepare("SELECT id, status FROM games WHERE id = :id LIMIT 1");
    $gameStmt->execute([':id' => $tokenRow['game_id']]);
    $gameRow = $gameStmt->fetch(PDO::FETCH_ASSOC);

    if (!$gameRow) {
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

$tokenStatusCode = $tokenValidation['code'] ?? 'missing';
$tokenIsValid = (($tokenValidation['ok'] ?? false) && $tokenStatusCode === 'ok');

$tokenUnavailableMessage = 'This link is invalid or expired. Click “Resend link to my email” to request a new one.';
if ($tokenStatusCode === 'used') {
    $tokenUnavailableMessage = 'This link was already used. Click “Resend link to my email” to get a new one.';
} elseif ($tokenStatusCode === 'expired') {
    $tokenUnavailableMessage = 'This link has expired. Click “Resend link to my email” to request a fresh link.';
} elseif ($tokenStatusCode === 'invalid' || $tokenStatusCode === 'missing') {
    $tokenUnavailableMessage = 'This link is invalid. Click “Resend link to my email” to request a new one.';
}

$tokenExpiresDisplay = ($tokenRow && $tokenRow['expires_at_dt'] instanceof DateTimeInterface)
    ? $tokenRow['expires_at_dt']->format('Y-m-d H:i:s T')
    : null;

$scoreTotals = score_load();
$scoreLineText = sprintf(
    'overall: host %d · world %d · draws %d',
    $scoreTotals['host_wins'] ?? 0,
    $scoreTotals['world_wins'] ?? 0,
    $scoreTotals['draws'] ?? 0
);

$canonicalUrl = 'https://patrickmicka.com/chess/';
$pageTitle = 'The Internet Gambit | Host Move';
$pageDescription = 'Me vs. the world, one board at a time.';
$socialImageUrl = 'https://patrickmicka.com/chess/assets/og-chess-v1.png';

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <meta name="robots" content="noindex, nofollow" />
  <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8') ?>" />
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
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="apple-touch-icon" sizes="180x180" href="assets/icons//apple-touch-icon.png">
  <link rel="icon" type="image/png" sizes="32x32" href="assets/icons//favicon-32x32.png">
  <link rel="icon" type="image/png" sizes="16x16" href="assets/icons//favicon-16x16.png">
  <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
</head>
<body>
  <div class="wrap">
    <h1>Host Move</h1>
    <?php if (isset($_GET['fresh']) && $_GET['fresh'] === '1'): ?>
      <p class="ok">Issued a fresh link for your current turn.</p>
    <?php endif; ?>
    <p id="errorBanner" class="error" style="display:none;"></p>
    <p class="subhead">
      You play <strong id="hostColorLabel">white</strong>. Visitors play <strong id="visitorColorLabel">black</strong>.
      Turn: <strong id="turnLabel">...</strong>.
    </p>
    <?php if ($tokenExpiresDisplay): ?>
    <p class="muted">This link expires at <strong><?php echo htmlspecialchars($tokenExpiresDisplay, ENT_QUOTES, 'UTF-8'); ?></strong>.</p>
    <?php endif; ?>

    <div id="completionCard" class="card state-card" style="display:none;">
      <h2>Move submitted</h2>
      <p>Your move has been recorded.<br>This link is single-use, so you’re all done here.<br>We’ll return you to the game view.</p>
      <p id="redirectNote" class="muted">Redirecting to the game…</p>
      <div class="controls">
        <a class="button-link" id="returnToGameLink" href="index.php">Return to game</a>
      </div>
    </div>

    <div id="expiredCard" class="card state-card" style="<?php echo $tokenIsValid ? 'display:none;' : 'display:block;'; ?>">
      <h2 id="expiredTitle">Host link unavailable</h2>
      <p id="expiredMessage"><?php echo htmlspecialchars($tokenUnavailableMessage, ENT_QUOTES, 'UTF-8'); ?></p>
      <div class="controls">
        <button id="btnResendExpired" type="button">Resend link to my email</button>
        <a class="button-link secondary" href="index.php">Return to game</a>
        <span id="resendStatusExpired" class="muted"></span>
      </div>
    </div>

    <div id="mainContent" style="<?php echo $tokenIsValid ? '' : 'display:none;'; ?>">
      <div class="card">
        <div class="board-container">
          <div class="board-shell">
            <div id="board" aria-label="Chess board" role="application"></div>
            <div id="promotionChooser" class="promotion-chooser" aria-live="polite" aria-label="Choose promotion piece">
              <div class="promotion-band" role="group" aria-label="Promotion options">
                <div class="promotion-buttons">
                  <button type="button" class="promo-btn active" data-piece="q" aria-label="Promote to Queen">
                    <img class="promotion-piece" alt="" src="assets/pieces/lichess/wQ.svg">
                    <span class="promotion-label">Queen</span>
                  </button>
                  <button type="button" class="promo-btn" data-piece="r" aria-label="Promote to Rook">
                    <img class="promotion-piece" alt="" src="assets/pieces/lichess/wR.svg">
                    <span class="promotion-label">Rook</span>
                  </button>
                  <button type="button" class="promo-btn" data-piece="b" aria-label="Promote to Bishop">
                    <img class="promotion-piece" alt="" src="assets/pieces/lichess/wB.svg">
                    <span class="promotion-label">Bishop</span>
                  </button>
                  <button type="button" class="promo-btn" data-piece="n" aria-label="Promote to Knight">
                    <img class="promotion-piece" alt="" src="assets/pieces/lichess/wN.svg">
                    <span class="promotion-label">Knight</span>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div id="gameOverBanner" class="gameover-banner" role="alert" aria-live="polite">
          <div>
            <h3 id="gameOverTitle"></h3>
            <p id="gameOverBody" class="muted" style="margin:0;"></p>
          </div>
          <div class="gameover-actions">
            <button id="btnNextGame" type="button">Start next game</button>
            <button id="gameOverRefresh" type="button" class="secondary button-link">Refresh</button>
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
        <div class="notation-controls">
          <button id="toggleNotation" type="button">Show notation</button>
          <span id="notationStatus" class="muted" aria-live="polite"></span>
        </div>
        <div id="notationMount"></div>
        <p class="muted mono" id="debugBox"></p>
        <p id="hostScoreLine" class="score-line" aria-live="polite"><?= htmlspecialchars($scoreLineText, ENT_QUOTES, 'UTF-8') ?></p>
      </div>
    </div>
  </div>

  <script src="assets/ui_helpers.js"></script>
  <script src="assets/board_id_map.js"></script>
  <script src="assets/chess.min.js"></script>
  <script>
    window.HOST_TOKEN = <?php echo json_encode($tokenValue); ?>;
    window.INITIAL_TOKEN_VALID = <?php echo $tokenIsValid ? 'true' : 'false'; ?>;
    window.INITIAL_TOKEN_STATUS = <?php echo json_encode($tokenStatusCode); ?>;
    window.INITIAL_TOKEN_MESSAGE = <?php echo json_encode($tokenUnavailableMessage); ?>;
  </script>
  <script>
    (() => {
      // Test protocol (promotion refresh safety):
      // 1) Start a promotion move and leave the chooser open.
      // 2) Click Refresh to pull new server state.
      // 3) Chooser closes, selection clears, and status shows “Game updated while choosing promotion; please retry.”

      const boardIdMap = (window.BoardIdMap && typeof window.BoardIdMap.createBoardIdMap === 'function')
        ? window.BoardIdMap.createBoardIdMap()
        : null;
      if (!boardIdMap) {
        console.error('Board ID map unavailable.');
        return;
      }

      const boardEl = document.getElementById('board');
      const mainContent = document.getElementById('mainContent');
      const completionCard = document.getElementById('completionCard');
      const expiredCard = document.getElementById('expiredCard');
      const redirectNote = document.getElementById('redirectNote');
      const returnToGameLink = document.getElementById('returnToGameLink');
      const btnResendExpired = document.getElementById('btnResendExpired');
      const resendStatusExpired = document.getElementById('resendStatusExpired');
      const btnRefresh = document.getElementById('btnRefresh');
      const btnSubmit = document.getElementById('btnSubmit');
      const btnCopyLink = document.getElementById('btnCopyLink');
      const btnResend = document.getElementById('btnResend');
      const expiredTitle = document.getElementById('expiredTitle');
      const expiredMessage = document.getElementById('expiredMessage');
      const statusMsg = document.getElementById('statusMsg');
      const statusSpinner = document.getElementById('statusSpinner');
      const copyStatus = document.getElementById('copyStatus');
      const resendStatus = document.getElementById('resendStatus');
      const movePreview = document.getElementById('movePreview');
      const debugBox = document.getElementById('debugBox');
      const lastUpdatedEl = document.getElementById('lastUpdated');
      const hostScoreLine = document.getElementById('hostScoreLine');
      const visitorColorLabel = document.getElementById('visitorColorLabel');
      const hostColorLabel = document.getElementById('hostColorLabel');
      const turnLabel = document.getElementById('turnLabel');
      const errorBanner = document.getElementById('errorBanner');
      const promotionChooser = document.getElementById('promotionChooser');
      const promotionButtons = Array.from(document.querySelectorAll('.promo-btn'));
      const gameOverBanner = document.getElementById('gameOverBanner');
      const gameOverTitle = document.getElementById('gameOverTitle');
      const gameOverBody = document.getElementById('gameOverBody');
      const btnNextGame = document.getElementById('btnNextGame');
      const gameOverRefresh = document.getElementById('gameOverRefresh');
      const notationMount = document.getElementById('notationMount');
      const toggleNotationBtn = document.getElementById('toggleNotation');
      const notationStatus = document.getElementById('notationStatus');

      const hostToken = (window.HOST_TOKEN || '').trim();
      const initialTokenValid = Boolean(window.INITIAL_TOKEN_VALID);
      const initialTokenMessage = (window.INITIAL_TOKEN_MESSAGE || '').toString();
      const initialScoreLine = <?php echo json_encode($scoreLineText); ?>;
      delete window.HOST_TOKEN;
      delete window.INITIAL_TOKEN_VALID;
      delete window.INITIAL_TOKEN_MESSAGE;
      delete window.INITIAL_TOKEN_STATUS;

      const DEBUG = false;

      const game = new Chess();
      const uiHelpers = window.ChessUI || {};
      const filesBase = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];
      let state = null;
      let visitorColor = 'black';
      let youColor = 'white';
      let selectedSquare = null;
      let pendingMove = null; // {from,to,promotion,san,requiresPromotion?}
      let pendingBaseFen = null;
      let lastUpdatedTs = null;
      let stateLoadPromise = null;
      let selectionStateFingerprint = null;
      let latestFetchedStateFingerprint = null;
      let promotionChoice = 'q';
      let promotionPending = false;
      let promotionColor = null;
      let redirectTimer = null;
      let gameOverState = { over: false, reason: null, winner: null };
      let notationVisible = false;
      let notationElements = null;
      let notationData = { fen: '', pgn: '' };

      function squareId(logical) {
        return boardIdMap.toOpaque(logical);
      }

      function findSquareEl(logical) {
        const opaque = squareId(logical);
        if (!opaque) return null;
        return boardEl.querySelector(`.sq[data-square-id="${opaque}"]`);
      }

      function setStatus(message, tone = 'muted', { showSpinner = false } = {}) {
        statusMsg.textContent = message;
        statusMsg.className = tone;
        if (statusSpinner) {
          statusSpinner.classList.toggle('show', showSpinner);
        }
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

      function handlePromotionInterrupted(newFingerprint) {
        if (!selectionStateFingerprint || !newFingerprint) return;
        if (selectionStateFingerprint === newFingerprint) return;
        clearSelection({ restore: true });
        setStatus('Game updated while choosing promotion; please retry.', 'muted');
      }

      function renderScoreLine(scoreData, fallbackLine = '') {
        if (!hostScoreLine) return;
        if (scoreData && typeof scoreData === 'object') {
          const hostWins = Number.isFinite(Number(scoreData.host_wins)) ? Number(scoreData.host_wins) : 0;
          const worldWins = Number.isFinite(Number(scoreData.world_wins)) ? Number(scoreData.world_wins) : 0;
          const draws = Number.isFinite(Number(scoreData.draws)) ? Number(scoreData.draws) : 0;
          hostScoreLine.textContent = `overall: host ${hostWins} · world ${worldWins} · draws ${draws}`;
          hostScoreLine.dataset.updatedAt = scoreData.updated_at || '';
          return;
        }
        if (fallbackLine) {
          hostScoreLine.textContent = fallbackLine;
        }
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

        const layout = getOrientationLayout(youColor);
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
        movePreview.textContent = 'none';
        btnSubmit.disabled = true;
        resetPromotionChooser();
        if (restore && hadPending) {
          renderBoard();
        } else {
          updateHighlights();
        }
      }

      function formatTimestamp(ts) {
        if (!ts) return 'unknown';
        const d = new Date(ts);
        if (Number.isNaN(d.getTime())) return ts;
        return d.toLocaleString();
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
        setInteractionEnabled(!isOver);
        btnSubmit.disabled = true;
        if (!isOver) {
          if (gameOverBanner) gameOverBanner.classList.remove('show');
          return;
        }
        const winnerColor = gameOverState.winner === 'w' ? 'white' : (gameOverState.winner === 'b' ? 'black' : null);
        const hostColorNorm = normalizeColor(youColor);
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
        setStatus('Game over.', 'muted');
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
          const pick = findSquareEl(selectedSquare);
          if (pick) pick.classList.add('pick');

          const moves = game.moves({ square: selectedSquare, verbose: true });
          moves.forEach(m => {
            const hint = findSquareEl(m.to);
            if (hint) hint.classList.add('hint');
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
              movePreview.textContent = `${move.san} (${move.from}->${move.to})`;
              btnSubmit.disabled = false;
              renderBoard();
            }
          } catch (err) {
            setStatus('Invalid promotion selection', 'error');
          }
        }
      }

      function isYourTurn() {
        if (!state) return false;
        return state.turn_color === youColor && state.status === 'active';
      }

      function onSquareClick(opaqueSquareId) {
        statusMsg.textContent = '';
        if (!state) return;

        if (pendingMove && !selectedSquare) {
          clearSelection({ restore: true });
        }

        if (!isYourTurn()) {
          statusMsg.textContent = 'Not your turn yet. Refresh for updates.';
          statusMsg.className = 'muted';
          return;
        }

        const sq = boardIdMap.toLogical(opaqueSquareId);
        if (!sq) return;

        const turn = game.turn();
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

        const promoInfo = promotionInfoForMove(selectedSquare, sq);
        const promoOptions = promoInfo.moves.map((m) => m.promotion).filter(Boolean);
        let promoToUse = promotionChoice;
        if (promoInfo.isPromotion) {
          promotionPending = true;
          promotionColor = yourTurnChar;
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
        selectionStateFingerprint = latestFetchedStateFingerprint || stateFingerprint(state);
        movePreview.textContent = `${move.san} (${move.from}->${move.to})`;
        btnSubmit.disabled = false;
        selectedSquare = null;
        renderBoard();
      }

      function showErrorBanner(message) {
        if (!errorBanner) return;
        errorBanner.textContent = message;
        errorBanner.style.display = 'block';
      }

      function clearErrorBanner() {
        if (!errorBanner) return;
        errorBanner.textContent = '';
        errorBanner.style.display = 'none';
      }

      function setBoardInteractive(enabled) {
        if (!boardEl) return;
        boardEl.classList.toggle('board-disabled', !enabled);
        boardEl.setAttribute('aria-disabled', enabled ? 'false' : 'true');
        btnSubmit.disabled = !enabled || btnSubmit.disabled;
      }

      function setInteractionEnabled(enabled) {
        setBoardInteractive(enabled);
        [btnRefresh, btnCopyLink, btnResend].forEach((btn) => {
          if (btn) btn.disabled = !enabled;
        });
        if (btnSubmit && !enabled) {
          btnSubmit.disabled = true;
        }
      }

      function hideStateCards() {
        [completionCard, expiredCard].forEach((card) => {
          if (card) card.style.display = 'none';
        });
      }

      function showMainContent() {
        hideStateCards();
        if (mainContent) mainContent.style.display = '';
      }

      function showStateCard(cardEl) {
        hideStateCards();
        if (mainContent) mainContent.style.display = 'none';
        if (cardEl) cardEl.style.display = 'block';
      }

      function clearRedirectTimers() {
        if (redirectTimer) {
          clearTimeout(redirectTimer);
          redirectTimer = null;
        }
      }

      function redirectToGamePage() {
        clearRedirectTimers();
        if (returnToGameLink) {
          returnToGameLink.setAttribute('aria-busy', 'true');
        }
        window.location.replace('index.php');
        redirectTimer = setTimeout(() => {
          window.location.href = 'index.php';
        }, 1500);
      }

      function enterCompletionState(message) {
        setInteractionEnabled(false);
        clearSelection();
        hideStateCards();
        showStateCard(completionCard);
        setStatus(message || 'Move submitted. Redirecting…', 'ok');
        if (statusSpinner) statusSpinner.classList.remove('show');
        redirectToGamePage();
      }

      function enterExpiredLinkState(reason) {
        setInteractionEnabled(false);
        clearSelection();
        updateLastUpdated('Unavailable', 'error');
        if (expiredMessage && reason) {
          expiredMessage.textContent = reason;
        }
        setStatus(reason || 'Host link invalid or expired. Please resend link.', 'error');
        showStateCard(expiredCard);
        if (statusSpinner) statusSpinner.classList.remove('show');
      }

      function buildStateUrl() {
        const params = new URLSearchParams();
        if (hostToken) params.set('token', hostToken);
        const qs = params.toString();
        return qs ? `api/state.php?${qs}` : 'api/state.php';
      }

      function setNotationData({ fen = '', pgn = '' }) {
        notationData = { fen: fen || '', pgn: pgn || '' };
        if (notationVisible && notationElements) {
          notationElements.fenBox.value = notationData.fen;
          notationElements.pgnBox.value = notationData.pgn;
          notationElements.copyFenMsg.textContent = '';
          notationElements.copyPgnMsg.textContent = '';
        }
      }

      function showNotation() {
        if (!notationMount || notationVisible) return;
        const wrapper = document.createElement('div');
        const fenLabel = document.createElement('p');
        fenLabel.className = 'muted';
        fenLabel.textContent = 'FEN:';
        const fenBox = document.createElement('textarea');
        fenBox.readOnly = true;
        const copyFenRow = document.createElement('div');
        copyFenRow.className = 'copy-row';
        const copyFenBtn = document.createElement('button');
        copyFenBtn.type = 'button';
        copyFenBtn.textContent = 'Copy FEN';
        const copyFenMsg = document.createElement('span');
        copyFenMsg.className = 'copy-note';
        copyFenMsg.setAttribute('aria-live', 'polite');
        copyFenRow.appendChild(copyFenBtn);
        copyFenRow.appendChild(copyFenMsg);

        const pgnLabel = document.createElement('p');
        pgnLabel.className = 'muted';
        pgnLabel.textContent = 'PGN:';
        const pgnBox = document.createElement('textarea');
        pgnBox.readOnly = true;
        const copyPgnRow = document.createElement('div');
        copyPgnRow.className = 'copy-row';
        const copyPgnBtn = document.createElement('button');
        copyPgnBtn.type = 'button';
        copyPgnBtn.textContent = 'Copy PGN';
        const copyPgnMsg = document.createElement('span');
        copyPgnMsg.className = 'copy-note';
        copyPgnMsg.setAttribute('aria-live', 'polite');
        copyPgnRow.appendChild(copyPgnBtn);
        copyPgnRow.appendChild(copyPgnMsg);

        wrapper.appendChild(fenLabel);
        wrapper.appendChild(fenBox);
        wrapper.appendChild(copyFenRow);
        wrapper.appendChild(pgnLabel);
        wrapper.appendChild(pgnBox);
        wrapper.appendChild(copyPgnRow);
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

        copyFenBtn.addEventListener('click', () => copyText(fenBox.value, copyFenMsg));
        copyPgnBtn.addEventListener('click', () => copyText(pgnBox.value, copyPgnMsg));
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

      async function fetchState() {
        if (stateLoadPromise) {
          return stateLoadPromise;
        }

        stateLoadPromise = (async () => {
          showMainContent();
          setStatus('Loading…', 'muted', { showSpinner: true });
          clearSelection();
          clearErrorBanner();
          updateLastUpdated('Updating...', 'muted');
          setInteractionEnabled(false);
          btnRefresh.disabled = true;

          try {
            const url = buildStateUrl();
            const res = await fetch(url, { cache: 'no-store' });
            if (DEBUG) console.log('fetchState status', res.status);

            const contentType = res.headers.get('content-type') || '';
            let json = null;
            if (contentType.includes('application/json')) {
              try {
                json = await res.json();
              } catch (parseErr) {
                if (DEBUG) console.log('fetchState parse error', parseErr);
              }
            } else {
              const text = await res.text();
              if (DEBUG) console.log('fetchState body', text);
            }

            if (!res.ok || (json && json.ok === false)) {
              const err = new Error((json && json.message) || `Failed to load state (HTTP ${res.status})`);
              err.status = res.status;
              err.code = json ? json.code : undefined;
              throw err;
            }

            if (!json) {
              throw new Error('Failed to parse state response');
            }

            if (DEBUG) console.log('fetchState payload', json);

            state = json;
            latestFetchedStateFingerprint = stateFingerprint(state);
            handlePromotionInterrupted(latestFetchedStateFingerprint);

            visitorColor = state.visitor_color;
            youColor = state.you_color;

            visitorColorLabel.textContent = visitorColor;
            hostColorLabel.textContent = youColor;
            turnLabel.textContent = state.turn_color;
            renderScoreLine(state.score, state.score_line);
            setNotationData({ fen: state.fen, pgn: state.pgn });

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
              try {
                game.load(state.fen);
              } catch (err) {
                throw new Error('Invalid FEN from server');
              }
            }

            debugBox.textContent = `game_id=${state.id} status=${state.status} updated_at=${state.updated_at}`;
            renderBoard();

            lastUpdatedTs = state.updated_at || null;
            updateLastUpdated(formatTimestamp(lastUpdatedTs), 'muted');

            const detected = computeGameOverFromFen(state.fen);
            const forcedOver = state.status && state.status !== 'active';
            applyGameOverUI({
              over: forcedOver || detected.over,
              reason: detected.reason || (forcedOver ? 'finished' : null),
              winner: detected.winner || null,
            });

            if (gameOverState.over) {
              setStatus('Game over. Start the next game when ready.', 'muted');
            } else {
              setStatus(isYourTurn() ? 'Your turn.' : 'Waiting on visitors.', isYourTurn() ? 'ok' : 'muted');
              setInteractionEnabled(true);
            }
            btnSubmit.disabled = true;
            clearErrorBanner();
            if (statusSpinner) statusSpinner.classList.remove('show');
          } catch (err) {
            state = null;
            const isAuthError = [401, 403, 410].includes(err.status);
            const message = err && err.message ? err.message : 'Failed to load state';
            const friendlyMessage = isAuthError ? 'Host link invalid or expired. Please resend link.' : message;

            setStatus(friendlyMessage, 'error');
            updateLastUpdated('Failed to load state', 'error');
            showErrorBanner(`Failed to load game state: ${friendlyMessage}`);
            setInteractionEnabled(false);
            try {
              if (typeof game.clear === 'function') {
                game.clear();
              }
            } catch (e) {
              // noop
            }
            if (!isAuthError) {
              renderBoard();
              setNotationData({ fen: '', pgn: '' });
              movePreview.textContent = 'none';
              throw err;
            }
            enterExpiredLinkState(friendlyMessage);
          } finally {
            btnRefresh.disabled = false;
            stateLoadPromise = null;
          }
        })();

        return stateLoadPromise;
      }

      btnRefresh.addEventListener('click', () => fetchState().catch(err => {
        setStatus(err.message || 'Failed to refresh', 'error');
        updateLastUpdated('Failed to refresh', 'error');
      }));

      promotionButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
          selectPromotionChoice(btn.dataset.piece);
          promotionChooser.classList.add('show');
        });
      });
      resetPromotionChooser();

      btnSubmit.addEventListener('click', async () => {
        if (!hostToken) {
          setStatus('Missing token. Please use the link from your email.', 'error');
          return;
        }
        if (gameOverState && gameOverState.over) {
          setStatus('Game is over. Start the next game.', 'error');
          return;
        }
        if (!pendingMove || !state) return;
        btnSubmit.disabled = true;
        setStatus('Submitting…', 'muted', { showSpinner: true });

        const payload = {
          from: pendingMove.from,
          to: pendingMove.to,
          promotion: pendingMove && pendingMove.requiresPromotion ? pendingMove.promotion : '',
          move: pendingMove.san,
          token: hostToken,
          last_known_updated_at: lastUpdatedTs,
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

          const successMessage = json.message || 'Move accepted. Visitors may move now.';
          pendingBaseFen = null;
          selectionStateFingerprint = null;
          resetPromotionChooser();
          enterCompletionState(successMessage);
        } catch (err) {
          setStatus(err.message || 'Move rejected', 'error');
          btnSubmit.disabled = false;
          setInteractionEnabled(true);
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

      async function resendLink(buttonEl, statusEl) {
        if (!buttonEl || !statusEl) return;

        statusEl.textContent = 'Sending...';
        statusEl.className = 'muted';
        buttonEl.disabled = true;
        try {
          const res = await fetch('api/my_move_submit.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'resend', token: hostToken }),
          });
          const json = await res.json();
          if (res.status === 429) {
            const remaining = typeof json.remaining_seconds === 'number' ? json.remaining_seconds : null;
            const msg = json.message || 'Please wait before resending.';
            statusEl.textContent = remaining ? `${msg} (${remaining}s)` : msg;
            statusEl.className = 'error';
            return;
          }
          if (!res.ok || json.error || (json.ok === false)) {
            const errMsg = json.error || json.message || 'Failed.';
            throw new Error(errMsg);
          }
          statusEl.textContent = json.message || 'Sent.';
          statusEl.className = 'ok';
        } catch (err) {
          statusEl.textContent = err && err.message ? err.message : 'Failed.';
          statusEl.className = 'error';
        } finally {
          buttonEl.disabled = false;
        }
      }

      btnResend.addEventListener('click', () => resendLink(btnResend, resendStatus));
      if (btnResendExpired && resendStatusExpired) {
        btnResendExpired.addEventListener('click', () => resendLink(btnResendExpired, resendStatusExpired));
      }

      async function startNextGame() {
        if (!hostToken) {
          setStatus('Missing token. Use your host link.', 'error');
          return;
        }
        if (!gameOverState.over) {
          setStatus('Game is not over yet.', 'error');
          return;
        }
        if (btnNextGame) btnNextGame.disabled = true;
        setStatus('Starting next game…', 'muted', { showSpinner: true });
        try {
          const res = await fetch('api/host_next_game.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token: hostToken }),
          });
          const json = await res.json();
          if (!res.ok || (json && json.ok === false)) {
            const errMsg = (json && (json.error || json.message)) || 'Failed to start next game.';
            throw new Error(errMsg);
          }
          window.location.href = 'index.php';
        } catch (err) {
          setStatus(err.message || 'Failed to start next game.', 'error');
          if (btnNextGame) btnNextGame.disabled = false;
        } finally {
          if (statusSpinner) statusSpinner.classList.remove('show');
        }
      }

      if (btnNextGame) {
        btnNextGame.addEventListener('click', startNextGame);
      }
      if (gameOverRefresh) {
        gameOverRefresh.addEventListener('click', () => {
          fetchState().catch(err => {
            setStatus(err.message || 'Failed to refresh', 'error');
          });
        });
      }

      renderScoreLine(null, initialScoreLine);
      renderBoard();
      notationStatus.textContent = 'Notation hidden';

      if (toggleNotationBtn) {
        toggleNotationBtn.addEventListener('click', () => {
          if (notationVisible) {
            hideNotation();
          } else {
            showNotation();
          }
        });
      }

      if (initialTokenValid) {
        fetchState().catch(err => {
          const message = err && err.message ? err.message : 'Failed to load';
          setStatus(message, 'error');
          updateLastUpdated('Failed to load', 'error');
        });
      } else {
        const unavailableMessage = initialTokenMessage || 'This host link is invalid or expired. Please request a new one.';
        enterExpiredLinkState(unavailableMessage);
      }
    })();
  </script>
</body>
</html>
