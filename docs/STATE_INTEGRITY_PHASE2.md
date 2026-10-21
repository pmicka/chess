# Phase 2: database, revision, transition, and token invariants

This phase builds on Phase 1. It does not deploy itself or modify a database on
an ordinary request. Existing installations require an explicit CLI migration.
No production database, token, game, score, or email was used during development.

## Behavior and compatibility

- Move requests must include `game_id` (positive integer) and
  `expected_revision` (nonnegative integer). `last_known_updated_at` no longer
  authorizes a write. Existing tabs must reload after deployment.
- State responses include `revision`. Both boards send game/revision, and their
  fingerprints include game ID, revision, and status. FEN remains the playable
  position. Chess legality and SAN generation are still Phase 3 work.
- Every accepted move increments revision in a conditional update. Reset also
  increments revision. SQLite writers obtain a reservation before reading state,
  with a two-second busy timeout. Contended moves return a bounded 503
  `database_busy` with `Retry-After: 1`; stale versions return 409. A spent host
  credential returns 403. Automatic move-response recovery remains Phase 4 work.
- A unique partial index permits at most one active game. Finished games may
  legitimately leave zero active games while awaiting a host transition. Locks
  are unique by `(game_id,name)`; game colors/status and revision values have
  database constraints.
- Tokens store explicit `expires_at`, `game_revision`, and `revoked_at`.
  `your_move` is restricted to its active host turn/revision. `next_game` is
  restricted to its finished game/revision and cannot submit a move. A move
  revokes sibling capabilities, preventing them from surviving another turn.
- The two-hour action lifetime and seven-day, email-only recovery window remain.
  Recovery accepts only an eligible unused/unrevoked capability for the latest
  created game and its current revision. Completed-game recovery requires the
  new `next_game` purpose. A spent move token cannot request another action.
  Resend siblings can coexist within one revision; all lose authority when that
  revision advances. Public GET/HEAD credential issuance remains prohibited.
- Terminal moves record their result and issue a separate completion capability
  in the same transaction. A visitor's completion capability goes only to the
  configured mailbox. An authenticated host's terminal response can return its
  completion capability to that host; the existing host page opens that link.
- `game_transitions` has a unique completed game, successor, and authorizing
  capability. Only one successor can be created. The same spent transition
  credential can read its own public receipt (the same successor ID, with current
  successor metadata); it cannot create another game, issue credentials, count
  another result, or send another email. Revoked credentials otherwise grant no
  action. Used legacy move tokens cannot authorize transitions.
- Host-first successors receive their opening link by email after commit. A
  transport failure returns a warning while preserving the committed game.
  Durable notification retries and lost-response reconciliation remain Phase 4.

## Score preservation

Migration imports the explicitly supplied JSON score into the singleton
`score_baseline` row. It does not recompute historical totals or infer results
from legacy PGN. The original JSON file is left byte-for-byte unchanged.

New `game_results` rows use the completed game ID as a primary key. Result
accounting and game transitions share the SQLite transaction. Results at or below
the imported `last_counted_game_id` are recorded with `counted=0` so totals already
present in the baseline are not counted twice. A conflicting recorded result
fails instead of overwriting history. Unknown results block progression pending
review instead of silently advancing without score accounting.

After migration, SQLite is authoritative for totals. The old JSON is an archival
baseline, not a live mirror. Back up SQLite to preserve both baseline and results.

## Database-path safety

Absolute Unix/Windows/UNC paths are recognized without the audited regex error;
relative paths remain anchored to the application directory. Runtime requires an
existing database and schema version 2. It never initializes or migrates missing
state. SQLite URI `mode=rw` also prevents creation if the file disappears between
checking and opening it.

For an absolute configured path, the resolver checks the old incorrectly nested
candidate under the application directory. If a different legacy candidate exists,
runtime refuses to create or switch databases. It does not choose between copies.
The migration/provisioning CLI requires the operator's explicit absolute path and
opens only that existing file. Configuring that verified file remains a separate
deployment step; no file is moved automatically.

## Existing-installation deployment — operator procedure, not executed

1. Stop application writes at the web server. Verify which database the current
   release actually uses, including the old nested candidate; do not assume
   `data/chess.sqlite` is authoritative. Record game IDs, latest FEN/PGN, score
   totals, file ownership, and the chosen path privately without printing tokens.
2. Make a consistent SQLite backup and a copy of the actual score JSON and private
   configuration. Test the backup privately. Preserve any alternate database
   copies for reconciliation; never merge or delete them automatically.
3. Review the bundled code and run the isolated suite against the deployment PHP
   version. PHP 8+, PDO SQLite, ctype, and SQLite 3.24+ are needed. Node/shell game
   status detection remains an existing production dependency; tests stub it.
4. Run read-only preflight against the verified files:

   ```bash
   php scripts/migrate_phase2.php --database=/verified/private/chess.sqlite --score-file=/verified/private/score.json
   ```

   Preflight checks SQLite integrity, foreign keys, active-game uniqueness and
   creation order, lock uniqueness, game metadata/FEN turn agreement, and explicit
   score counters. Missing/corrupt score
   data is a refusal, not a zero baseline. If zero totals are correct, supply a
   separately reviewed baseline file with all four counters explicitly zero.
   Duplicate games/locks require a separate reviewed resolution before migration.
5. With backups and preflight reviewed, add `--apply` to that same command. The
   migration is transactional and repeatable. An already migrated database is
   left unchanged; later edits to the JSON cannot reimport or reset its totals.
6. Deploy the complete compatible PHP bundle, including `lib/schema.php`, the
   revised score/token helpers, endpoints, and both pages. Point `DB_PATH` to the
   verified database. Do not deploy just the endpoints or restore the old path
   resolver. Do not run bootstrap on an existing installation.
7. **Legacy token rows are retained but revoked**, because their issuing turn
   cannot be established safely. If the verified latest game needs a host action,
   provision one replacement through the local operator tool:

   ```bash
   php scripts/host_link.php --database=/verified/private/chess.sqlite --output=/private/nonexistent-host-link.txt
   ```

   This tool writes a new exclusive mode-0600 file outside the application root;
   it never prints a credential or sends mail. It revokes sibling credentials in
   the same transaction and leaves game rows, locks, and scores unchanged. A file
   write failure rolls back issuance/revocation. Deliver the file contents through
   an authenticated private channel, then remove the private file securely. Never
   paste the link into shared logs, tickets, or this conversation. The tool refuses
   a visitor turn. If legacy status disagrees with a terminal position, review that
   discrepancy explicitly rather than resetting or rewriting the game.
8. Verify expected game/revision, score totals, valid link behavior, and denied
   legacy links using private staging first. Resume writes only with compatible
   pages loaded. Keep Phase 1 infrastructure log redaction/cache exclusions.

For a verified **new** installation only, `php init_db.php --new` creates the
schema, zero baseline, and initial game. Creation is exclusive with private file
permissions; verify web-user ownership/access before serving it. Existing files are refused. Bootstrap,
migration, and provisioning are CLI-only and return 404 over HTTP.

## Rollback and future revocation

Keep host/move routes in maintenance during any rollback. If no writes occurred
after migration, the verified database backup and compatible previous release can
be restored together, accounting for the old path bug and retaining Phase 1
containment. Never expose the pre-containment release.

If any moves/results/transitions occurred after migration, restoring the old
database or score JSON loses data. Prefer a forward fix. Any downgrade requires
an explicitly reviewed export/reconciliation of post-migration revisions, result
totals, and transitions while writes remain paused. There is no automatic down
migration and no instruction to drop the new columns/tables.

Future revocation should set `revoked_at` for affected token IDs in a verified
transaction. Preserve transition-receipt rows and referenced tokens; do not use
the Phase 1 legacy deletion procedure on this schema. Spent receipts expose only
public successor information and perform no writes. If replacement is needed,
use private operator provisioning, not reset. None of these operations were run
against production during this task.

## Verification

```bash
PHP_BIN=php python3 tests/state_integrity.py
```

The suite includes Phase 1 authorization regressions, migration preservation and
refusal cases, explicit expiry/revision binding, same-timestamp stale versions,
two independent PHP workers sharing one SQLite database, transaction rollback,
score baseline cutoffs, terminal capabilities, transition replay, bounded busy
handling, and private provisioning failures. Fixtures live in temporary directories;
native mail/shell execution and external network services are replaced/disabled.

Browser smoke checks additionally cover both pages' new request fields and the
host completion-to-next-game flow. These checks do not prove production deployment,
real email delivery, server chess legality, or the later outbox/recovery phases.
