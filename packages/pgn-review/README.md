# PGN Review Kit (Read-only)

This package contains a read-only PGN review kit extracted from the main chess app. It renders a board, forward/back/live controls, opening explorer snapshot, and a notation panel without any gameplay, submission, CAPTCHA, or polling. It is designed for non-iframe embeds on pmicka.com.

## What it depends on

- **Chess engine:** a global `Chess` constructor from `assets/chess.min.js` (chess.js).
- **ECO dataset:** `${assetsBaseUrl}data/eco_lite.json`.
- **Piece SVGs:** `${assetsBaseUrl}pieces/lichess/` (e.g. `wK.svg`, `bQ.svg`).

## API

```js
import { mountPgnReview } from './src/pgn-review.js';

const instance = mountPgnReview(containerEl, {
  pgn: '1. e4 e5 2. Nf3 Nc6',
  initialFen: 'start',
  initialPly: 6,
  orientation: 'white',
  showExplorer: true,
  showNotation: true,
  allowStorage: true,
  assetsBaseUrl: '/assets/vendor/pgn-review/',
});

instance.setPgn('1. d4 d5');
instance.destroy();
```

## Integrating into pmicka.com

1. Copy these directories into `pmicka.com` at `assets/vendor/pgn-review/`:
   - `packages/pgn-review/src/`
   - `packages/pgn-review/css/`
   - `packages/pgn-review/pmicka/`
2. Copy the required assets into `assets/vendor/pgn-review/`:
   - `assets/chess.min.js` (or another chess.js build that exposes `window.Chess`).
   - `assets/data/eco_lite.json`.
   - `assets/pieces/lichess/` SVGs.
3. Include the CSS and scripts:

```html
<link rel="stylesheet" href="/assets/vendor/pgn-review/css/pgn-review.css" />
<script src="/assets/vendor/pgn-review/chess.min.js"></script>
<script type="module" src="/assets/vendor/pgn-review/pmicka/init-pgn-review.js"></script>
```

4. Ensure your PGN blocks look like:

```html
<pre><code class="language-pgn">1. e4 e5 2. Nf3 Nc6</code></pre>
```

The initializer will insert a `.pgn-review` container after each PGN block and mount the viewer.

## Security posture

The review kit is read-only. It does not submit moves, does not render CAPTCHA widgets, and does not poll `/api/state.php`. The only network call is a same-origin fetch for `eco_lite.json`.
