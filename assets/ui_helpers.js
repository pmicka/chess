/**
 * Shared UI helpers for board orientation and safe state handling.
 *
 * The helpers are written in a tiny UMD-style wrapper so they can be used
 * both in the browser (attached to window.ChessUI) and in Node-based tests
 * (module.exports).
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.ChessUI = factory();
  }
}(typeof self !== 'undefined' ? self : this, function () {
  const FILES = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];

  /**
   * Normalize a color string to "white" or "black".
   */
  function normalizeColor(color) {
    return String(color).toLowerCase() === 'black' ? 'black' : 'white';
  }

  /**
   * Return the board files (left-to-right) and ranks (top-to-bottom)
   * for the given orientation.
   */
  function orientationLayout(color) {
    const normalized = normalizeColor(color);
    if (normalized === 'black') {
      return {
        files: [...FILES].reverse(), // h -> a
        ranks: [1, 2, 3, 4, 5, 6, 7, 8],
      };
    }
    return {
      files: [...FILES],
      ranks: [8, 7, 6, 5, 4, 3, 2, 1],
    };
  }

  /**
   * Map a square name (e.g., "a2") to its rotated equivalent for the
   * specified orientation. For black orientation the mapping is a 180°
   * rotation; white orientation is a no-op.
   */
  function mapSquareForOrientation(square, color) {
    const normalized = normalizeColor(color);
    if (normalized === 'white') {
      return square;
    }

    const match = /^([a-h])([1-8])$/i.exec(square || '');
    if (!match) return square;

    const fileIdx = FILES.indexOf(match[1].toLowerCase());
    const rankNum = parseInt(match[2], 10);
    if (fileIdx === -1 || Number.isNaN(rankNum)) {
      return square;
    }

    const rotatedFile = FILES[7 - fileIdx];
    const rotatedRank = 9 - rankNum;
    return `${rotatedFile}${rotatedRank}`;
  }

  /**
   * Convert a 0-based file/rank pair from the player's perspective
   * (file 0 = left from their seat, rank 0 = closest rank) into a
   * board square string.
   */
  function playerPerspectiveSquare(fileIndex, rankIndex, color) {
    const normalized = normalizeColor(color);
    const safeFile = Math.max(0, Math.min(7, fileIndex));
    const safeRank = Math.max(0, Math.min(7, rankIndex));

    if (normalized === 'black') {
      const file = FILES[7 - safeFile];
      const rank = 8 - safeRank;
      return `${file}${rank}`;
    }

    const file = FILES[safeFile];
    const rank = 1 + safeRank;
    return `${file}${rank}`;
  }

  /**
   * Build an 8x8 matrix of square names representing the visible grid
   * when rendered for the given orientation.
   */
  function squareMatrix(color) {
    const layout = orientationLayout(color);
    const rows = [];
    for (let r = 0; r < layout.ranks.length; r++) {
      const row = [];
      for (let c = 0; c < layout.files.length; c++) {
        row.push(`${layout.files[c]}${layout.ranks[r]}`);
      }
      rows.push(row);
    }
    return rows;
  }

  return {
    FILES,
    normalizeColor,
    orientationLayout,
    mapSquareForOrientation,
    playerPerspectiveSquare,
    squareMatrix,
  };
}));
