'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const { Chess } = require('../assets/chess.min.js');
// Load the dependency-free browser ES module without changing repository module semantics.
const parser = import('data:text/javascript;base64,' + fs.readFileSync(require.resolve('../assets/pgn_annotations.js')).toString('base64'));
async function parse(pgn) { return (await parser).parseAnnotatedPgn(pgn, Chess); }

test('plain PGN has correct history and no invented annotations', async () => {
  const game = await parse('1.e4 e5 2.Nf3 Nc6 *');
  assert.equal(game.moves.length, 4);
  for (const entry of game.moves) {
    assert.equal(entry.annotation.quality, null);
    assert.equal(entry.annotation.evaluation, null);
    assert.equal(entry.annotation.bestMove, null);
  }
  assert.equal(new Chess(game.moves[3].after).get('c6').type, 'n');
});
test('suffixes, adjacent NAGs and supplied comments associate with the correct main-line ply', async () => {
  const game = await parse('1.e4?! { (0.13 → -0.79) Inaccuracy. d4 was best. } (1.d4 d5 (1...Nf6) 2.c4) e5 2.Nf3$1 {[%eval 0.3]} Nc6');
  assert.equal(game.moves.length, 4);
  assert.equal(game.skippedVariations, true);
  const a = game.moves[0].annotation;
  assert.equal(a.quality, 'Inaccuracy');
  assert.equal(a.evaluation, '0.13 → -0.79');
  assert.equal(a.bestMove.from, 'd2');
  assert.equal(a.bestMove.to, 'd4');
  assert.equal(game.moves[1].annotation.quality, null);
  assert.equal(game.moves[2].annotation.quality, 'Good');
  assert.equal(game.moves[2].annotation.evaluation, '0.3');
});
test('comments inside variations and semicolon comments do not add moves', async () => {
  const game = await parse('1.e4 (1.d4 { ) best move } d5) ; main-line note\ne5');
  assert.equal(game.moves.length, 2);
  assert.equal(game.moves[0].annotation.comment, 'main-line note');
});
test('supplied mate evaluation remains supplied text', async () => {
  const game = await parse('1.f3 {[%eval #-3,18]} e5');
  assert.equal(game.moves[0].annotation.evaluation, '#-3');
});
test('a narrative mention does not become a quality classification', async () => {
  const game = await parse('1.e4 {This was not a blunder.} e5');
  assert.equal(game.moves[0].annotation.quality, null);
});
test('illegal suggested moves are omitted without damaging valid main-line history', async () => {
  const game = await parse('1.e4 {Qh5 was best.} e5');
  assert.equal(game.moves.length, 2);
  assert.equal(game.moves[0].annotation.bestMove, null);
  assert.match(game.moves[0].annotation.warning, /not legal/);
});
test('FEN setup supports black to move and explicit promotion', async () => {
  const game = await parse('[SetUp "1"]\n[FEN "4k3/8/8/8/8/8/p7/4K3 b - - 0 1"]\n\n1...a1=N $5');
  assert.equal(game.moves.length, 1);
  assert.equal(game.moves[0].move.promotion, 'n');
  assert.equal(game.moves[0].annotation.quality, 'Interesting');
});
test('HTML-like headers and comments stay opaque text', async () => {
  const game = await parse('[White "<img src=x>"]\n\n1.e4 {<script>alert(1)</script>} e5');
  assert.equal(game.headers.White, '<img src=x>');
  assert.equal(game.moves[0].annotation.comment, '<script>alert(1)</script>');
});
test('bound main-line history rather than truncating it', async () => {
  await assert.rejects(parse('Nf3 Nf6 Ng1 Ng8 '.repeat(251)), /1,000-ply/);
});
for (const [name, pgn] of [
  ['illegal main line', '1.e4 e5 2.Bh6'], ['truncated comment', '1.e4 {bad'],
  ['nested comment', '1.e4 {bad {bad}'], ['unbalanced variation', '1.e4 (1.d4'],
  ['extra closing variation', '1.e4 ) e5'], ['multiple games', '1.e4 1-0 1.d4'],
  ['extra headers', '1.e4 [White "Other"]'], ['bad NAG', '1.e4 $x'],
  ['bad setup', '[SetUp "1"]\n\n1.e4'], ['bad FEN', '[FEN "invalid"]\n\n1.e4'],
  ['unsupported variant', '[Variant "Atomic"]\n\n1.e4'], ['empty input', ''],
  ['oversize input', ' '.repeat(200001)], ['excess nesting', '('.repeat(33)],
]) test(`reject ${name} without silently repairing data`, async () => {
  await assert.rejects(parse(pgn));
});
