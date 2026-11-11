# UI refinement validation

Validated November 10, 2026 against baseline `d34b91ecaaab9fa50b3a29c59339091e443f8df2`.

Local frontend checks pass. PHP integration, real Turnstile, native zoom, assistive-technology, and physical-device checks remain open. This is not a WCAG certification or a production performance/deployment sign-off.

## Scope and isolation

- Public-page changes are in `index.php` and `assets/css/style.css`.
- PHP logic, API endpoints, database code, rules library, and `assets/submission.js` are unchanged. One PHP documentation comment correctly describes coordinate-based submission.
- Browser fixtures strip PHP and supply synthetic API/Turnstile responses. The fixture restores the page's literal title to avoid an empty-title warning absent from PHP output.
- Requests are intercepted. No real moves, emails, credentials, database writes, or production API mutations occur.
- axe-core 4.14.0 was injected only into test pages from a separate scratch bundle retrieved from the official npm registry with package integrity verified. No production dependencies or assets were added.

## Corrections from validation

- Added a main landmark and keyboard skip link to the public board.
- Added valid group/region semantics to named player, promotion, and notation containers.
- Native public control buttons and Back to top have at least 44px hit areas. Board squares remain proportional and are checked separately by the WCAG target-size rule.
- Replaced low-contrast board outlines with darker markers, retaining dashed destination cues and a two-tone focus ring. Computed selection/destination contrast is **13.65:1 on light squares / 3.55:1 on dark squares**; last-move contrast is **12.30:1 / 3.20:1**.
- Preserved essential checkerboard colors under forced-colors emulation.
- Removed public-page background scaling that enlarged the 320px document scroll area by approximately 5px. Shared host/review styling remains independent.

## Executed checks

Chromium: `156.0.8078.79`. Axe: `4.14.0`.

| Check | Result |
| --- | --- |
| Node chess-rule and annotation suites | 77 tests passed |
| `tests/browser_interactions.py` | Passed at 320/390/768/1280px for White/Black, plus recovery, failure, promotion, and completion cases |
| Axe WCAG A/AA tags through 2.2 and best practices | 32 state/viewport scans; zero automatic violations |
| Accessibility tree | 64 named square controls; collapsed Details controls absent; expanded controls present |
| Keyboard and target sizes | Skip link, roving board focus, cancellation, promotion, disclosure/notation; native control targets at least 44px |
| Reflow | No document or stage overflow at 320/390/768/1280px and 844×390 landscape |
| Text stress | No overflow with 200% inherited root text; this is not native browser text zoom |
| Reduced motion | Spinner disabled, transitions reduced, disclosure scrolling uses `auto` |
| Forced colors | Checkerboard remains distinguishable under Chromium emulation |
| Shared CSS samples | Host/review class geometry and computed styles match baseline at 320/390/1280px |
| Actual standalone review frontend | Import, supplied annotations, escaping, flip, final position, clear at 320/390/1280px; PHP headers stripped |
| ECO-lite data | 371 entries, 40,948 bytes; validation passed |
| Static checks | Inline JS syntax, Python test parsing, unique IDs, unchanged PHP logic/backend/transport, diff whitespace checks |

The eight accessibility states are initial, candidate awaiting CAPTCHA, verified candidate, expired CAPTCHA, history, history with live notation, promotion, and game over.

**Incomplete automated checks:** text `color-contrast` review items remain because axe cannot reliably resolve layered pseudo-element/image backgrounds. They are retained in the JSON report and are not counted as passes. Composited text contrast and real Cloudflare iframe accessibility require manual review. Board-marker contrast is checked independently against both actual CSS square colors.

## Bounded performance checks

The quality suite measures 20 background polls, with garbage collection before/after. The live element count remains 309; reported renderer DOM nodes remain 691 and event listeners remain 271. Playwright selector instrumentation is initialized first so its own listeners are not mistaken for an application leak. Detailed CDP script/layout measurements are written to the requested JSON report.

The fixture also verifies one bundled engine load, WebP selection instead of JPEG, and zero CAPTCHA widgets before selection.

These are local headless diagnostics, not total CPU time, load latency, paint/compositing cost, a long-term leak test, or a mobile-device benchmark. API and Turnstile responses are mocked. Production network/CAPTCHA timing and compressed transfer sizes remain unmeasured.

Compared with baseline, the public page source grew by approximately 15KB, including approximately 14KB of inline JavaScript; CSS grew by approximately 5.4KB, uncompressed. No fonts, piece images, engine assets, or production libraries were added. Existing WebP/JPEG backgrounds are 216,176 / 867,137 bytes.

## Reproduce frontend checks

Requires existing Node, Python, Playwright, Chromium. Supply an existing axe-core bundle; tests never download/install dependencies.

```sh
node --test tests/chess_rules.test.js tests/pgn_annotations.test.js
python3 scripts/validate_eco_lite.py
python3 tests/browser_interactions.py
AXE_CORE_PATH=/path/to/axe.min.js python3 tests/browser_ui_quality.py --report /tmp/chess-ui-quality.json
```

Without an axe bundle, the quality suite explicitly skips axe scans while running structural checks. Do not report that run as automated WCAG scanning.

## Remaining checks before release

- [ ] Run `php -l index.php` and PHP-backed suites in an isolated PHP 8+ / PDO SQLite / Node environment. PHP, the scoped package installer, and sudo are unavailable here.
- [ ] Run `PHP_BIN=php python3 tests/notification_recovery.py`, which includes earlier backend cases: endpoint authorization, legality, revision/lock concurrency, receipts, scores, transitions, and stubbed notifications.
- [ ] Run the existing PHP-backed `browser_chess.py`, `browser_flip.py`, `browser_history.py`, `browser_review.py`, `browser_recovery.py`, and `browser_annotations.py` fixtures. Relevant UI expectations were updated; mocked frontend checks do not replace these suites.
- [ ] With staging credentials and a disposable game, verify real Turnstile loading, explicit rendering, challenge interaction, expiry, timeout/error recovery, blocked script, reset/removal, and server rejection. Keep anti-abuse validation enabled.
- [ ] Test actual screen readers, such as NVDA/Firefox and VoiceOver/Safari: board entry/navigation, square names, focus restoration, promotion, announcements, live/history distinction.
- [ ] Test native 200% text zoom and 400% browser zoom, font overrides, high contrast, and long-content wrapping. Viewport reflow and inherited-text stress are proxies.
- [ ] Test iOS Safari and Android Chrome on devices: touch accuracy, landscape scrolling, keyboard/viewport behavior, CAPTCHA sizing, accidental submission prevention.
- [ ] Manually measure text/focus contrast on actual composited backgrounds and inspect state cues without color.
- [ ] Profile staging with slow network/CPU: loading/errors, layout shifts, background blur/compositing, long histories, extended polling, and the real CAPTCHA iframe.
- [ ] Verify ownership, all promotions, canonical FEN/PGN exports, Live return, successor orientation, and unknown-outcome retries against real isolated endpoints.

No production deployment or commit was made during validation.

## Simplification follow-up — November 11, 2026

- Removed the Details button, panel, persisted disclosure state, and Advanced/Notation hidden labels. Opening and full-move number are directly visible; FEN/PGN has its own compact toggle and Import PGN link.
- Ownership appears once in the subhead. Live status and latest move remain in the game summary; review-specific facts appear only while reviewing history. The extra Live chip is hidden in live mode.
- Normal instructions use one feedback line. Transient loading, errors, and confirmations replace it; errors are announced assertively through that same feedback area. The duplicate error panel was removed.
- Visible keyboard help was removed; the board retains an assistive description, keyboard navigation, and focus handling. Cancel shares the selected-move row and is hidden before selection.
- The live server returned `Cache-Control: max-age=2592000` for CSS and its current stylesheet matched the repository. This supports the stale-browser-style diagnosis from the screenshots. The public stylesheet URL now includes its content hash, so CSS changes request a new URL without requiring a hard refresh.
- Fixed narrow-screen FEN/PGN overflow after removing the old panel wrapper.
- Re-ran interaction checks at 320/390/768/1280px for both sides, all 77 rule/annotation tests, and all 32 axe state/viewport scans: no automatic violations. Composited text-contrast review remains incomplete.
- Shared-style and standalone review checks passed. After 20 polls, 288 live elements, 621 renderer DOM nodes, and 270 listeners remained stable after garbage collection. These are fixture diagnostics with the same limitations described above.
- PHP business logic, APIs, and submission transport remain unchanged. The stylesheet hash expression is the only added PHP code; PHP lint/integration and real-device/staging checks remain outstanding.

## Simpler board markers — November 11, 2026

- Replaced the public board's dark outlines and dashed destination boxes with soft green selection fills, olive last-move fills, small destination dots. Capture rings were removed after visual review.
- Legal destination hints include en passant and clear when a selection changes or is cancelled. Captures retain their accessible description and use the common destination-dot styling. The additional class and accessible capture description are presentation only; legal moves and submission behavior are unchanged.
- Kept the distinct two-tone keyboard-focus indicator. Contrast checks now test destination dots with their alpha composited against both square colors; they do not claim the translucent fills satisfy a 3:1 boundary contrast threshold. Composited visual review remains part of the manual checklist.
- Interaction checks, capture/en-passant checks, all 32 axe state/viewport scans, shared-style/review checks, and bounded polling checks passed. No automatic accessibility violations; manual contrast, real-device, and PHP/staging limitations remain as above.

## Compact reference controls — November 11, 2026

- Matched the notebook viewer's 40px desktop and 44px narrow-screen history controls, compact text/padding, icon-only Flip button, and exact red/green sliding toggle. Source measurements and provenance are in [DESIGN.md](DESIGN.md). Other public action buttons now use compact intrinsic widths.
- The toggle opens the existing live FEN/PGN panel immediately below the history controls. Its pressed/expanded state and keyboard operation stay synchronized; viewing history still blocks move submission.
- Copied the original 226,352-byte SVG artwork unchanged. This adds a static asset, with no new runtime library.
- Focused control checks passed at 320/390/768/1280px. Interaction checks and all 32 axe state/viewport scans passed, along with shared-style, standalone review, landscape, reduced-motion, and bounded-polling checks. The target-size check now uses the 24px WCAG AA minimum; the reference's 40px desktop controls supersede the earlier blanket 44px expectation.
- Visually reviewed isolated mobile screenshots with the toggle off and on. PHP/staging, real CAPTCHA, physical-device, and manual contrast checks remain outstanding. Server logic and submission transport are unchanged.

## Position details and copy-only exports — November 11, 2026

- The slider now expands displayed-position metadata with Copy FEN and Copy PGN below it. Raw notation readouts and the Import PGN link are removed. Deleted the standalone import endpoint, its frontend/CSS, and its retired browser tests; shared chess/review libraries remain.
- Copy reads the board at click time. PGN is replayed through the selected ply and includes a legal unsubmitted preview; returning to Live restores current exports. PGN copying fails with accessible feedback if replay cannot match the displayed FEN, rather than exporting a different position. Clipboard fallback restores focus and removes its temporary textarea.
- Focused checks passed at 320/390/768/1280px for sizing, keyboard disclosure, live/history/start/preview exports, Live return, cancellation, clipboard failure, and history submission locking. Full interaction checks passed for both sides, promotion, stale/invalid state, submission concurrency, verification gating, and game-over messaging.
- All 32 axe state/viewport scans passed without automatic violations. Shared-style, reduced-motion, forced-color, text-stress, landscape, and bounded-polling checks passed. Visually reviewed the expanded mobile panel. JavaScript syntax and diff checks passed; existing PHP blocks are unchanged.
- Physical-device, real CAPTCHA, manual contrast, and PHP/staging checks remain outstanding. No merge or production deployment performed.

## Consolidated ply information — November 11, 2026

- Removed the history info pill and earlier-position banner. Selected/total ply and move number now share the details panel's Ply row, hidden with the slider closed. Move numbering follows the reference viewer's completed/selected move convention (`ceil(ply / 2)`, minimum 1). The disabled submission hint is shortened to “Return to Live to play.”
- History failures use the existing accessible error feedback; history submission locking, navigation, and copy exports are retained.
- Focused controls/exports, full interaction checks, all 32 axe scans, shared styles, reduced motion, forced colors, reflow, landscape, and bounded polling passed. A targeted check confirmed both live and reviewed ply text and closed-panel visibility after the numbering adjustment. Mobile screenshot visually reviewed. Physical-device/PHP/staging/manual checks remain outstanding. Not merged or deployed.

## Typography and information order — November 11, 2026

- Unified game labels (12px regular sentence case), values/feedback (14px regular), section headings (15px semibold), and score totals (22px semibold). Reference button sizing and board coordinates remain unchanged.
- Moved expandable details below live status/actions and above all-time score, preserving this order in the desktop rail and mobile document flow. Removed the duplicate timestamp from details.
- Focused tests verify document order, computed type styles, disclosure, history/preview exports, clipboard failures, and submission locking at 320/390/768/1280px. All passed. All 32 axe scans, shared styles, reflow/text stress, reduced motion, forced colors, landscape, and bounded polling passed. Mobile and desktop screenshots visually reviewed; JavaScript syntax and diff checks passed. No backend or submission behavior changed.
- Physical-device, real CAPTCHA, manual contrast, and PHP/staging checks remain outstanding. Not merged or deployed.

## Desktop column alignment — November 11, 2026

- Desktop board, history controls/toggle, and expanded details share a left-aligned column up to 620px wide, with a 24px gutter to the live-game rail. Removed the board container's automatic centering at desktop widths.
- At the 1024px breakpoint, the existing details node moves between the board column and its mobile location before the score. This retains one set of IDs/handlers, disclosure state, and focused copy control through resizing. Mobile action-first order is preserved.
- Focused tests passed at 320/390/768/1280px, including matching desktop edges/widths, details ordering, resize/focus/disclosure preservation, copy exports, and history locks. All 32 axe scans, reflow/text stress, reduced motion, forced colors, shared styles, landscape, and bounded polling passed. Desktop screenshot visually reviewed; JavaScript syntax and diff checks passed.
- Real-device, manual contrast, real CAPTCHA, and PHP/staging checks remain separate. No merge or deployment performed.

## Visitor heading, record footer, and move coaching — November 11, 2026

- Replaced the top game-number heading with a prominent Visitor Turn state. Game number and previous result now live in the bottom-right record footer.
- Added dismissible contextual move coaching and one reminder after 30 seconds per legal candidate. The reminder retains CAPTCHA gating and distinguishes pending verification from ready submission. Cancelling, submitting, history review, stale/unavailable state, and game completion clear eligibility. Phase-based Submit animations are bounded and disabled by reduced-motion preferences.
- Review squares accept explanatory clicks while selection/submission remain locked. Keyboard activation uses the same controller guard. No engine, backend, receipt transport, or anti-abuse logic changed.
- New isolated tests passed at 390/1280px for piece guidance, exact 30-second timing, verification gating, ready phase, reduced motion, dismissal, cancellation, post-submit cleanup, review-click feedback, and return to Live. Focused layout/export checks passed at 320/390/768/1280px. Full interaction checks passed, and all 32 axe scans plus shared-style, reflow, reduced-motion, forced-color, landscape, and bounded-polling checks passed. Desktop/mobile screenshots visually reviewed; JavaScript syntax and diff checks passed.
- Real-device, real CAPTCHA, manual contrast, screen-reader, and PHP/staging checks remain outstanding. No merge or deployment performed.

## Unified information-column feedback — November 11, 2026

- Moved move coaching into the existing instruction/error area of the information column. One styled panel shows either transient status/errors or contextual instructions/reminders, without duplicate visible messages. Removed the speech-bubble pointer, close button, and dismissal state. Verification-expiration/failure guidance takes priority over generic selected-move coaching; assertive error announcements remain.
- Expanded game details stay below live actions and above score in the information column at every width. The toggle stays under the board. Removed breakpoint-driven node relocation; desktop board/control alignment is retained.
- Focused controls/export tests passed at 320/390/768/1280px, including right-column details alignment and focus/disclosure across resizing. Coaching tests passed at 390/1280px for shared error/instruction presentation, no pointer/close control, reminder timing, CAPTCHA gating, cancellation/submission cleanup, reduced motion, and history guards. Full interaction checks and all 32 axe scans passed, along with shared styles, reflow/text stress, reduced motion, forced colors, landscape, and bounded polling. Mobile and desktop screenshots visually reviewed. JavaScript syntax and diff checks passed.
- Physical-device, real CAPTCHA, manual contrast/screen-reader, and PHP/staging checks remain separate. Not merged or deployed.

## Stable Cancel action — November 11, 2026

- Cancel now shares the Submit/Refresh row and stays visible, disabled until a piece or move can be cancelled. Submission and unresolved-receipt safeguards still disable cancellation. The preview row no longer appears merely to hold Cancel.
- Focused checks passed at 320/390/768/1280px for same-row action geometry and initial disabled visibility. Full interaction checks passed for cancellation, promotion, receipt recovery/concurrency, stale state, verification, and history locks. A targeted 320px check verified selecting/cancelling and same-row Retry/Refresh/Cancel geometry without overflow. All 32 axe scans, reflow/text stress, reduced motion, forced colors, shared styles, landscape, and bounded polling passed. Narrow retry screenshot visually reviewed; JavaScript syntax and diff checks passed.
- Real-device, real CAPTCHA, manual accessibility, and PHP/staging checks remain separate. Not merged or deployed.

## Protect unsubmitted moves — November 11, 2026

- Board reselection, history navigation, and manual refresh now ask “Cancel move?” before replacing a legal candidate. Native modal semantics provide a dark backdrop and keyboard containment; Keep move is focused initially, and Escape preserves the candidate. Confirmation restores the base position and continues the requested action. Safe inspection, verification, and submission remain usable.
- The dialog drops its deferred action if the candidate disappears or submission/receipt recovery begins. Polling and stale-state enforcement continue; no submission is triggered by the confirmation flow.
- Dedicated tests passed at 320/390/1280px for candidate preservation, Tab/Shift+Tab, Escape/Keep, confirmed board reselection and history navigation, safe controls, and successful single submission. Modal axe scans passed at all three widths. Full interaction tests, coaching tests, and all 32 general axe scans passed, including stale-refresh confirmation, receipt recovery, concurrency, promotion, and history locks. Shared styles, reflow/text stress, reduced motion, forced colors, landscape, and bounded polling passed. Mobile/desktop overlay screenshots visually reviewed; JavaScript syntax and diff checks passed.
- Real-device, real CAPTCHA, manual screen-reader/contrast, and PHP/staging checks remain outstanding. Not merged or deployed.
