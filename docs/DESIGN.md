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

The reference CSS and markup are scoped to `.public-game`; existing host styles remain independent. Controls share one row: Start, Back, Forward, Live, Flip, and the copied notation toggle. `Live` retains the asynchronous game's existing meaning rather than becoming the reference's `Final` action.

The toggle opens displayed-position metadata below the live summary and move controls in the information column at every viewport size: opening, selected/total ply with move number, last move, and status. Copy FEN and Copy PGN sit below those facts; no raw notation is rendered. Exports follow the selected history ply, Live, or a legal unsubmitted preview. The standalone PGN import service has been removed. Pressed/expanded state stays synchronized and the icon remains mounted.

Button widths respond to the available board width. At widths up to 360px, content-based flex sizing prevents the Forward label from overflowing; toggle dimensions and styling remain unchanged. Keyboard labels, focus handling, and the wider board are preserved.

Reference screenshots were inspected at 390/1280px. `tests/browser_compact_controls.py` verifies extracted dimensions, the exact icon, on/off geometry and colors, keyboard operation, displayed FEN/PGN export during history review, Live return, unsubmitted previews, clipboard errors, and submission locking at 320/390/768/1280px. Native-device and real Turnstile/PHP integration checks remain separate.

The copied SVG is 226,352 bytes uncompressed; its embedded artwork is preserved for the requested visual match. It is a static local asset, not a new runtime library.

## Game typography and content order

Public game facts and feedback share 14px regular text with 1.5 line height. Labels use 12px regular sentence case; section headings use 15px semibold; score totals use 22px semibold. Buttons retain the reference control sizing.

On mobile, order is board/navigation, live summary, move controls and asynchronous feedback, optional position details/copy controls, then all-time score. Desktop left-aligns the board and navigation/toggle in one column (up to 620px), with a 24px gap to the information column containing live summary, feedback/actions, expanded details, and score. The details node stays in that column through resizing. Opening details never displaces the live action area. Details show the last move without its timestamp; the timestamp remains in the live summary.

## Visitor guidance and record footer

The live column leads with a 20px “Visitor Turn” heading in the existing accent when visitors can play. Game ID and previous-game result are moved to a right-aligned, Unicode-capable system monospace footer across the bottom of the card (12px, #89919d).

One nonmodal message panel in the information column replaces the old instructions, separate error presentation, and board helper. It has no speech-bubble pointer or dismiss button. Errors and verification failures take priority over routine guidance and reminders; errors remain assertively announced. It guides piece/destination selection, verification, and explicit submission. After a legal candidate remains selected for 30 seconds, its reminder distinguishes missing verification from a ready submission. It never submits or verifies automatically. Review clicks show return-to-Live guidance while remaining locked. Submit phase changes use bounded glow animations; reduced motion disables them.

The action row is Submit, Refresh, Cancel. Cancel is always visible, disabled without a selection and while submission/receipt recovery is in flight. Selecting a piece or legal move enables it. The row does not wrap; the submit label can shrink/wrap within its button at narrow widths. The separate preview row appears only for a candidate move.

## Turnstile appearance

The explicitly rendered widget uses Cloudflare's supported `appearance: 'interaction-only'` option: verification runs when a legal candidate is selected, and the widget is shown when visitor interaction is required. No CSS hides or clips the challenge. The container has no fixed minimum height. Ready feedback reads “Move ready—not submitted. Choose Submit.” Token expiration, failures, server verification, and explicit submission remain unchanged. Reference: https://developers.cloudflare.com/turnstile/get-started/client-side-rendering/widget-configurations/#appearance-modes

The main action is labeled Submit; its instructions and reminder use that same label. Submit/Refresh/Cancel use 14px text. Ready Submit uses a muted blue surface (#203748), pale text (#d8f1ff), and the existing blue accent border/pulse (#7dd3ff); hover darkens to #29485e. Inactive states share the secondary dark surface. Navigation button sizing is unchanged.

Feedback panels use traffic-light states: muted orange-red for errors (#d98b73 border, #2b2020 surface, #edb8a7 text), amber for pending/action-required guidance (#c6a264/#29251b/#e6c98e), and green for ready/accepted feedback (#79ab8d/#1b2923/#b7d8c1). Error text takes priority; ready feedback continues to explicitly say the move is not submitted. Message semantics, not color alone, identify the next action.

Default selection instructions and passive host-turn waiting use a neutral gray panel (#161c24 surface, secondary text, standard border). The host-turn message explicitly says the host must move, that updates are automatic, and that visitors can play next. Amber is reserved for active selection/verification, review-interaction guidance, or states needing attention. Error and ready/accepted treatment remains red and green.
