# Third-Party Notices

This package expects the following third-party assets to be provided by the host site:

## chess.js

- **Library:** chess.js
- **Source in this repo:** `assets/chess.min.js`
- **Usage:** Provides the global `Chess` constructor used for PGN parsing and move generation.

## Piece SVG set

- **Assets:** Lichess-style SVG pieces
- **Source in this repo:** `assets/pieces/lichess/`
- **Usage:** Rendered for chess piece images. The review kit expects these files to be hosted under `${assetsBaseUrl}pieces/lichess/`.
