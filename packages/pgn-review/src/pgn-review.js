import { resolveAssetsBaseUrl } from './assets.js';
import { buildHistoryTimeline, buildSnapshotGame, buildMoveTokensFromHistory, deriveDisplayPgn } from './pgn.js';
import { createHistoryController } from './state.js';
import { createEcoLiteStore, getOpeningInfo } from './opening.js';
import {
  createReviewLayout,
  renderBoard,
  renderHistoryUI,
  renderSnapshot,
  createNotationPanel,
  setError,
} from './dom.js';

const DEFAULT_OPTIONS = {
  pgn: '',
  initialFen: 'start',
  initialPly: null,
  orientation: 'white',
  showExplorer: true,
  showNotation: true,
  allowStorage: true,
  assetsBaseUrl: '/assets/vendor/pgn-review/',
};

function getChessCtor(opts) {
  if (opts && opts.Chess) return opts.Chess;
  if (typeof window !== 'undefined' && window.Chess) return window.Chess;
  return null;
}

function formatMoveCount(plies) {
  const num = Number.isFinite(plies) ? Math.max(0, plies) : 0;
  const moveNum = Math.max(0, Math.ceil(num / 2));
  return `${num} ply · move ${moveNum}`;
}

function buildLastMoveText(lastMove) {
  if (!lastMove) return '—';
  const san = lastMove.san || `${lastMove.from}${lastMove.to}${lastMove.promotion || ''}`;
  return san || '—';
}

export function mountPgnReview(containerEl, opts = {}) {
  if (!containerEl) throw new Error('Container element is required.');
  const options = { ...DEFAULT_OPTIONS, ...opts };
  const ChessCtor = getChessCtor(options);
  if (!ChessCtor) {
    throw new Error('Chess constructor not found. Provide opts.Chess or include chess.js globally.');
  }

  const assetsBaseUrl = resolveAssetsBaseUrl(options.assetsBaseUrl);
  const layout = createReviewLayout(containerEl, {
    showExplorer: options.showExplorer,
    showNotation: options.showNotation,
  });

  const boardIdMap = (typeof window !== 'undefined' && window.BoardIdMap
    && typeof window.BoardIdMap.createBoardIdMap === 'function')
    ? window.BoardIdMap.createBoardIdMap()
    : null;

  const historyController = createHistoryController({
    timeline: [],
    historySans: [],
    initialPly: options.initialPly,
  });

  const ecoLiteStore = createEcoLiteStore({
    assetsBaseUrl,
    allowStorage: options.allowStorage,
  });

  const notationPanel = createNotationPanel({
    notationMount: layout.notationMount,
    toggleNotationBtn: layout.toggleNotationBtn,
    notationStatus: layout.notationStatus,
  });

  let historyState = {
    timeline: [],
    historySans: [],
    normalizedPgn: '',
  };

  let currentInitialFen = options.initialFen || 'start';
  let openingLookupToken = 0;

  const applyHistoryState = ({ timeline, historySans, normalizedPgn }) => {
    historyState = { timeline, historySans, normalizedPgn };
    historyController.setHistory({
      nextTimeline: timeline,
      nextHistorySans: historySans,
      nextInitialPly: options.initialPly,
    });
  };

  const updateSnapshot = async () => {
    if (!layout.snapshotBlock) return;
    const selectedIdx = historyController.getSelectedIndex();
    const snapshot = buildSnapshotGame({
      ChessCtor,
      initialFen: currentInitialFen,
      historySans: historyState.historySans,
      ply: selectedIdx,
    });
    const verboseMoves = snapshot.verboseMoves || [];
    const lastMove = verboseMoves[verboseMoves.length - 1] || null;
    const plyText = formatMoveCount(verboseMoves.length);
    const lastMoveText = buildLastMoveText(lastMove);
    const toMove = snapshot.chess.turn() === 'w' ? 'White' : 'Black';
    const statusBits = [`${toMove} to move`];
    if (snapshot.chess.in_check()) {
      statusBits.push('check');
    }
    const statusText = statusBits.join(' · ');

    let openingText = 'Unknown';
    const lookupId = ++openingLookupToken;
    try {
      const moveTokens = buildMoveTokensFromHistory(verboseMoves);
      const data = await ecoLiteStore.getData({ prime: true });
      const opening = getOpeningInfo(moveTokens, data);
      if (opening) {
        openingText = `${opening.eco} — ${opening.name}`;
      }
    } catch (err) {
      console.error('Opening lookup failed', err);
    }
    if (lookupId !== openingLookupToken) return;
    renderSnapshot({
      snapshotOpeningEl: layout.snapshotOpeningEl,
      snapshotPlyEl: layout.snapshotPlyEl,
      snapshotLastMoveEl: layout.snapshotLastMoveEl,
      snapshotStatusEl: layout.snapshotStatusEl,
      openingText,
      plyText,
      lastMoveText,
      statusText,
    });
  };

  const updateBoard = () => {
    const selectedIdx = historyController.getSelectedIndex();
    const timeline = historyState.timeline;
    const targetFen = timeline[selectedIdx];
    const game = new ChessCtor();
    if (targetFen) {
      try {
        game.load(targetFen);
      } catch (err) {
        setError(layout.errorEl, 'Invalid FEN for this position.');
        return;
      }
    }
    const lastMoveSquares = (() => {
      const verboseMoves = buildSnapshotGame({
        ChessCtor,
        initialFen: currentInitialFen,
        historySans: historyState.historySans,
        ply: selectedIdx,
      }).verboseMoves;
      const lastMove = verboseMoves[verboseMoves.length - 1];
      return lastMove ? { from: lastMove.from, to: lastMove.to } : null;
    })();
    renderBoard({
      boardEl: layout.boardEl,
      game,
      orientation: options.orientation,
      assetsBaseUrl,
      boardIdMap,
      lastMoveSquares,
    });
  };

  const updateNotation = () => {
    const selectedIdx = historyController.getSelectedIndex();
    const fen = historyState.timeline[selectedIdx] || '';
    const pgnDisplay = deriveDisplayPgn(options.pgn, historyState.historySans);
    notationPanel.setNotationData({ fen, pgn: pgnDisplay });
  };

  const updateHistoryControls = () => {
    const viewState = historyController.getViewState();
    renderHistoryUI({
      historyStatus: layout.historyStatus,
      btnBack: layout.btnBack,
      btnForward: layout.btnForward,
      btnLive: layout.btnLive,
      selectedIdx: historyController.getSelectedIndex(),
      latestIdx: viewState.latestPly,
      isLive: historyController.isLiveView(),
    });
  };

  const renderAll = () => {
    updateHistoryControls();
    updateBoard();
    updateNotation();
    updateSnapshot();
  };

  const handleBack = () => {
    historyController.goBack();
    renderAll();
  };

  const handleForward = () => {
    historyController.goForward();
    renderAll();
  };

  const handleLive = () => {
    historyController.goLive();
    renderAll();
  };

  const handleKeydown = (ev) => {
    const target = ev.target;
    if (target && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName))) return;
    if (ev.key === 'ArrowLeft') {
      ev.preventDefault();
      handleBack();
    } else if (ev.key === 'ArrowRight') {
      ev.preventDefault();
      handleForward();
    } else if (ev.key === 'Home') {
      ev.preventDefault();
      historyController.setPly(0, { mode: 'review' });
      renderAll();
    } else if (ev.key === 'End') {
      ev.preventDefault();
      handleLive();
    }
  };

  const handleToggleNotation = () => notationPanel.toggleNotation();

  if (layout.btnBack) layout.btnBack.addEventListener('click', handleBack);
  if (layout.btnForward) layout.btnForward.addEventListener('click', handleForward);
  if (layout.btnLive) layout.btnLive.addEventListener('click', handleLive);
  if (layout.toggleNotationBtn && options.showNotation) {
    layout.toggleNotationBtn.addEventListener('click', handleToggleNotation);
  }

  containerEl.setAttribute('tabindex', '0');
  containerEl.addEventListener('keydown', handleKeydown);

  const loadPgn = (pgnText, initialFen) => {
    options.pgn = pgnText || '';
    currentInitialFen = initialFen || options.initialFen || 'start';
    setError(layout.errorEl, '');

    try {
      const historyBundle = buildHistoryTimeline({
        ChessCtor,
        initialFen: currentInitialFen,
        pgnText: options.pgn,
      });
      applyHistoryState(historyBundle);
    } catch (err) {
      const message = err && err.message ? err.message : String(err);
      setError(layout.errorEl, `PGN parse failed: ${message}`);
      let fallbackFen = 'start';
      try {
        const fallbackGame = currentInitialFen && currentInitialFen !== 'start'
          ? new ChessCtor(currentInitialFen)
          : new ChessCtor();
        fallbackFen = fallbackGame.fen();
      } catch (fenErr) {
        fallbackFen = new ChessCtor().fen();
      }
      applyHistoryState({
        timeline: [fallbackFen],
        historySans: [],
        normalizedPgn: '',
      });
    }
    if (options.showNotation) {
      notationPanel.showNotation();
    }
    renderAll();
  };

  loadPgn(options.pgn, currentInitialFen);

  return {
    destroy() {
      if (layout.btnBack) layout.btnBack.removeEventListener('click', handleBack);
      if (layout.btnForward) layout.btnForward.removeEventListener('click', handleForward);
      if (layout.btnLive) layout.btnLive.removeEventListener('click', handleLive);
      if (layout.toggleNotationBtn && options.showNotation) {
        layout.toggleNotationBtn.removeEventListener('click', handleToggleNotation);
      }
      containerEl.removeEventListener('keydown', handleKeydown);
      layout.destroy();
    },
    setPgn(nextPgn, nextInitialFen) {
      loadPgn(nextPgn, nextInitialFen || currentInitialFen);
    },
  };
}
