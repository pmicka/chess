'use strict';

// The same vendored engine as the boards; no package installation or network I/O.
const { Chess } = require('../assets/chess.min.js');
const START = new Chess().fen();
const MAX_PGN_BYTES = 65536;
const MAX_PLIES = 2048;

class InvalidState extends Error {}
function requireState(condition) { if (!condition) throw new InvalidState(); }

function loadPosition(fen) {
  requireState(typeof fen === 'string' && fen.length <= 160);
  const fields = fen.split(' ');
  requireState(fields.length === 6 && /^(w|b)$/.test(fields[1]) &&
    /^(?:-|K?Q?k?q?)$/.test(fields[2]) && fields[2] !== '' &&
    /^(?:-|[a-h][36])$/.test(fields[3]) && /^(0|[1-9][0-9]{0,5})$/.test(fields[4]) &&
    /^[1-9][0-9]{0,5}$/.test(fields[5]));
  const game = new Chess();
  requireState(game.load(fen) === true && game.fen() === fen);
  const pieces = game.board().flat().filter(Boolean);
  for (const color of ['w', 'b']) {
    requireState(pieces.filter(p => p.type === 'k' && p.color === color).length === 1);
    requireState(pieces.filter(p => p.color === color).length <= 16);
    requireState(pieces.filter(p => p.type === 'p' && p.color === color).length <= 8);
  }
  for (const rank of [1, 8]) {
    for (const file of 'abcdefgh') requireState(game.get(file + rank)?.type !== 'p');
  }
  for (const [right, king, rook, color] of [
    ['K','e1','h1','w'], ['Q','e1','a1','w'], ['k','e8','h8','b'], ['q','e8','a8','b']]) {
    if (fields[2].includes(right)) {
      requireState(game.get(king)?.type === 'k' && game.get(king)?.color === color &&
        game.get(rook)?.type === 'r' && game.get(rook)?.color === color);
    }
  }
  if (fields[3] !== '-') {
    const file = fields[3][0];
    const white = fields[1] === 'w';
    requireState(fields[3][1] === (white ? '6' : '3') && fields[4] === '0');
    requireState(!white || Number(fields[5]) >= 2);
    requireState(game.get(fields[3]) === null && game.get(file + (white ? '7' : '2')) === null);
    const pawn = game.get(file + (white ? '5' : '4'));
    requireState(pawn?.type === 'p' && pawn.color === (white ? 'b' : 'w'));
  }
  // The side that just moved cannot have left its own king in check. This also
  // rejects adjacent kings and prevents king captures from corrupt legacy FENs.
  const previous = new Chess();
  const opposite = [...fields]; opposite[1] = fields[1] === 'w' ? 'b' : 'w'; opposite[3] = '-';
  requireState(previous.load(opposite.join(' ')) === true && !previous.in_check());
  return game;
}

function positionKey(game) {
  const fields = game.fen().split(' ');
  // FIDE repetition ignores an EP target if no *legal* en-passant capture exists.
  // The bundled engine's raw-FEN repetition comparison does not normalize this.
  if (fields[3] !== '-' && !game.moves({verbose:true}).some(move => move.flags.includes('e'))) fields[3] = '-';
  return fields.slice(0, 4).join(' ');
}

function loadHistory(fen, pgn) {
  const canonical = loadPosition(fen);
  if (pgn === undefined) return {game: canonical, occurrences: new Map([[positionKey(canonical), 1]])};
  requireState(typeof pgn === 'string' && Buffer.byteLength(pgn) <= MAX_PGN_BYTES);
  // The application's writer stores main-line SAN. This vendored serializer
  // drops annotations; refuse such legacy content instead of silently losing it.
  const movetext = pgn.replace(/^\s*\[[^\r\n]*\]\s*$/gm, '');
  requireState(!/[{}();$!?]/.test(movetext));
  if (pgn.trim() === '') {
    requireState(fen === START);
    return {game: canonical, occurrences: new Map([[positionKey(canonical), 1]])};
  }
  const fenHeaders = [...pgn.matchAll(/^\s*\[FEN "([^"\r\n]+)"\]\s*$/gm)];
  const setupHeaders = [...pgn.matchAll(/^\s*\[SetUp "([^"\r\n]+)"\]\s*$/gm)];
  requireState(fenHeaders.length <= 1 && setupHeaders.length <= 1);
  requireState(fenHeaders.length === 0 || (setupHeaders.length === 1 && setupHeaders[0][1] === '1'));
  requireState(setupHeaders.length === 0 || (setupHeaders[0][1] === '1' && fenHeaders.length === 1));
  const initial = fenHeaders.length ? fenHeaders[0][1] : START;
  loadPosition(initial);
  const parsed = new Chess();
  requireState(parsed.load_pgn(pgn, {sloppy:false}) === true && parsed.fen() === fen);
  const history = parsed.history({verbose:true});
  requireState(history.length <= MAX_PLIES);
  // Replay the verified moves to count positions. Do not derive repetition from
  // FEN alone, or trust partially loaded PGN after load_pgn() returns false.
  const replay = new Chess(initial);
  const occurrences = new Map([[positionKey(replay), 1]]);
  for (const move of history) {
    requireState(replay.move({from:move.from, to:move.to, ...(move.promotion ? {promotion:move.promotion} : {})}) !== null);
    const key = positionKey(replay);
    occurrences.set(key, (occurrences.get(key) || 0) + 1);
  }
  requireState(replay.fen() === fen);
  return {game:parsed, occurrences};
}

function status(game, occurrences) {
  if (game.in_checkmate()) return {ok:true, over:true, reason:'checkmate', winner:game.turn() === 'w' ? 'b' : 'w'};
  if (game.in_stalemate()) return {ok:true, over:true, reason:'stalemate', winner:null};
  // Preserve the application's automatic fifty-move / threefold draw policy.
  const draw = Number(game.fen().split(' ')[4]) >= 100 || game.insufficient_material() ||
    (occurrences.get(positionKey(game)) || 0) >= 3;
  return {ok:true, over:draw, reason:draw ? 'draw' : null, winner:null};
}

function evaluate(input) {
  try {
    requireState(input !== null && typeof input === 'object' && !Array.isArray(input));
    const {game, occurrences} = loadHistory(input.fen, input.pgn);
    if (input.turn_color !== undefined) {
      requireState(input.turn_color === (game.turn() === 'w' ? 'white' : 'black'));
    }
    const before = status(game, occurrences);
    const actualResult = !before.over ? '*' : before.winner === 'w' ? '1-0' : before.winner === 'b' ? '0-1' : '1/2-1/2';
    const declared = game.header().Result;
    const trailing = typeof input.pgn === 'string' ? input.pgn.trim().match(/(?:^|\s)(1-0|0-1|1\/2-1\/2|\*)$/) : null;
    for (const result of [declared, trailing?.[1]]) {
      requireState(result === undefined || result === '*' || result === actualResult);
    }
    if (input.move === undefined) return {ok:true, ...before};
    if (before.over) return {ok:true, before, move:null};
    const move = input.move;
    if (!move || typeof move !== 'object' || Array.isArray(move) ||
      typeof move.from !== 'string' || !/^[a-h][1-8]$/.test(move.from) ||
      typeof move.to !== 'string' || !/^[a-h][1-8]$/.test(move.to) ||
      typeof move.promotion !== 'string' || !/^(?:|q|r|b|n)$/.test(move.promotion)) {
      return {ok:false, code:'illegal_move'};
    }
    // Match the exact legal move including promotion. chess.js otherwise accepts
    // spurious promotion parameters on ordinary moves.
    const legal = game.moves({verbose:true}).find(candidate => candidate.from === move.from &&
      candidate.to === move.to && (candidate.promotion || '') === move.promotion);
    if (!legal) return {ok:false, code:'illegal_move'};
    const accepted = game.move({from:legal.from, to:legal.to, ...(legal.promotion ? {promotion:legal.promotion} : {})});
    requireState(accepted !== null);
    loadPosition(game.fen());
    const key = positionKey(game);
    occurrences.set(key, (occurrences.get(key) || 0) + 1);
    const after = status(game, occurrences);
    if (after.over) game.header('Result', after.winner === 'w' ? '1-0' : after.winner === 'b' ? '0-1' : '1/2-1/2');
    const pgn = game.pgn();
    requireState(Buffer.byteLength(pgn) <= MAX_PGN_BYTES && game.history().length <= MAX_PLIES);
    return {ok:true, before, move:accepted, fen:game.fen(), pgn,
      turn_color:game.turn() === 'w' ? 'white' : 'black', after};
  } catch (error) {
    return {ok:false, code:error instanceof InvalidState ? 'state_inconsistent' : 'engine_error'};
  }
}

module.exports = {evaluate};
if (require.main === module) {
  let result;
  try {
    const input = require('fs').readFileSync(0, 'utf8');
    result = Buffer.byteLength(input) > 131072 ? {ok:false, code:'state_inconsistent'} : evaluate(JSON.parse(input));
  } catch (_) { result = {ok:false, code:'engine_error'}; }
  process.stdout.write(JSON.stringify(result) + '\n');
}
