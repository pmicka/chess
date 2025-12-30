# Anti-scrape verification checklist

Lightweight steps to confirm the board DOM does not leak obvious chess semantics while keeping the experience usable and accessible.

## Manual checks

- Load the visitor board and inspect the DOM:
  - **No algebraic squares** (`a1`-`h8`) appear in element IDs, data attributes, or text.
  - **No piece names** (pawn, knight, bishop, rook, queen, king) appear in class names or attributes.
  - **No FEN/PGN/SAN strings** appear unless the notation panel has been explicitly opened.
- Interactions still work:
  - Select a piece and destination, submit a move.
  - Board flip/orientation behaves as expected.
  - Refreshing the page preserves the session-scoped square mapping for the session.
- Accessibility:
  - The board container advertises an appropriate role.
  - Squares have a minimal, non-descriptive label (e.g., “Board square”) without coordinates or piece names.

## Automated scan helper

Use `scripts/dom_scan.js` to surface obvious leaks in a rendered HTML response:

```bash
node scripts/dom_scan.js http://localhost:8000/index.php
```

The script fetches the target URL (or reads a local file path) and reports counts of:

- Algebraic square tokens: `\b([a-h][1-8])\b`
- Notation keywords: `fen`, `pgn`, `san`

False positives are possible; investigate and confirm any hits.

## Notes

- Square identifiers in the DOM are opaque, session-random IDs only.
- FEN/PGN output is gated behind a user-triggered toggle; when hidden, the markup is removed from the DOM.
- Canonical game state lives in script-local scope (not on `window` or data attributes).
