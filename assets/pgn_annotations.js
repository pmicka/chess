// Local review only. No access to application state, storage, credentials, or network.
const QUALITY = { '!!': 'Very good', '!': 'Good', '!?': 'Interesting', '?!': 'Inaccuracy', '?': 'Mistake', '??': 'Blunder' };
const NAGS = { 1: 'Good', 2: 'Mistake', 3: 'Very good', 4: 'Blunder', 5: 'Interesting', 6: 'Inaccuracy' };
const RESULT = /^(?:1-0|0-1|1\/2-1\/2|\*)$/;

export function parseAnnotatedPgn(raw, ChessCtor) {
  if (typeof raw !== 'string' || !raw.trim()) throw new Error('Paste a PGN first.');
  if (raw.length > 200000) throw new Error('PGN exceeds the 200,000-character limit.');
  const headers = Object.create(null);
  let text = raw.replace(/^\uFEFF/, '').replace(/\r\n?/g, '\n').trim();
  while (text.startsWith('[')) {
    const match = text.match(/^\[([A-Za-z][A-Za-z0-9_]*)\s+"((?:\\.|[^"\\])*)"\s*\]\s*/);
    if (!match) throw new Error('Malformed PGN header.');
    headers[match[1]] = match[2].replace(/\\(["\\])/g, '$1');
    text = text.slice(match[0].length);
  }
  if (headers.Variant && !/^(standard|chess)$/i.test(headers.Variant)) throw new Error('Only standard chess PGN is supported.');
  if (headers.SetUp === '1' && !headers.FEN) throw new Error('SetUp requires a FEN header.');
  const game = new ChessCtor();
  if (headers.FEN && !game.load(headers.FEN)) throw new Error('Invalid starting FEN.');
  const initialFen = game.fen();
  const moves = [];
  let depth = 0;
  let token = '';
  let ended = false;
  let skippedVariations = false;
  const current = () => moves[moves.length - 1];
  const comment = (value) => {
    if (!current() || depth) return;
    const a = current().annotation;
    a.comment = [a.comment, value.trim()].filter(Boolean).join('\n');
    const severity = value.match(/(?:^|[.)]\s+)(blunder|mistake|inaccuracy)\./i);
    if (severity) a.quality = severity[1][0].toUpperCase() + severity[1].slice(1).toLowerCase();
    const evaluation = value.match(/\[%eval\s+([+-]?(?:\d+(?:\.\d+)?|#-?\d+))(?:\s*,\s*\d+)?\s*\]/i);
    const change = value.match(/\(([+-]?\d+(?:\.\d+)?)\s*→\s*([+-]?\d+(?:\.\d+)?)\)/);
    if (evaluation) a.evaluation = evaluation[1];
    else if (change) a.evaluation = `${change[1]} → ${change[2]}`;
    const best = value.match(/\b([^\s{}]+)\s+was best\b/i);
    if (best) a.bestSan = best[1].replace(/[.,!?]+$/, '');
  };
  const flush = () => {
    const value = token;
    token = '';
    if (!value || depth) return;
    const sanToken = value.replace(/^\d+\.(?:\.\.)?/, '');
    if (!sanToken || sanToken === '...') return;
    if (RESULT.test(sanToken)) { ended = true; return; }
    if (ended) throw new Error('Only one game may be reviewed at a time.');
    if (/^[!?]+$/.test(sanToken)) {
      if (current()) current().annotation.quality = QUALITY[sanToken] || null;
      return;
    }
    if (moves.length >= 1000) throw new Error('PGN exceeds the 1,000-ply limit.');
    const glyph = sanToken.match(/[!?]+$/)?.[0] || '';
    const san = sanToken.slice(0, sanToken.length - glyph.length);
    const before = game.fen();
    const move = game.move(san); // Never repair, renumber, or silently truncate main-line moves.
    if (!move) throw new Error(`Invalid main-line move at ply ${moves.length + 1}.`);
    moves.push({ move, before, after: game.fen(), annotation: { comment: '', quality: QUALITY[glyph] || null, evaluation: null, bestSan: null, bestMove: null, warning: null } });
  };
  for (let i = 0; i < text.length; i++) {
    const char = text[i];
    if (char === '{' || char === ';') {
      flush();
      const end = char === '{' ? text.indexOf('}', i + 1) : text.indexOf('\n', i + 1);
      if (char === '{' && (end < 0 || text.slice(i + 1, end).includes('{'))) throw new Error('Unbalanced PGN comment.');
      comment(text.slice(i + 1, end < 0 ? text.length : end));
      i = end < 0 ? text.length : end;
    } else if (char === '(') {
      flush(); depth++; skippedVariations = true;
      if (depth > 32) throw new Error('Variation nesting is too deep.');
    } else if (char === ')') {
      flush();
      if (!depth) throw new Error('Unbalanced PGN variation.');
      depth--;
    } else if (char === '$') {
      flush();
      const nag = text.slice(i + 1).match(/^\d+/);
      if (!nag) throw new Error('Invalid numeric annotation glyph.');
      if (!depth && current() && NAGS[nag[0]]) current().annotation.quality = NAGS[nag[0]];
      i += nag[0].length;
    } else if (/\s/.test(char)) flush();
    else if (char === '}' || char === '[' || char === ']') throw new Error('Unexpected PGN delimiter or additional game.');
    else token += char;
  }
  flush();
  if (depth) throw new Error('Unbalanced PGN variation.');
  for (const entry of moves) {
    const a = entry.annotation;
    if (!a.bestSan) continue;
    const position = new ChessCtor(entry.before);
    a.bestMove = position.move(a.bestSan);
    if (!a.bestMove) a.warning = 'The supplied suggestion is not legal before this move; its arrow is hidden.';
  }
  return { headers, initialFen, moves, skippedVariations, raw };
}
