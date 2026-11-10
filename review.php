<?php
// Independent browser-only review: no database, credentials, or game endpoints.
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>PGN review · The Internet Gambit</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <link rel="stylesheet" href="packages/pgn-review/css/pgn-review.css">
  <link rel="stylesheet" href="assets/css/annotated-review.css">
</head>
<body>
  <main class="wrap">
    <h1>PGN review</h1>
    <p><a href="index.php">Return to the live game</a></p>
    <p>Review a pasted game locally. This page does not submit moves, contact game APIs, or save your PGN.</p>
    <form id="pgnImport" class="card review-import">
      <label for="pgnInput">PGN (one standard-chess game, up to 200,000 characters)</label>
      <textarea id="pgnInput" rows="7" maxlength="200000" spellcheck="false" autocomplete="off" required></textarea>
      <div class="review-actions"><button type="submit">Review PGN</button><button id="clearReview" type="button">Clear</button></div>
      <p id="importError" role="alert"></p>
    </form>
    <noscript><p>JavaScript is required for local PGN review.</p></noscript>
    <section id="importedReview" class="pgn-review card" aria-label="Imported game review" hidden>
      <h2 id="importedPlayers">Imported game</h2>
      <p id="reviewScope">Main line only. Annotation labels, evaluations, and suggestions come from your PGN; no analysis engine runs here.</p>
      <div class="board-container"><div class="board-shell">
        <div id="importedBoard" class="board" aria-label="Imported position"></div>
        <svg id="suggestionArrow" viewBox="0 0 100 100" aria-hidden="true" focusable="false"></svg>
      </div></div>
      <div class="review-actions" aria-label="Imported game navigation">
        <button id="reviewStart" type="button">Start</button><button id="reviewBack" type="button">Back</button>
        <button id="reviewNext" type="button">Forward</button><button id="reviewFinal" type="button">Final</button>
        <button id="reviewFlip" type="button" aria-pressed="false">Flip board</button>
      </div>
      <p id="reviewPosition" role="status" aria-live="polite"></p>
      <button id="annotationToggle" type="button" aria-pressed="true">Show supplied annotations</button>
      <div id="annotationPanel">
        <p id="annotationSummary"></p><pre id="annotationComment"></pre>
      </div>
      <details><summary>Position and source PGN</summary>
        <p>FEN at the selected ply</p><pre id="reviewFen"></pre><button id="copyPosition" type="button">Copy position FEN</button>
        <p>Original PGN, including supplied annotations</p><pre id="reviewSource"></pre><button id="copySource" type="button">Copy original PGN</button>
        <span id="reviewCopyStatus" role="status"></span>
      </details>
    </section>
  </main>
  <script src="assets/chess.min.js"></script>
  <script src="assets/board_id_map.js"></script>
  <script type="module" src="assets/annotated_review.js"></script>
</body>
</html>
