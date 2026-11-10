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
