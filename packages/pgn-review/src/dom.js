import { getPieceUrl } from './assets.js';

const filesBase = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];

function algebraic(file, rank) {
  return file + rank;
}

function getOrientationLayout(orientation) {
  const isBlack = String(orientation).toLowerCase() === 'black';
  return {
    files: isBlack ? [...filesBase].reverse() : [...filesBase],
    ranks: isBlack ? [1, 2, 3, 4, 5, 6, 7, 8] : [8, 7, 6, 5, 4, 3, 2, 1],
  };
}

function getSquareId(logical, boardIdMap) {
  if (boardIdMap && typeof boardIdMap.toOpaque === 'function') {
    return boardIdMap.toOpaque(logical);
  }
  return logical;
}

function createPieceElement(piece, assetsBaseUrl) {
  if (!piece) return null;
  const img = document.createElement('img');
  img.className = 'piece-img';
  img.setAttribute('alt', '');
  img.setAttribute('aria-hidden', 'true');
  img.src = getPieceUrl(piece.color, piece.type, assetsBaseUrl);
  img.loading = 'lazy';
  return img;
}

export function createReviewLayout(containerEl, { showExplorer = true, showNotation = true } = {}) {
  containerEl.classList.add('pgn-review');

  const boardStack = document.createElement('div');
  boardStack.className = 'board-stack';

  const boardContainer = document.createElement('div');
  boardContainer.className = 'board-container';
  const boardShell = document.createElement('div');
  boardShell.className = 'board-shell';
  const boardEl = document.createElement('div');
  boardEl.className = 'board locked';
  boardEl.setAttribute('aria-label', 'Chess board');
  boardEl.setAttribute('role', 'application');
  boardShell.appendChild(boardEl);
  boardContainer.appendChild(boardShell);
  boardStack.appendChild(boardContainer);

  const controlBay = document.createElement('div');
  controlBay.className = 'control-bay';

  const errorEl = document.createElement('div');
  errorEl.className = 'error-block';
  errorEl.setAttribute('role', 'alert');

  const historyRow = document.createElement('div');
  historyRow.className = 'history-row';
  const historyControls = document.createElement('div');
  historyControls.className = 'history-controls';
  const historyButtons = document.createElement('div');
  historyButtons.className = 'history-buttons';

  const btnBack = document.createElement('button');
  btnBack.type = 'button';
  btnBack.className = 'secondary ghost-button button-compact';
  btnBack.textContent = 'Back';
  const btnForward = document.createElement('button');
  btnForward.type = 'button';
  btnForward.className = 'secondary ghost-button button-compact';
  btnForward.textContent = 'Forward';
  const btnLive = document.createElement('button');
  btnLive.type = 'button';
  btnLive.className = 'secondary ghost-button button-compact';
  btnLive.textContent = 'Live';

  historyButtons.appendChild(btnBack);
  historyButtons.appendChild(btnForward);
  historyButtons.appendChild(btnLive);

  const historyStatus = document.createElement('span');
  historyStatus.className = 'history-chip muted';
  historyStatus.textContent = 'Live';

  historyControls.appendChild(historyButtons);
  historyControls.appendChild(historyStatus);
  historyRow.appendChild(historyControls);

  controlBay.appendChild(historyRow);

  let snapshotBlock = null;
  let snapshotOpeningEl = null;
  let snapshotPlyEl = null;
  let snapshotLastMoveEl = null;
  let snapshotStatusEl = null;

  if (showExplorer) {
    snapshotBlock = document.createElement('div');
    snapshotBlock.className = 'game-snapshot';

    const rowOpening = document.createElement('div');
    rowOpening.className = 'snapshot-row';
    const labelOpening = document.createElement('span');
    labelOpening.className = 'snapshot-label muted';
    labelOpening.textContent = 'Opening';
    snapshotOpeningEl = document.createElement('span');
    snapshotOpeningEl.className = 'snapshot-value';
    snapshotOpeningEl.textContent = 'Unknown';
    rowOpening.appendChild(labelOpening);
    rowOpening.appendChild(snapshotOpeningEl);

    const rowPly = document.createElement('div');
    rowPly.className = 'snapshot-row';
    const labelPly = document.createElement('span');
    labelPly.className = 'snapshot-label muted';
    labelPly.textContent = 'Ply';
    snapshotPlyEl = document.createElement('span');
    snapshotPlyEl.className = 'snapshot-value';
    snapshotPlyEl.textContent = '0 ply · move 0';
    rowPly.appendChild(labelPly);
    rowPly.appendChild(snapshotPlyEl);

    const rowLast = document.createElement('div');
    rowLast.className = 'snapshot-row';
    const labelLast = document.createElement('span');
    labelLast.className = 'snapshot-label muted';
    labelLast.textContent = 'Last move';
    snapshotLastMoveEl = document.createElement('span');
    snapshotLastMoveEl.className = 'snapshot-value';
    snapshotLastMoveEl.textContent = '—';
    rowLast.appendChild(labelLast);
    rowLast.appendChild(snapshotLastMoveEl);

    const rowStatus = document.createElement('div');
    rowStatus.className = 'snapshot-row';
    const labelStatus = document.createElement('span');
    labelStatus.className = 'snapshot-label muted';
    labelStatus.textContent = 'Status';
    snapshotStatusEl = document.createElement('span');
    snapshotStatusEl.className = 'snapshot-value';
    snapshotStatusEl.textContent = '—';
    rowStatus.appendChild(labelStatus);
    rowStatus.appendChild(snapshotStatusEl);

    snapshotBlock.appendChild(rowOpening);
    snapshotBlock.appendChild(rowPly);
    snapshotBlock.appendChild(rowLast);
    snapshotBlock.appendChild(rowStatus);
    controlBay.appendChild(snapshotBlock);
  }

  const detailsPanel = document.createElement('div');
  detailsPanel.className = 'hud-details-panel';

  const notationControls = document.createElement('div');
  notationControls.className = 'notation-controls';
  const toggleNotationBtn = document.createElement('button');
  toggleNotationBtn.type = 'button';
  toggleNotationBtn.className = 'secondary ghost-button button-compact';
  toggleNotationBtn.textContent = showNotation ? 'Hide notation' : 'Show notation';
  const notationStatus = document.createElement('span');
  notationStatus.className = 'muted';
  notationStatus.setAttribute('aria-live', 'polite');
  notationStatus.textContent = showNotation ? 'Notation visible' : 'Notation hidden';
  notationControls.appendChild(toggleNotationBtn);
  notationControls.appendChild(notationStatus);

  const notationMount = document.createElement('div');
  notationMount.className = 'notation-mount';

  detailsPanel.appendChild(notationControls);
  detailsPanel.appendChild(notationMount);
  if (showNotation) {
    controlBay.appendChild(detailsPanel);
  }

  controlBay.appendChild(errorEl);

  containerEl.appendChild(boardStack);
  containerEl.appendChild(controlBay);

  return {
    boardEl,
    btnBack,
    btnForward,
    btnLive,
    historyStatus,
    snapshotBlock,
    snapshotOpeningEl,
    snapshotPlyEl,
    snapshotLastMoveEl,
    snapshotStatusEl,
    toggleNotationBtn,
    notationStatus,
    notationMount,
    errorEl,
    showNotation,
    showExplorer,
    destroy() {
      containerEl.innerHTML = '';
      containerEl.classList.remove('pgn-review');
    },
  };
}

export function renderBoard({ boardEl, game, orientation, assetsBaseUrl, boardIdMap, lastMoveSquares }) {
  if (!boardEl) return;
  boardEl.innerHTML = '';

  const layout = getOrientationLayout(orientation);
  const files = layout.files;
  const ranks = layout.ranks;

  for (let row = 0; row < 10; row += 1) {
    for (let col = 0; col < 10; col += 1) {
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
      div.className = `sq ${((fileIndex + rank) % 2 === 0) ? 'light' : 'dark'}`;
      div.dataset.squareId = getSquareId(sq, boardIdMap);
      div.setAttribute('aria-label', 'Board square');
      const pieceEl = createPieceElement(piece, assetsBaseUrl);
      if (pieceEl) {
        div.appendChild(pieceEl);
      }
      if (lastMoveSquares) {
        if (lastMoveSquares.from === sq || lastMoveSquares.to === sq) {
          div.classList.add('last');
        }
      }
      boardEl.appendChild(div);
    }
  }
}

export function renderHistoryUI({ historyStatus, btnBack, btnForward, btnLive, selectedIdx, latestIdx, isLive }) {
  if (btnBack) btnBack.disabled = selectedIdx <= 0;
  if (btnForward) btnForward.disabled = selectedIdx >= latestIdx;
  if (btnLive) btnLive.disabled = isLive;
  if (historyStatus) {
    const statusText = latestIdx > 0
      ? (isLive ? 'Live' : `Move ${selectedIdx} of ${latestIdx}`)
      : 'Live';
    historyStatus.textContent = statusText;
    historyStatus.classList.toggle('muted', isLive);
    historyStatus.classList.toggle('ok', !isLive);
  }
}

export function renderSnapshot({
  snapshotOpeningEl,
  snapshotPlyEl,
  snapshotLastMoveEl,
  snapshotStatusEl,
  openingText,
  plyText,
  lastMoveText,
  statusText,
} = {}) {
  if (snapshotOpeningEl && openingText != null) snapshotOpeningEl.textContent = openingText;
  if (snapshotPlyEl && plyText != null) snapshotPlyEl.textContent = plyText;
  if (snapshotLastMoveEl && lastMoveText != null) snapshotLastMoveEl.textContent = lastMoveText;
  if (snapshotStatusEl && statusText != null) snapshotStatusEl.textContent = statusText;
}

export function createNotationPanel({ notationMount, toggleNotationBtn, notationStatus }) {
  let notationVisible = false;
  let notationElements = null;
  let notationData = { fen: '', pgn: '' };

  const setNotationData = ({ fen = '', pgn = '' }) => {
    notationData = { fen: fen || '', pgn: pgn || '' };
    if (notationVisible && notationElements) {
      notationElements.fenBox.textContent = notationData.fen;
      notationElements.pgnBox.textContent = notationData.pgn;
      notationElements.copyFenMsg.textContent = '';
      notationElements.copyPgnMsg.textContent = '';
    }
  };

  const showNotation = () => {
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
    if (toggleNotationBtn) toggleNotationBtn.textContent = 'Hide notation';
    if (notationStatus) notationStatus.textContent = 'Notation visible';

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
  };

  const hideNotation = () => {
    if (!notationVisible || !notationMount) return;
    notationMount.innerHTML = '';
    notationElements = null;
    notationVisible = false;
    if (toggleNotationBtn) toggleNotationBtn.textContent = 'Show notation';
    if (notationStatus) notationStatus.textContent = 'Notation hidden';
  };

  const toggleNotation = () => {
    if (notationVisible) {
      hideNotation();
    } else {
      showNotation();
    }
  };

  return {
    setNotationData,
    showNotation,
    hideNotation,
    toggleNotation,
    isVisible: () => notationVisible,
  };
}

export function setError(errorEl, message) {
  if (!errorEl) return;
  if (message) {
    errorEl.textContent = message;
    errorEl.classList.add('show');
  } else {
    errorEl.textContent = '';
    errorEl.classList.remove('show');
  }
}
