import { parseAnnotatedPgn } from './pgn_annotations.js';
import { renderBoard } from '../packages/pgn-review/src/dom.js';
import { createHistoryController } from '../packages/pgn-review/src/state.js';

const el = (id) => document.getElementById(id);
const container = el('importedReview');
const board = el('importedBoard');
const arrow = el('suggestionArrow');
const ids = window.BoardIdMap.createBoardIdMap();
let imported = null;
let controller = null;
let flipped = false;
let annotationsVisible = true;

function drawSuggestion(move) {
  arrow.replaceChildren();
  if (!move) return;
  const svg = (tag, attrs) => {
    const node = document.createElementNS('http://www.w3.org/2000/svg', tag);
    Object.entries(attrs).forEach(([name, value]) => node.setAttribute(name, value));
    return node;
  };
  const center = (sq) => {
    let col = 'abcdefgh'.indexOf(sq[0]);
    let row = 8 - Number(sq[1]);
    if (flipped) { col = 7 - col; row = 7 - row; }
    return [15 + col * 10, 15 + row * 10]; // 10×10 grid includes coordinate gutters.
  };
  const defs = svg('defs', {});
  const marker = svg('marker', { id: 'suppliedArrowHead', viewBox: '0 0 10 10', refX: '8', refY: '5', markerWidth: '5', markerHeight: '5', orient: 'auto-start-reverse' });
  marker.append(svg('path', { d: 'M 0 0 L 10 5 L 0 10 z', fill: '#7dd3ff' }));
  defs.append(marker);
  const [x1, y1] = center(move.from);
  const [x2, y2] = center(move.to);
  arrow.append(defs, svg('line', { x1, y1, x2, y2, stroke: '#7dd3ff', 'stroke-width': '1.2', 'marker-end': 'url(#suppliedArrowHead)' }));
}

function render() {
  if (!imported) return;
  const ply = controller.getSelectedIndex();
  const entry = imported.moves[ply - 1];
  const fen = entry?.after || imported.initialFen;
  const game = new window.Chess(fen);
  renderBoard({ boardEl: board, game, orientation: flipped ? 'black' : 'white', assetsBaseUrl: 'assets/', boardIdMap: ids, lastMoveSquares: entry?.move });
  // Use logical IDs, never a visual index, for square-attached decoration.
  const findSquare = (sq) => board.querySelector(`[data-square-id="${ids.toOpaque(sq)}"]`);
  let king = null;
  for (const file of 'abcdefgh') for (let rank = 1; rank <= 8; rank++) {
    const sq = `${file}${rank}`;
    const piece = game.get(sq);
    findSquare(sq).setAttribute('aria-label', `${sq}${piece ? ` ${piece.color === 'w' ? 'White' : 'Black'} ${piece.type.toUpperCase()}` : ' empty'}`);
    if (piece?.type === 'k' && piece.color === game.turn()) king = sq;
  }
  if (game.in_check()) findSquare(king)?.classList.add('review-check');
  const terminal = game.in_checkmate() ? 'checkmate' : game.in_stalemate() ? 'stalemate' : game.in_check() ? 'check' : '';
  el('reviewPosition').textContent = `Ply ${ply} of ${imported.moves.length} · ${entry?.move.san || 'Starting position'} · ${game.turn() === 'w' ? 'White' : 'Black'} to move${terminal ? ` · ${terminal}` : ''}`;
  el('reviewStart').disabled = el('reviewBack').disabled = ply === 0;
  el('reviewNext').disabled = el('reviewFinal').disabled = ply === imported.moves.length;
  el('reviewFlip').setAttribute('aria-pressed', String(flipped));
  el('annotationToggle').setAttribute('aria-pressed', String(annotationsVisible));
  el('annotationPanel').hidden = !annotationsVisible;
  const annotation = entry?.annotation;
  const parts = [];
  if (annotation?.quality) parts.push(`Supplied classification: ${annotation.quality}`);
  if (annotation?.evaluation) parts.push(`Supplied evaluation: ${annotation.evaluation}`);
  if (annotation?.bestMove) parts.push(`Supplied suggestion before this move: ${annotation.bestMove.san}`);
  if (annotation?.warning) parts.push(annotation.warning);
  if (annotationsVisible && annotation?.quality && entry) {
    const badge = document.createElement('span');
    badge.className = 'supplied-move-badge';
    badge.textContent = { Blunder: '??', Mistake: '?', Inaccuracy: '?!', 'Very good': '!!', Good: '!', Interesting: '!?' }[annotation.quality];
    badge.setAttribute('aria-hidden', 'true'); // The full supplied label is available in the text panel.
    findSquare(entry.move.to)?.appendChild(badge);
  }
  el('annotationSummary').textContent = parts.join(' · ') || 'No structured annotation supplied for this position.';
  el('annotationComment').textContent = annotation?.comment || '';
  drawSuggestion(annotationsVisible ? annotation?.bestMove : null);
  el('reviewFen').textContent = fen;
  el('reviewCopyStatus').textContent = '';
}

el('pgnImport').addEventListener('submit', (event) => {
  event.preventDefault();
  try {
    const next = parseAnnotatedPgn(el('pgnInput').value, window.Chess);
    imported = next;
    controller = createHistoryController({ timeline: [next.initialFen, ...next.moves.map((m) => m.after)], historySans: next.moves.map((m) => m.move.san), initialPly: 0 });
    flipped = false;
    annotationsVisible = true;
    el('importError').textContent = '';
    el('importedPlayers').textContent = `${next.headers.White || 'White'} vs. ${next.headers.Black || 'Black'}`;
    el('reviewScope').textContent = `Main line only${next.skippedVariations ? '; side variations omitted' : ''}. Annotations come from your PGN; no analysis engine runs here.`;
    el('reviewSource').textContent = next.raw;
    container.hidden = false;
    render();
    el('reviewFlip').focus();
  } catch (error) {
    el('importError').textContent = `${error.message} The previously loaded review, if any, is unchanged.`;
  }
});

el('clearReview').addEventListener('click', () => {
  imported = controller = null;
  el('pgnInput').value = '';
  el('importError').textContent = '';
  el('reviewSource').textContent = el('reviewFen').textContent = el('annotationComment').textContent = el('annotationSummary').textContent = '';
  el('importedPlayers').textContent = 'Imported game';
  el('reviewPosition').textContent = el('reviewCopyStatus').textContent = '';
  board.replaceChildren(); arrow.replaceChildren(); container.hidden = true;
  el('pgnInput').focus();
});

function navigate(action) {
  if (!controller) return;
  if (action === 'start') controller.setPly(0, { mode: 'review' });
  if (action === 'back') controller.goBack();
  if (action === 'next') controller.goForward();
  if (action === 'final') controller.goLive(); // Static end position; never live application state.
  render();
}
for (const [id, action] of [['reviewStart', 'start'], ['reviewBack', 'back'], ['reviewNext', 'next'], ['reviewFinal', 'final']]) el(id).addEventListener('click', () => navigate(action));
el('reviewFlip').addEventListener('click', () => { flipped = !flipped; render(); });
el('annotationToggle').addEventListener('click', () => { annotationsVisible = !annotationsVisible; render(); });
container.addEventListener('keydown', (event) => {
  if (event.altKey || event.ctrlKey || event.metaKey || event.target.closest('input, textarea, select, [contenteditable="true"]')) return;
  const action = { ArrowLeft: 'back', ArrowRight: 'next', Home: 'start', End: 'final' }[event.key];
  if (action) { event.preventDefault(); navigate(action); }
});
for (const [id, source] of [['copyPosition', 'reviewFen'], ['copySource', 'reviewSource']]) el(id).addEventListener('click', async () => {
  try { await navigator.clipboard.writeText(el(source).textContent); el('reviewCopyStatus').textContent = 'Copied.'; }
  catch (_) { el('reviewCopyStatus').textContent = 'Copy unavailable. Select the text above to copy it.'; }
});
