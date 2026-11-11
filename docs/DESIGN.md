# Reference chess controls

Source: [pmicka.com chess note](https://pmicka.com/notes/2026-01-27-chess-test/), inspected November 11, 2026, and `pmicka/notebook` commit `7b92d91056847650ed03db2c393414a43ada7def`.

Implementation sources:

- `public/assets/vendor/pgn-review/css/pgn-review.css`: compact buttons, history row, flip control, and notation-toggle rules.
- `public/assets/vendor/pgn-review/pmicka/init-pgn-review.js`: flip SVG and toggle markup.
- `public/assets/vendor/pgn-review/assets/annot-pill.svg`: copied byte-for-byte into `assets/icons/annot-pill.svg`. SHA-256: `2ea3284d0d4e5b71da1ae297be2b8e60d7cff2c724d8d28c90851d8e6a8ed85b`.

## Extracted dimensions

| Property | Above 520px | Up to 520px |
| --- | --- | --- |
| History-control height | 40px | 44px |
| History-button font | 13px, weight 700 | 11px, weight 700 |
| Text-button padding | 0 12px | 0 3px |
| Button radius | 8px | 8px |
| Toggle width | 68px | 60px |
| Knob diameter | 36px | 40px |
| Toggle/knob radius | 999px / 50% | 999px / 50% |

Other compact controls use 13px text, 1.4 line height, 8px 12px padding, and a 40px minimum height. Their width follows the label. Disabled opacity is 0.45.

The toggle uses `#ff6b6b` off and `#6cd69b` on, a `#0f141d` knob, `#202631` inner disc, the reference inset shadows/border, and a 180ms left-position transition. Reduced-motion preferences override that transition.

## Integration

The reference CSS and markup are scoped to `.public-game`; existing host and separate-review styles remain independent. Controls share one row: Start, Back, Forward, Live, Flip, and the copied notation toggle. `Live` retains the asynchronous game's existing meaning rather than becoming the reference's `Final` action.

The toggle opens the existing canonical live-game FEN/PGN view directly below the history controls. Its pressed/expanded state stays synchronized and its icon remains mounted. Opening and move-number information stay visible in the compact summary; the removed Details wrapper is not restored.

Button widths respond to the available board width. At widths up to 360px, content-based flex sizing prevents the Forward label from overflowing; toggle dimensions and styling remain unchanged. Keyboard labels, focus handling, and the wider board are preserved.

Reference screenshots were inspected at 390/1280px. `tests/browser_compact_controls.py` verifies extracted dimensions, the exact icon, on/off geometry and colors, keyboard operation, canonical FEN export during history review, and submission locking at 320/390/768/1280px. Native-device and real Turnstile/PHP integration checks remain separate.

The copied SVG is 226,352 bytes uncompressed; its embedded artwork is preserved for the requested visual match. It is a static local asset, not a new runtime library.
