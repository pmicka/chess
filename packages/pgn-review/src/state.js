export function createHistoryController({ timeline = [], historySans = [], initialPly = null } = {}) {
  let viewState = {
    mode: 'live',
    selectedPly: 0,
    latestPly: Math.max(0, timeline.length - 1),
  };

  const clamp = (idx) => {
    const maxIdx = Math.max(0, viewState.latestPly);
    if (!Number.isFinite(idx)) return maxIdx;
    return Math.max(0, Math.min(idx, maxIdx));
  };

  const applyInitialPly = (ply) => {
    if (Number.isFinite(ply)) {
      const clamped = clamp(ply);
      viewState.selectedPly = clamped;
      viewState.mode = clamped === viewState.latestPly ? 'live' : 'review';
    } else {
      viewState.selectedPly = viewState.latestPly;
      viewState.mode = 'live';
    }
  };

  const setHistory = ({ nextTimeline, nextHistorySans, nextInitialPly = null }) => {
    timeline = Array.isArray(nextTimeline) ? nextTimeline : [];
    historySans = Array.isArray(nextHistorySans) ? nextHistorySans : [];
    viewState.latestPly = Math.max(0, timeline.length - 1);
    applyInitialPly(nextInitialPly);
  };

  const getSelectedIndex = () => clamp(viewState.selectedPly);

  const isLiveView = () => viewState.mode === 'live' && getSelectedIndex() === viewState.latestPly;

  const setPly = (idx, { mode = null, allowAutoLive = false } = {}) => {
    let nextMode = mode || viewState.mode || 'live';
    let clamped = clamp(idx);
    if (nextMode === 'live') {
      clamped = viewState.latestPly;
    } else if (allowAutoLive && clamped >= viewState.latestPly) {
      nextMode = 'live';
      clamped = viewState.latestPly;
    }
    viewState = { ...viewState, mode: nextMode, selectedPly: clamped };
    return viewState;
  };

  const goBack = () => setPly(getSelectedIndex() - 1, { mode: 'review' });
  const goForward = () => setPly(getSelectedIndex() + 1, { mode: 'review', allowAutoLive: true });
  const goLive = () => setPly(viewState.latestPly, { mode: 'live' });

  setHistory({ nextTimeline: timeline, nextHistorySans: historySans, nextInitialPly: initialPly });

  return {
    getTimeline: () => timeline,
    getHistorySans: () => historySans,
    getViewState: () => ({ ...viewState }),
    getSelectedIndex,
    isLiveView,
    setHistory,
    setPly,
    goBack,
    goForward,
    goLive,
  };
}
