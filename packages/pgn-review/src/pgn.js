const PLY_NUMBERED_PATTERN = /\b\d+\.\.\./;

export function isPlyNumberedPgn(text) {
  return PLY_NUMBERED_PATTERN.test(text || '');
}

export function normalizePlyNumberedPGN(text) {
  const tokens = (text || '').trim().split(/\s+/).filter(Boolean);
  if (!tokens.length) return '';

  const entries = [];
  for (let i = 0; i < tokens.length; i += 1) {
    const match = tokens[i].match(/^(\d+)\.(\.\.)?$/);
    if (!match) continue;
    const san = tokens[i + 1];
    if (!san) break;
    entries.push({
      num: parseInt(match[1], 10),
      color: match[2] ? 'b' : 'w',
      san,
    });
    i += 1;
  }

  if (!entries.length) return text.trim();

  const needsRenumber = entries.some((entry, idx) => entry.num !== Math.floor(idx / 2) + 1);
  const moveMap = new Map();

  entries.forEach((entry, idx) => {
    const moveNum = needsRenumber ? (Math.floor(idx / 2) + 1) : entry.num;
    if (!moveMap.has(moveNum)) {
      moveMap.set(moveNum, { white: null, black: null });
    }
    const slot = moveMap.get(moveNum);
    if (entry.color === 'b') {
      if (!slot.black) slot.black = entry.san;
    } else if (!slot.white) {
      slot.white = entry.san;
    }
  });

  const orderedNumbers = Array.from(moveMap.keys()).sort((a, b) => a - b);
  const parts = [];
  orderedNumbers.forEach((num) => {
    const slot = moveMap.get(num);
    if (!slot) return;
    if (slot.white) {
      parts.push(`${num}. ${slot.white}${slot.black ? ` ${slot.black}` : ''}`);
    } else if (slot.black) {
      parts.push(`${num}... ${slot.black}`);
    }
  });

  return parts.join(' ').trim();
}

export function normalizeMovetext(pgnText) {
  const trimmed = (pgnText || '').trim();
  if (!trimmed) return '';
  if (isPlyNumberedPgn(trimmed)) {
    const normalized = normalizePlyNumberedPGN(trimmed);
    if (normalized) return normalized;
  }
  return trimmed;
}

export function buildPgnFromSansMoves(sansMoves) {
  if (!Array.isArray(sansMoves) || sansMoves.length === 0) return '';
  const parts = [];
  sansMoves.forEach((san, idx) => {
    const moveNo = Math.floor(idx / 2) + 1;
    if (idx % 2 === 0) {
      parts.push(`${moveNo}. ${san}`);
    } else {
      parts.push(san);
    }
  });
  return parts.join(' ').trim();
}

export function deriveDisplayPgn(rawPgn, sansMoves) {
  if (Array.isArray(sansMoves) && sansMoves.length) {
    const rendered = buildPgnFromSansMoves(sansMoves);
    return normalizeMovetext(rendered);
  }
  return normalizeMovetext(rawPgn || '');
}

export function buildHistoryTimeline({ ChessCtor, initialFen = 'start', pgnText = '' }) {
  if (!ChessCtor) throw new Error('Chess constructor unavailable');
  const startFen = (initialFen && initialFen !== 'start') ? initialFen : null;
  const seed = startFen ? new ChessCtor(startFen) : new ChessCtor();
  const timeline = [seed.fen()];
  const historySans = [];
  const normalized = normalizeMovetext(pgnText);
  if (!normalized) return { timeline, historySans, normalizedPgn: '' };

  const parser = startFen ? new ChessCtor(startFen) : new ChessCtor();
  const loaded = parser.load_pgn(normalized, { sloppy: true });
  if (!loaded) {
    throw new Error('Invalid PGN');
  }
  historySans.push(...parser.history());
  const replay = startFen ? new ChessCtor(startFen) : new ChessCtor();
  historySans.forEach((san, idx) => {
    const move = replay.move(san, { sloppy: true });
    if (!move) {
      throw new Error(`Illegal move at ply ${idx + 1}: ${san}`);
    }
    timeline.push(replay.fen());
  });
  const normalizedPgn = buildPgnFromSansMoves(historySans) || normalized;
  return { timeline, historySans, normalizedPgn };
}

export function buildSnapshotGame({ ChessCtor, initialFen = 'start', historySans = [], ply = 0 }) {
  if (!ChessCtor) throw new Error('Chess constructor unavailable');
  const startFen = (initialFen && initialFen !== 'start') ? initialFen : null;
  const replay = startFen ? new ChessCtor(startFen) : new ChessCtor();
  const limit = Math.max(0, Math.min(ply, historySans.length));
  for (let i = 0; i < limit; i += 1) {
    const mv = replay.move(historySans[i], { sloppy: true });
    if (!mv) break;
  }
  const verboseMoves = replay.history({ verbose: true }) || [];
  return { chess: replay, verboseMoves };
}

export function buildMoveTokensFromHistory(verboseHistory) {
  if (!Array.isArray(verboseHistory) || !verboseHistory.length) return [];
  return verboseHistory.map((m) => `${m.from}${m.to}`);
}
