/**
 * Session-scoped opaque square identifiers.
 *
 * Generates a stable (per page load) mapping between logical squares
 * (a1–h8) and opaque IDs that have no semantic meaning.
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) {
    module.exports = factory();
  } else {
    root.BoardIdMap = factory();
  }
}(typeof self !== 'undefined' ? self : this, function () {
  const FILES = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'];
  const RANKS = ['1', '2', '3', '4', '5', '6', '7', '8'];

  function hexFromRandom() {
    if (typeof crypto !== 'undefined' && crypto.getRandomValues) {
      const buf = new Uint32Array(2);
      crypto.getRandomValues(buf);
      return Array.from(buf).map((n) => n.toString(16).padStart(8, '0')).join('');
    }
    return Math.random().toString(16).slice(2) + Math.random().toString(16).slice(2);
  }

  function normalizeSquare(square) {
    const trimmed = String(square || '').trim().toLowerCase();
    return /^[a-h][1-8]$/.test(trimmed) ? trimmed : '';
  }

  function createBoardIdMap() {
    const logicalToOpaque = new Map();
    const opaqueToLogical = new Map();

    FILES.forEach((file) => {
      RANKS.forEach((rank) => {
        const logical = `${file}${rank}`;
        let opaque = '';
        do {
          opaque = `sq_${hexFromRandom().slice(0, 12)}`;
        } while (opaqueToLogical.has(opaque));
        logicalToOpaque.set(logical, opaque);
        opaqueToLogical.set(opaque, logical);
      });
    });

    return {
      toOpaque(logicalSquare) {
        return logicalToOpaque.get(normalizeSquare(logicalSquare)) || '';
      },
      toLogical(opaqueId) {
        return opaqueToLogical.get(String(opaqueId || '')) || '';
      },
      allOpaqueIds() {
        return Array.from(opaqueToLogical.keys());
      },
    };
  }

  return { createBoardIdMap };
}));
