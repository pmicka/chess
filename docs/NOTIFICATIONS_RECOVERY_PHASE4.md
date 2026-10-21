# Phase 4: notification durability and submission recovery

This phase builds on Phase 1 authorization containment, Phase 2 revision/score
integrity, and Phase 3 server chess validation. It introduces **schema version 3**.
It does not rebuild the UI, repair stored chess history, reset games, or rotate
existing credentials during migration. Existing games, PGN, FEN, score records,
token values, expiration times, and revocations are preserved.

## Behavior and boundaries

Move acceptance, token issuance, score changes, notification intent, and an optional
submission receipt now commit in one transaction. A rolled-back move sends no
email. After commit the request attempts its queued notification; mail/claim/ack
failures cannot change the move's successful outcome. A process dying after commit
but before mail leaves a durable job for the worker.

The `notification_outbox` contains token row references, game IDs/revisions, status,
attempts, retry times, and leases. It never stores a URL or a second copy of a
credential. The worker reserves a job under a short SQLite write transaction,
checks the original game/revision/purpose and credential eligibility, then
releases the transaction before calling the mail transport. Used, revoked, stale,
wrong-turn, and older-game jobs become `obsolete`; they never follow a new game.
An expired credential for a still-eligible queued turn can be replaced privately
within that job's game/revision/purpose. The replacement goes only to the configured
host email. This does not enable public credential recovery.

There are at most eight automatic attempts per job, with retry delays of 60, 120,
240, 480, 960, 1920, and 3600 seconds. A claim lasts ten minutes; abandoned claims
are retryable. A worker drains at most 20 due jobs per invocation. Configure the
host's mail-command timeout to finish within the lease. A stalled native `mail()`
call cannot be forcibly timed out by this PHP worker. An active lease prevents
another worker from immediately sending the same job.

`accepted` means **PHP's transport accepted the submission**, not that the recipient
received or opened the email. A crash between acceptance and recording the result
can produce another email on retry. Attempts use a stable Message-ID, which does
not guarantee deduplication. Another host request can also advance the game after
eligibility is checked but before transport finishes; an obsolete email's link
still fails the ordinary game/revision authorization checks. There is no delivery
webhook, bounce processor, or automatic SPF/DKIM/DMARC verification in this phase.

The new browser code supplies a random 128-bit `submission_id`. The server binds
its receipt to actor, original host credential (for host moves), game, source
revision, coordinates, and promotion. Reusing the same ID with a different move
returns 409. Replaying the accepted request returns its public result, without
moving again, issuing credentials, or sending another email. A visitor receipt
can be checked without a fresh Turnstile token; a fresh move still requires
Turnstile. Concurrent requests recheck the receipt under the writer reservation.

Only the first authorized terminal host response may include the existing
`next_game_token`. Stored/replayed receipts strip that credential, and terminal
moves also queue a completion email so a lost response does not strand the host.
POST `action=receipt` with the original spent host credential returns only a public
move/transition confirmation. It never restores the board, consumes another
credential, or chooses another host turn. Missing/unrecognized credentials gain
no authority. Existing clients without submission IDs retain their prior behavior;
they cannot recover move responses through the new move-receipt mechanism.

Visitor pending requests are saved in **sessionStorage**, containing only ID,
game/revision, coordinates, and promotion. Host and Turnstile credentials are never
persisted there. Host retries use an in-memory ID; refreshing a spent host link
checks its public receipt. Visitor refresh checks the saved receipt before retry.
A missing receipt may race an in-flight request, so it does not discard the ID.
While an outcome is unknown, selecting a different move is blocked; Submit retries
the original request. Malformed JSON, network failures, proxy timeouts, and 5xx
responses are not interpreted as success or definitive rejection. Browser requests
time out after 15 seconds. Only a recognized application rejection clears a failed
attempt. Failure to refresh the board after acceptance is reported separately.
Session storage must be available for visitor submissions; if it is unavailable,
the page reports that condition before sending a new move.

The Phase 1/2 recognized, unused/unrevoked, original-game resend policy and 60-second
cooldown remain. Mail failure now returns 200/`queued`, because recovery intent
was saved; it does not return any replacement secret. Public GET/HEAD never issue,
recover, rotate, revoke, or consume credentials. Host pages retain no-store and
no-referrer headers. Application logs omit credential-bearing query strings.

## Deployment checklist (operator actions; not executed by implementation)

1. Verify the **actual configured existing database** and PHP/Node executables.
   Preserve the current explicit DB_PATH, including any verified legacy nested
   path. Do not initialize, move, or reconstruct a database to deploy this phase.
   Retain the Phase 3 read-only chess-state preflight. Verify web and cron users
   have compatible configuration, SQLite journal permissions, Node availability,
   `proc_open`, PDO SQLite, and mail transport settings. Requirements/dependencies
   are unchanged; cron is newly required for durable notification retries.
2. Deploy during a short write-maintenance window. Stop any old notification
   workers. Make an SQLite-consistent backup outside the web root using the SQLite
   backup API or `.backup`, and preserve `config.local.php` and archived score JSON.
   Include WAL content through the backup API; copying a live main file alone is
   insufficient. Protect backup access as it contains host credentials.
3. Use the same absolute **verified database path** in both commands below. Replace
   the example before running. The first is a read-only preflight; only `--apply`
   migrates. It adds two empty tables and indexes atomically, plus schema version 3.

   ```bash
   php scripts/migrate_phase4.php --database=/verified/existing/chess.sqlite
   php scripts/migrate_phase4.php --database=/verified/existing/chess.sqlite --apply
   ```

   Phase 2 databases continue serving visitor state and valid host pages before
   this migration. New move/resend/next-game writes return 503 until it is applied.
   Migration is explicit and idempotent; HTTP cannot invoke either CLI script.
4. Configure a once-per-minute cron invocation as the correct account, using
   absolute executable/script/database paths. Example structure:

   ```cron
   * * * * * /absolute/php /absolute/chess/scripts/notifications.php --database=/verified/existing/chess.sqlite --drain >> /private/chess-notifications.log 2>&1
   ```

   The script prints status counts, never links or credentials. Exit 1 indicates an
   operation failure; exit 2 indicates a drained job exhausted attempts or could not
   be dispatched/acknowledged. A temporary mail failure that remains scheduled is
   reported as `pending`. Alert on failed jobs, accumulating pending jobs, expired
   sending leases, and `notification_dispatch_unavailable` application events.
   Configure private log permissions and rotation. Keep raw host URLs out of web
   server/proxy access logs too; PHP log redaction cannot configure those services.
5. Reload open browser tabs and deploy `assets/submission.js` with the PHP changes.
   Keep existing asset paths and visual layout. Verify public state and private CLI
   status without opening production credential paths. Verify actual delivery only
   through a separately authorized operator email exercise. Check SPF, DKIM, DMARC,
   envelope sender, spam folders, and provider/bounce logs at the hosting account;
   a transport `true` alone does not establish delivery.

## Operator email recovery

Read-only queue counts (no send or credential issuance):

```bash
php scripts/notifications.php --database=/verified/existing/chess.sqlite
```

Explicitly queue the current eligible host action and attempt delivery:

```bash
php scripts/notifications.php --database=/verified/existing/chess.sqlite --queue-current --drain
```

This command is **private CLI administration**, not a public recovery endpoint.
It preserves game/history/scores, reuses an eligible pending job, or creates an
email-only capability for the current host action if needed. After a previously
accepted but missing email, an explicit new operator queue creates a fresh job.
It never prints the link. It refuses a current visitor turn and does not advance
finished games. Do not schedule `--queue-current`: it is deliberate operator
recovery, and repeated use can send duplicate emails. Migration itself does not
queue emails for preexisting turns; use this explicit action if authorized.

After fixing a persistent transport/configuration problem, explicit
`--retry-failed --drain` reopens exhausted jobs. Each is revalidated before sending;
obsolete turns gain no new authority. Neither this nor queue-current is admin reset.
The existing two-hour move-link expiry and seven-day bounded public email recovery
window remain unchanged. A days-old turn outside that window requires the private
operator action above; possession of an arbitrary old/used/revoked link is not a
public recovery permission.

## Rollback and retention

If deployment fails, stop the cron worker and block writes. Preserve a fresh
consistent backup **before** rollback. Prefer reverting to a known-good Phase 4
build or a forward correction: pre-Phase-4 source recognizes only schema version 2
and cannot simply be redeployed against schema 3. Do not change the schema marker
by hand, drop receipt/outbox tables, or restore the old backup while play continues.
A full database restore loses intervening accepted moves/results and requires an
explicit reconciliation decision. Additive tables preserve existing rows; the
compatible admin-reset path now revokes tokens rather than deleting referenced
rows, solely to keep that existing privileged operation working.

No cleanup deletes jobs, tokens, or receipts automatically in this phase. Receipts
contain public move outcomes (including the visitor's accepted position snapshot)
and are retained for deterministic retries. Monitor database size and use a future
explicit retention policy with a documented recovery horizon before pruning.

Any broader credential-revocation exercise is separate: take a consistent backup,
pause writers/workers, inventory current game/revision/purpose without printing
secrets, explicitly revoke the approved credentials, and queue a private host email
for the still-current action. Such an exercise invalidates links and needs its own
operator authorization. This implementation and migration do not execute it.

## Reproducible checks

```bash
node --test tests/chess_rules.test.js
PHP_BIN=php python3 tests/notification_recovery.py
PHP_BIN=php python3 tests/browser_chess.py
PHP_BIN=php python3 tests/browser_recovery.py
```

The integration suite includes all Phase 1–3 tests. It uses temporary SQLite files,
synthetic credentials, disabled native mail with a stub transport, and mocked
Turnstile; outside HTTP wrappers are disabled. Browser checks block outside
requests and cover desktop/mobile retry, malformed/lost responses, refresh, and
timeout. Timeout browser fixtures accelerate the 15-second timer to 250ms and use
a local synthetic slow response. Real provider delivery, DreamHost cron/PHP web
settings, and production deployment remain operator verification items.


## Execution record — 2026-10-21

Implemented locally on `codex/phase4-notifications-recovery`, based on
`2b690466900cb70e38cadcbd475bf358c706687e`. Local implementation and validation
did not access production, use production credentials, send real mail, or deploy.
Publishing the changes and deploying them are separate actions.

| Check | Result |
| --- | --- |
| `tests/notification_recovery.py` (includes Phases 1–3) | PASS — 114 tests |
| `node --test tests/chess_rules.test.js` | PASS — 54 tests |
| `tests/browser_chess.py` | PASS — 16 desktop/mobile checks |
| `tests/browser_recovery.py` | PASS — 25 desktop/mobile recovery checks |
| PHP syntax | PASS — 25 PHP files |
| `node --check assets/submission.js` | PASS |
| Python test-file syntax | PASS |
| `git diff --check` | PASS |

The local environment used PHP 8.4.24 with PDO SQLite through the existing
`/workspace/audit/php` wrapper, Node 24, and existing Playwright/Chromium. Tests
installed no dependencies. The newly introduced lost-response regression was
observed failing before endpoint wiring (duplicate host request returned 403);
it now returns the accepted public receipt. Failure-injection tests verify
rollback of game/token/receipt/outbox rows, retry after transport failures,
claim/ack failure, lease recovery, attempt/batch bounds, expiry, current-game
binding, schema-2 read compatibility, migration preservation/idempotency,
unauthorized resend containment, log redaction, and Turnstile outages.

Production deployment, web/cron account permissions and runtimes, actual mail
transport, delivery/bounces, provider authentication, web-server log redaction,
and scheduler operation remain **UNVERIFIED**. These require the deployment
checks above; passing stubbed local mail tests does not establish delivery.
