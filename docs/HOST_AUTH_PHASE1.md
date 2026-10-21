# Phase 1: host authorization containment

Phase 2 supersedes the schema, token lifecycle, score storage, deployment, and
revocation procedures below. For the current working tree, follow
[Phase 2 deployment and compatibility notes](STATE_INTEGRITY_PHASE2.md). The
Phase 1 section remains as the record of the earlier release.

Based on the audit of `1d73756c493a876ad87df18155601bccb0c46b05`.
This change requires no schema migration and does not rewrite game history,
positions, scores, or existing token rows at deployment time.

## Link and recovery policy

| Credential/request | Board or move access | Email recovery |
| --- | --- | --- |
| Valid unused host token | Existing two-hour move lifetime remains | Allowed for its eligible game |
| Expired, unused token less than seven days from issuance | Denied | Email-only replacement for its eligible game |
| Token issued seven or more days ago | Denied when expired | Denied |
| Missing, unknown, used, deleted, or wrong-purpose token | Denied | Denied |
| Invalid/missing/future issuance timestamp | Existing move validation is unchanged | Denied |
| Finished, missing, older, or visitor-turn game | Existing move checks remain | Denied |

Recovery requires the original token's game to be the latest created game
(`MAX(id)`), active, and on the host's turn. It never falls back to another game,
even if legacy data has multiple active games. A valid link for an older game
still reads its original game through the existing state endpoint.

`used`, `used_at`, token deletion, and a purpose other than `your_move` all prevent
recovery. There is no new revocation column in this phase. A successful resend
does not revoke sibling credentials; broader turn-bound token lifecycle work is
deferred. Known exposed credentials therefore need the separately authorized
operator procedure below.

GET and HEAD never issue, recover, rotate, or consume host credentials. They do
not redirect invalid links to a new credential. The existing unavailable-link
card remains; its recovery button is disabled for ineligible links. Requests
with an eligible expired token may POST `action=resend`, but must open the new
email to gain access. The expired credential remains expired for state/moves.

Recovery emails go only to the configured `YOUR_EMAIL`; no recipient is accepted
from the request. Replacement credentials never appear in resend responses,
including mail failures. A failed transport can leave a newly issued credential
in the database, but only the private transport received it. The original unused
proof may retry within the recovery window after the cooldown.

The per-game 60-second cooldown applies after authorization. Unavailable,
unreadable/corrupt, or unwritable cooldown storage fails closed with HTTP 503;
an ordinary cooldown returns 429. Mail failure returns 500 without a credential.
Transport acceptance does not prove delivery.

For used links or links outside the recovery window, open the latest host email
or contact the operator for private, authenticated assistance. Do not reset the
game to recover access. This phase does not add a new operator login or recovery
endpoint.

## Logging and browser privacy

Application authentication diagnostics log only the request path, dropping the
entire query string (including alternate or encoded parameter names). Existing
token suffix diagnostics remain. Host HTML and host-submit responses send
`Referrer-Policy: no-referrer`; host HTML also sends `Cache-Control: no-store,
private` before configuration/database loading.

Web-server/CDN access logs are outside this application helper. Configure them
to omit/redact credential query strings, restrict old logs, and exclude host
pages from caching. Tokens still appear in emailed URLs and browser history.
These changes do not retroactively remove credentials from existing logs or
cached responses.

## Regression tests

```bash
PHP_BIN=php python3 tests/host_authorization.py
```

Requirements: Python 3 standard library and PHP 8+ CLI with PDO SQLite and ctype.
`PHP_BIN` may name a wrapper executable supplying extension paths. No production
configuration is loaded: the runner copies source into a private temporary
directory, writes synthetic configuration, and uses a temporary SQLite database.
Native `mail()` and `shell_exec()` are disabled before loading the application;
test replacements use a private simulated mailbox and fixed game-status output.
HTTP/HTTPS stream wrappers are disabled so accidental external service calls
cannot leave the fixture. Development-server access output is discarded, and
credential-bearing assertion values are never printed. The server and fixtures
are removed on completion.

Coverage includes GET/HEAD, missing/invalid/expired/used/revoked/older-game links,
recovery boundaries, invalid timestamps, unchanged rows on denied requests,
cooldown, mail false/exception, application log redaction, and ordinary host state
loading/submission. This is HTTP integration coverage; it does not establish
full browser rendering, real email delivery, Node availability, or chess legality.

## Deployment and rollback

1. Back up the actual deployed SQLite file, score file, and private configuration
   using the operator's existing consistent backup process. The audited DB-path
   bug is deferred: verify the actual file in use before choosing a backup path.
2. Review the diff and run the isolated suite with the deployment PHP version.
3. Deploy `my_move.php`, `api/my_move_submit.php`, `api/state.php`,
   `lib/http.php`, and the new `lib/host_recovery.php` together. Do not deploy an
   endpoint without its helper. No bootstrap/reset or migration is required.
4. Check permissions on the existing cooldown file/directory. Corrupt or
   unavailable storage now intentionally disables resend; inspect/repair it
   privately rather than bypassing the check.
5. Verify the headers and denial/recovery paths using synthetic staging state.
   Do not use production token paths as unauthenticated smoke checks.
6. Configure infrastructure log redaction and host-page cache exclusion. Treat
   previously exposed credentials separately; deploying code alone does not
   revoke them.

Rollback does not require restoring a database or undoing a schema migration.
If the release must be withdrawn, first disable access to host/recovery action
routes at the web server or use a secured maintenance response. Restore the
reviewed code bundle together and keep those routes disabled until a corrected
containment release is ready. Do not publicly restore the vulnerable recovery
behavior. Preserve any legitimate moves made since deployment.

## Future credential revocation — instructions only, not executed

Under a separate authorized maintenance operation, pause affected host actions,
back up the verified database, and inventory affected token IDs/game IDs without
printing token values. In a transaction, delete confirmed compromised token rows
(which also prevents their use by the legacy next-game authorization), preserving
all game, lock, and score rows. Generate an appropriate replacement only for the
verified current host turn and deliver it through a private authenticated channel
or the configured host mailbox. Never print it to shared logs or invoke reset.
Resume actions only after validating the replacement privately and confirming
compromised links are rejected. Reconcile any missed notification separately.

Do not merely mark compromised tokens used: the existing next-game endpoint
accepts used tokens. Its replay defect, authoritative chess legality, database
revisions/path repair, durable notifications, and broader frontend recovery remain
separate audit phases.
