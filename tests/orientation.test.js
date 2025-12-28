const assert = require('assert');
const {
  orientationLayout,
  squareMatrix,
  playerPerspectiveSquare,
} = require('../assets/ui_helpers.js');
const { Chess } = require('../assets/chess.min.js');

function testOrientationLayout() {
  const white = orientationLayout('white');
  const black = orientationLayout('black');

  assert.deepStrictEqual(white.files, ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h']);
  assert.deepStrictEqual(white.ranks, [8, 7, 6, 5, 4, 3, 2, 1]);

  assert.deepStrictEqual(black.files, ['h', 'g', 'f', 'e', 'd', 'c', 'b', 'a']);
  assert.deepStrictEqual(black.ranks, [1, 2, 3, 4, 5, 6, 7, 8]);
}

function testMatrixRotation() {
  const whiteMatrix = squareMatrix('white');
  const blackMatrix = squareMatrix('black');

  for (let r = 0; r < 8; r++) {
    for (let c = 0; c < 8; c++) {
      assert.strictEqual(blackMatrix[r][c], whiteMatrix[7 - r][7 - c]);
    }
  }
}

function testPerspectiveSquares() {
  assert.strictEqual(playerPerspectiveSquare(0, 0, 'white'), 'a1');
  assert.strictEqual(playerPerspectiveSquare(7, 0, 'white'), 'h1');
  assert.strictEqual(playerPerspectiveSquare(0, 0, 'black'), 'h8');
  assert.strictEqual(playerPerspectiveSquare(4, 1, 'black'), 'd7');
}

function testSanAndFenAreConsistentAcrossOrientations() {
  const whiteGame = new Chess();
  const whiteFrom = playerPerspectiveSquare(3, 1, 'white'); // d2
  const whiteTo = playerPerspectiveSquare(3, 3, 'white'); // d4
  const whiteMove = whiteGame.move({ from: whiteFrom, to: whiteTo });
  assert.strictEqual(whiteMove.san, 'd4');
  const whiteFen = whiteGame.fen();

  const blackGame = new Chess();
  blackGame.load('rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR b KQkq - 0 1');
  // From the black perspective, the queen pawn is on file index 4 (mirrored).
  const blackFrom = playerPerspectiveSquare(4, 1, 'black'); // d7
  const blackTo = playerPerspectiveSquare(4, 3, 'black'); // d5
  const blackMove = blackGame.move({ from: blackFrom, to: blackTo });
  assert.strictEqual(blackMove.san, 'd5');
  const blackFen = blackGame.fen();

  // Boards should mirror each other after symmetric double-pawn pushes.
  assert.strictEqual(
    whiteFen.split(' ')[0],
    'rnbqkbnr/pppppppp/8/8/3P4/8/PPP1PPPP/RNBQKBNR'
  );
  assert.strictEqual(
    blackFen.split(' ')[0],
    'rnbqkbnr/ppp1pppp/8/3p4/8/8/PPPPPPPP/RNBQKBNR'
  );
}

testOrientationLayout();
testMatrixRotation();
testPerspectiveSquares();
testSanAndFenAreConsistentAcrossOrientations();

console.log('orientation tests passed');
