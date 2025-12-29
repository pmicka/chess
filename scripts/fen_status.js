#!/usr/bin/env node
// Lightweight FEN game-over detector using chess.js.
// Usage: node fen_status.js "<fen>"

const path = require('path');
const { Chess } = require(path.join(__dirname, '..', 'assets', 'chess.min.js'));

function statusForFen(fen) {
  const result = { over: false, reason: null, winner: null };
  const game = new Chess();

  try {
    game.load(fen);
  } catch (err) {
    return { ...result, error: err && err.message ? String(err.message) : 'Invalid FEN' };
  }

  const inCheckmate = game.in_checkmate();
  const inStalemate = game.in_stalemate();
  const inDraw = game.in_draw();

  let reason = null;
  if (inCheckmate) {
    reason = 'checkmate';
  } else if (inStalemate) {
    reason = 'stalemate';
  } else if (inDraw) {
    reason = 'draw';
  }

  let winner = null;
  if (inCheckmate) {
    const sideToMove = game.turn(); // 'w' or 'b'
    winner = sideToMove === 'w' ? 'b' : 'w';
  }

  return {
    over: inCheckmate || inStalemate || inDraw,
    reason,
    winner,
  };
}

const fenArg = process.argv[2] || '';
const output = statusForFen(fenArg);
console.log(JSON.stringify(output));
