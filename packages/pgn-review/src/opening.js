import { getEcoLiteUrl } from './assets.js';

const GENERIC_OPENING_NAMES = new Set([
  "king's pawn game",
  'center game',
  'italian game',
  'scotch game',
]);

const GENERIC_OPENING_PATTERNS = [/\bopening$/i];

function isGenericUmbrellaName(name) {
  if (!name) return false;
  const normalized = name.trim().toLowerCase();
  if (GENERIC_OPENING_NAMES.has(normalized)) return true;
  return GENERIC_OPENING_PATTERNS.some((re) => re.test(normalized));
}

function hydrateEcoEntries(rawEntries) {
  if (!Array.isArray(rawEntries)) return [];
  return rawEntries.map((entry, idx) => {
    const movesArray = typeof entry?.moves === 'string'
      ? entry.moves.split(/\s+/).filter(Boolean)
      : (Array.isArray(entry?.movesArray) ? entry.movesArray : []);
    return {
      eco: entry.eco,
      name: entry.name,
      moves: entry.moves,
      movesArray,
      movesLen: movesArray.length,
      order: idx,
    };
  });
}

export function createEcoLiteStore({
  assetsBaseUrl,
  allowStorage = true,
  fetchImpl = fetch,
  storageKey = 'pgn_review_eco_lite_cache_v1',
} = {}) {
  return {
    started: false,
    data: null,
    promise: null,
    loadFromStorage() {
      if (!allowStorage || typeof localStorage === 'undefined') return null;
      try {
        const raw = localStorage.getItem(storageKey);
        if (!raw) return null;
        const parsed = JSON.parse(raw);
        if (!parsed || !Array.isArray(parsed.data)) return null;
        return hydrateEcoEntries(parsed.data);
      } catch (err) {
        return null;
      }
    },
    persist(data) {
      if (!allowStorage || typeof localStorage === 'undefined') return;
      try {
        localStorage.setItem(storageKey, JSON.stringify({ data }));
      } catch (err) {
        // ignore storage failures
      }
    },
    load() {
      if (this.promise) return this.promise;
      const cached = this.loadFromStorage();
      if (cached && Array.isArray(cached)) {
        this.data = cached;
        this.promise = Promise.resolve(this.data);
        this.started = true;
        return this.promise;
      }
      this.started = true;
      const url = getEcoLiteUrl(assetsBaseUrl);
      this.promise = fetchImpl(url, { cache: 'force-cache' })
        .then((res) => {
          if (!res.ok) throw new Error('Failed to load ECO data');
          return res.json();
        })
        .then((json) => {
          const hydrated = hydrateEcoEntries(json);
          this.data = hydrated;
          this.persist(json);
          return hydrated;
        })
        .catch((err) => {
          console.error('ECO lite load failed', err);
          this.data = null;
          this.promise = null;
          this.started = false;
          throw err;
        });
      return this.promise;
    },
    async getData({ prime = false } = {}) {
      if (!this.data && prime) {
        try {
          await this.load();
        } catch (err) {
          return null;
        }
      }
      return this.data;
    },
  };
}

export function getOpeningInfo(moveTokens, data) {
  if (!Array.isArray(moveTokens) || !moveTokens.length) return null;
  if (!Array.isArray(data) || !data.length) return null;
  const matches = [];
  let hasFourPlyOrLonger = false;

  for (const entry of data) {
    const movesArray = entry?.movesArray || [];
    const movesLen = entry?.movesLen || movesArray.length || 0;
    if (!movesLen || movesLen > moveTokens.length) continue;
    const isShortLine = movesLen <= 2;
    let isPrefix = true;
    for (let i = 0; i < movesLen; i += 1) {
      if (movesArray[i] !== moveTokens[i]) {
        isPrefix = false;
        break;
      }
    }
    if (!isPrefix) continue;
    if (movesLen >= 4) {
      hasFourPlyOrLonger = true;
    }
    matches.push({
      eco: entry.eco,
      name: entry.name,
      lineMoves: entry.moves,
      movesLen,
      isGeneric: isShortLine ? true : isGenericUmbrellaName(entry.name),
      order: entry.order ?? 0,
    });
  }

  const filteredMatches = hasFourPlyOrLonger
    ? matches.filter((m) => m.movesLen > 2)
    : matches;

  if (!filteredMatches.length) return null;

  filteredMatches.sort((a, b) => {
    if (a.movesLen !== b.movesLen) return b.movesLen - a.movesLen;
    if (a.isGeneric !== b.isGeneric) return a.isGeneric ? 1 : -1;
    if (a.eco !== b.eco) return a.eco.localeCompare(b.eco);
    if (a.name !== b.name) return a.name.localeCompare(b.name);
    return (a.order || 0) - (b.order || 0);
  });

  const best = filteredMatches[0];
  return best
    ? {
      eco: best.eco,
      name: best.name,
      lineMoves: best.lineMoves,
      matchPly: best.movesLen,
    }
    : null;
}
