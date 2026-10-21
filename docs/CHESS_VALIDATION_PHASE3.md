# Phase 3: authoritative chess validation

Built on merged Phase 1/2 commit `aed7178bbf1ae2f37c188ae493508d4f83fd055a`.
No production requests, database edits, credentials, emails, resets, dependency
upgrades, branding changes, or UI layout changes were used in this phase.

## Submission contract and atomicity

Both move endpoints retain their existing authentication, CAPTCHA, game ID,
integer revision, writer reservation, conditional update and token lifecycle.
They accept coordinates plus an explicit `q`, `r`, `b`, or `n` promotion when
required. Malformed coordinates are not truncated into legal ones. Invalid,
missing-required, and spurious promotions are rejected. Optional client `move`
and `last_move_san` fields are ignored; clients cannot submit FEN or PGN.

`lib/chess.php` invokes `scripts/chess_rules.js`, which uses the **unchanged**
vendored `assets/chess.min.js`. The engine verifies canonical FEN/turn and stored
PGN history before matching the exact requested move against legal moves. It
generates SAN, resulting FEN, next turn, PGN and terminal status. These values
are persisted together in the existing SQLite transaction. Terminal outcomes,
scores and completion capabilities remain part of that transaction. Client SAN
cannot determine notation or results. Terminal PGN includes the generated result.

The old PHP board transformer and handwritten PGN formatter are removed. The
remaining `apply_move_to_fen()` compatibility helper also validates legality;
the endpoints use the full history-aware operation instead.

Illegal moves return `400 illegal_move`; malformed request fields retain their
specific 400 codes. Inconsistent stored history, invalid FEN or FEN/turn
disagreement returns `409 state_inconsistent`. Engine failures return
`503 chess_unavailable`. These failures roll back without changing games, locks,
tokens, results or scores and send no mail. A valid host credential remains
usable after an illegal move or validator failure. Existing stale-version and
authentication responses remain in force.

The existing special case for a verifiably terminal position still marked
`active` remains: an eligible submission finalizes that game and emails a
completion capability, returning `409 GAME_OVER` without applying the requested
move. It never edits FEN/PGN. This is distinct from a rejected illegal move or
validator failure. Read-only requests and the deployment check never finalize it.

## History, legacy state and draws

FEN remains authoritative for the playable position. PGN is replayed to verify
that it reaches exactly that FEN, including turn, castling, en passant and clocks.
A failed `load_pgn()` is rejected even if it loaded a valid prefix. An empty PGN
is valid only at the initial position. Explicit `[SetUp "1"]` / `[FEN "..."]`
PGN setups are supported and validated; they are never invented to disguise a
missing history. Basic FEN semantic checks reject missing/duplicate or adjacent
kings, a previous mover left in check, back-rank pawns, impossible castling rights
and inconsistent en-passant metadata.
Declared PGN results must agree with the detected position; unknown `*` results
are permitted. Terminal moves write their actual result into exported PGN.

Existing rows are not repaired, replayed into replacement state, or rescored on
deployment. After a legal move, engine PGN serialization preserves the verified
move sequence and headers, normalizing notation/formatting. Because the vendored
serializer drops comments, variations and annotation glyphs/NAGs, such legacy
content is refused unchanged for explicit review. This application's existing
writer generates plain main-line SAN; this phase does not silently discard
separately annotated PGN.
Neither replay nor this phase proves the authenticity of pre-existing historical
requests. A legacy discrepancy needs separate, explicit reconciliation; never
reset a game, discard a final ply, or choose PGN over FEN automatically.

Checkmate, stalemate, insufficient material, fifty-move and threefold draws use
the validated position/history. The existing application policy automatically
ends the game at 100 halfmoves or three occurrences; this phase does not add
FIDE claim/offer workflows. Repetition excludes clocks and ignores an en-passant
target when no **legal** en-passant capture exists, including pinned captures.
Next-game fallback result detection now uses verified PGN as well as FEN, so a
repetition draw can be established when a result row is absent. Existing result
rows and transition receipts retain their Phase 2 behavior.

## Runtime and failure handling

- PHP 8+, PDO SQLite and the Phase 2 schema are required. No schema changes occur
  in Phase 3. `proc_open()` and its process/pipe functions must be enabled for the
  web PHP user, with permission to execute Node and read both engine files.
- Node.js 18+ is required; use a maintained release. Default
  executable is `node` from PATH. An optional `CHESS_NODE_BINARY` constant in
  `config.local.php` selects an absolute executable path; no shell command flags.
- PHP passes argv as an array and FEN/PGN through stdin. No shell, host credential,
  external URL, external service or package lookup is part of validation.
- Validation has a three-second process deadline, nonblocking stdin/stdout/stderr,
  and a combined 128 KiB output cap. Input is capped at 128 KiB JSON, PGN at 64 KiB
  and verified history at 2,048 plies. Larger state requires review; it is not
  truncated. Failed or timed-out processes are terminated. Missing Node, missing
  assets, disabled process execution, nonzero exits and malformed responses all
  fail closed. There is no fallback to client notation or the old transformer.
- The existing SQLite writer reservation remains held during validation; other
  writers can receive the existing bounded `database_busy` response. Durable mail
  outbox and reconciliation of lost successful responses remain Phase 4 work.
- `scripts/fen_status.js` remains a diagnostic compatibility CLI using the same
  validator. FEN alone cannot establish repetition; runtime moves/transitions
  provide PGN.

## Deployment and rollback — procedure only, not executed

1. Confirm Phase 2 was actually deployed and migrated. If not, follow its separate
   backup, database-path verification, score import and legacy-link revocation
   procedure first. Do not run bootstrap or migration to repair chess history.
2. Pause writes and make a consistent SQLite/configuration backup. Keep any legacy
   PGN annotations in that backup. Confirm Node and process execution under the
   **web** PHP user, whose PATH/disabled functions may differ from CLI.
3. On a private staging copy with this complete code bundle, run:

   ```bash
   php scripts/check_chess.php --database=/verified/private/chess.sqlite
   ```

   This opens that existing file read-only and checks the latest game, including
   its stored status. It prints only game/revision/status metadata, never a link,
   token, FEN or PGN. Missing/unmigrated databases, unavailable engine and state
   disagreements fail without writes. Status disagreement also requires review.
   The script is CLI-only (404 over HTTP), creates no credentials and sends no mail.
4. Resolve any legacy inconsistency through a separate reviewed data plan before
   resuming writes. Do not silently create setup headers, change clocks/castling,
   drop moves, recompute old scores or reset. The check does not scan/rewrite every
   historical finished game; current state is the deployment gate.
5. Run the isolated tests under the deployment runtimes. Deploy both revised move
   endpoints, `api/host_next_game.php`, `db.php`, `lib/chess.php`, `lib/http.php`,
   `scripts/chess_rules.js` and the existing unchanged chess.js asset together.
   Keep CLI diagnostics private. No dependency upgrade or new DB migration is
   necessary. Verify ordinary legal moves and failures in staging, then resume.

If validation fails after deployment, keep writes paused and prefer a forward
fix to the runtime/path/configuration. Do not restore a pre-Phase-1 release or
re-enable the insecure PHP transformer as a fallback. Phase 3 adds no schema, so
it does not require a down migration, but reverting to Phase 2 reopens the known
legality/SAN vulnerability and is unsuitable for public writes. Do not restore
an old DB over post-deployment moves. No revocation or operator provisioning was
performed in this phase; future credential handling follows Phase 2 separately.

## Reproducible verification

```bash
node --test tests/chess_rules.test.js
PHP_BIN=php python3 tests/chess_legality.py
# Optional, only when Playwright/Chromium are already installed:
PHP_BIN=php CHROMIUM_BIN=/usr/bin/chromium python3 tests/browser_chess.py
```

The HTTP suite includes all Phase 1/2 regressions and uses temporary SQLite,
synthetic tokens, stub mail and mocked Turnstile, with real local Node/chess.js.
Failure cases substitute private local executables. Native mail and legacy shell
execution remain disabled; external PHP network access is intercepted/disabled.
Browser routing permits only that fixture's localhost server. Desktop/mobile
checks falsify client SAN, exercise promotion, and retry after engine failure.

Baseline tests first demonstrated that both old endpoints accepted impossible
moves and persisted falsified SAN. Final execution counts and environment are
recorded in the implementation handoff. Production deployment/runtime support,
real email delivery and unprovided legacy production data remain unverified.

## Execution on 2026-10-21

Environment: Linux, PHP 8.4.24 with PDO SQLite, Node.js 24.19.0, existing
Playwright/Chromium. Other PHP/Node versions and the production web runtime were
not executed. No test dependency was installed or upgraded.

| Check | Outcome |
| --- | --- |
| Node rule/protocol and standard perft regressions | PASS — 54 tests |
| HTTP/SQLite regressions, including all 61 Phase 1/2 cases | PASS — 85 tests (parameterized subcases additional) |
| Desktop 1280×900 and mobile 390×844, both boards | PASS — 16 browser scenarios |
| PHP syntax | PASS — all 21 PHP files and the local config template |
| Python/JavaScript test and engine syntax | PASS — 4 Python and 3 JavaScript files |
| Diff whitespace and patch reverse-application checks | PASS |
| Production runtime, current production history, real mail delivery | NOT RUN — requires separate deployment verification |

Browser scenarios cover ordinary submission with falsified client SAN, rook
underpromotion, validator failure followed by a successful retry, and checkmate
with generated result notation. Host checkmate also opens its completion link
and creates exactly one successor. The existing board pages and chess.js asset
have no diff.
