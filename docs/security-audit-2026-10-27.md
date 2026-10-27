# Local security audit - 2026-10-27

## Scope and evidence

Reviewed baseline: `16fac4285bc2be8f76d2a22f252fbdaadeed1e08`. The fixes and regression tests described below accompany this report. This is a source review and bounded local dynamic audit, not a certification of a public deployment.

Tests used PHP 8.5.12, real Laravel routing/middleware/services, isolated SQLite, temporary files, two ordinary users with separately activated keys, shared and separate wallets, and the trusted local CLI operator as the administrator. There is no administrator account, organization, tenant-header authentication, or organization model to manufacture for testing. Authorization was not mocked. Only external RPC/Telegram responses were replaced with controlled adapters; additional TCP/component probes ran with Docker networking disabled. No production services, actual credentials, real Telegram recipients, destructive load, or cloud metadata endpoints were used.

Static inspection is identified separately from executed tests below. No public host was supplied or attacked. Ordinary HTTP requests, small batches, three health probes, bounded synthetic bodies, and the existing two-process key-redemption barrier were used instead of resource exhaustion.

## Entry-point map

| Entry point | Method / protocol | Authentication / role | Data and effects | Dynamic coverage |
| --- | --- | --- | --- | --- |
| `/health` | GET, HEAD / HTTP | Public if a web listener exposes it | Only `{"status":"ok"}` after `SELECT 1`; now no session or cookie | Real Kernel and loopback TCP; query/body/header/method/error cases |
| `/up` | GET, HEAD / HTTP | Public if exposed | Generic application liveness, no personal data | Real Kernel and loopback TCP; generic failure rendering |
| `/storage/{path}` | Previously GET/HEAD and PUT | Laravel relative signed capability; PUT also required upload capability | Previously private local-disk read/write; no application link issuer | Valid, invalid, expired, cross-method, and traversal cases before hardening; absent even with a valid signature afterward |
| `/api/*`, versioned APIs, `/graphql`, `/webhooks/*`, login/admin/debug/export/upload paths | Absent | No applicable role | No application handler or mutation | 17 absent endpoint classes and write-method cases; database snapshots unchanged |
| Telegram `/start KEY` | Message obtained by authenticated HTTPS long polling | Individual single-use key; optional Telegram-ID allowlist; actor equals private chat | Creates one user grant/destination and queues a private reply | Real key activation, denial, replay, expiry/revocation, actor/chat/allowlist, throttling |
| Telegram `/status`, `/stop`, `/resume` and other private text | Long-poll message | Active grant, optional allowlist, exact private actor/chat | Own delivery status and permissions only; unknown/inactive users receive no reply | Two-user isolation, fake operator commands and protected fields, authorized controls, pending-reply revalidation |
| `access:*` | Local Artisan CLI | Trusted OS operator/application identity, not an ordinary Telegram user | Issue/expire/revoke/list access; restore invalidation | Real services/commands; two-process single-key redemption |
| `wallet:add`, `wallet:update`, `wallet:disable`, `wallet:list`, `wallet:import` | Local Artisan CLI | Operator plus explicit internal user with active access; ownership for existing objects | Scoped subscriptions, labels, filters, watch sets; TXT/CSV/JSON local files | Allowed controls; missing/revoked users; foreign IDs; protected fields; bounded/atomic imports |
| `wallet:backfill-create`, `wallet:backfill-run` | Local Artisan CLI / worker | Operator; owner and grant checks | Scoped historical job and explicitly selected notification policy | Foreign job IDs fail before external work; existing full-suite history/floor checks |
| `ops:*`, chain workers, Telegram delivery/replay | Local CLI / supervised workers | Trusted OS operator; maintenance/ownership/grant checks as applicable | Encrypted backup/restore, scanning, outbox attempts, retries | Tamper/restore/path probes, real routing and outbox checks, full existing worker suite |

There is no inbound JSON-RPC server, REST data API, GraphQL resolver, WebSocket subscription, provider webhook, password login/reset, MFA, JWT refresh, or browser-authenticated administrative UI. JSON-RPC is outbound only. Public static assets are not private-data API routes. Native installation starts no web listener; optional Nginx/FPM configuration is a separate deployment choice.

## Confirmed problems and fixes

### SEC-01: denied Telegram traffic accumulated permanent audit rows

- **Priority:** P2 / medium availability risk.
- **Location:** [TelegramPoller](../app/Telegram/TelegramPoller.php), `record()`, final user association, and `runOnce()` cleanup; [new partial index](../database/migrations/2026_10_27_000000_index_anonymous_telegram_updates.php).
- **Prerequisite:** a person can send messages to the bot. An allowlist prevented replies but did not prevent a permanent row per ignored event. Known revoked, expired, or disabled users also escaped anonymous cleanup because their denied messages retained `user_id`.
- **Safe reproduction:** seed 1,000 synthetic acknowledged anonymous events, process 20 unlisted messages through the real poller. Expected bounded retention; before the fix 1,020 rows remained. Three additional cases proved denied known-account messages were incorrectly classified as user-associated history.
- **Impact:** sustained denied traffic could grow SQLite and its backups without an application retention bound. No actual disk exhaustion was attempted; account/data access was not bypassed.
- **Fix:** retain a recent 1,000-update-ID anonymous window and delete at most 1,000 older eligible rows per successful/idle poll. A partial index avoids scanning all authenticated history. New denied traffic is associated with a user only if access was active. Successful activation remains recorded atomically with its key/user. Existing key/user evidence, replies, and the committed offset remain intact.
- **Regression:** `SecurityAccessAuditTest`; stale pruned events cannot consume an otherwise valid unused key. Legacy backlogs drain gradually rather than in one unbounded deletion.

### SEC-02: upstream responses were buffered and decoded without resource budgets

- **Priority:** P2 / medium availability risk, conditional on an oversized/anomalous upstream response.
- **Location:** [RpcTransport](../app/Blockchain/RpcTransport.php), [TelegramClient](../app/Telegram/TelegramClient.php), new [BoundedJsonResponse](../app/Support/BoundedJsonResponse.php).
- **Prerequisite:** configured RPC/Telegram upstream supplies an excessive body or update list. This is not a confirmed anonymous SSRF or direct user-selected-URL attack. Dense legitimate responses can also stress an unbounded parser.
- **Safe reproduction:** valid JSON envelopes with only 8 KiB of padding were accepted despite a test budget of 4 KiB; `getUpdates` accepted 101 results despite requesting 100. All three regression expectations failed before the fix. No OOM or real load attack was performed.
- **Impact:** worker memory/CPU use happened before result checks; an oversized poll batch could also perform more work than requested.
- **Fix:** force Guzzle's streaming handler, enforce actual decoded-byte limits with at most `MAX+1` bytes read, cap JSON at 100,000 structural tokens before allocation and depth 64 during decoding, use bounded read/validation windows, and close every body. No buffering-handler fallback is allowed when CLI `allow_url_fopen` is disabled. RPC defaults to 8 MiB (hard ceiling 32 MiB), Telegram to 2 MiB (ceiling 8 MiB); both have a 1 KiB minimum configured budget. Oversized/truncated RPC results fail closed and request splitting; a single oversized block stays blocked. Malformed JSON is rejected. More than 100 updates rejects the whole batch before consuming keys or advancing offsets. Telegram send ambiguity and HTTP retry/status semantics are preserved.
- **Regression:** `SecurityIntegrationAuditTest`; an instrumented stream forbids unbounded casts/reads, proves exactly 4,097 bytes read under a 4,096-byte limit, and proves closure. Small gzip passes, excessive decoded gzip fails. A roughly 200 KiB dense JSON array fails before decoding while normal log arrays and escaped text pass. A separate real loopback stream without `Content-Length` was rejected; `allow_url_fopen=0` was checked in a separate PHP process.

### SEC-03: anonymous health probes created unnecessary file sessions

- **Priority:** P3 / low, when optional HTTP is exposed with the native file-session driver.
- **Location:** [web routes](../routes/web.php), `/health` web middleware.
- **Safe reproduction:** three anonymous GET requests created three session files. Expected a stateless health check. No flooding or disk exhaustion was attempted; normal session expiry/garbage collection exists.
- **Impact:** needless persistent files and cookies for unauthenticated probes.
- **Fix:** exclude the `web` middleware group only for the already-public health route, keeping its SQLite check and JSON contract.
- **Regression:** `SecurityHttpAuditTest` verifies three requests create zero session files/cookies and change no database state.

### OPS-01: retention could delete the backup just reported as successful

- **Priority:** P3 / low operational reliability; not a remote-user exploit.
- **Location:** [BackupService](../app/Operations/BackupService.php), `create()` / `prune()`.
- **Safe reproduction:** retention 1, create backup, move synthetic clock back two seconds, create another. Before the fix the new returned artifact was deleted and backup metadata pointed to a missing file. Random suffix ordering could also affect same-second backups.
- **Fix:** explicitly retain the just-published artifact and retain at most another `N-1` files.
- **Regression:** `SecurityOperationsAuditTest` exercises clock rollback and a same-second normal control. Concurrent independent backup creators remain a separate limitation below.

### OPS-02: invalid encryption configuration left an empty restore staging file

- **Priority:** informational local cleanup defect.
- **Location:** [BackupService](../app/Operations/BackupService.php), `restore()`.
- **Safe reproduction:** create an authentic synthetic backup, clear the test application key, attempt an otherwise properly paused restore. Before the fix an empty private `.restore-*` file remained.
- **Fix:** validate the key before allocating the staging file. Regression checks no residue, database mutation, or outgoing effects.

### Additional hardening: remove unused signed file routes

[Filesystem configuration](../config/filesystems.php) had Laravel's `local.serve=true`. The application never issued temporary download/upload links, but the framework registered both routes. Dynamic tests verified the real signature/expiry/method/traversal protections: invalid requests failed; a correctly signed synthetic download returned 200 and upload 204. **No signature bypass or unauthenticated private-file access was found.** Setting `serve=false` removes this unused surface; valid old-style signatures now also have no route. This is hardening, not an invented authentication vulnerability.

## Coverage by direction

| Direction | Executed checks | Result / boundary |
| --- | --- | --- |
| Authentication and API keys | Real issuance/activation; malformed, expired, revoked/consumed keys; inactive grants; actor/chat/allowlist; invalid-attempt TTL; stale reply cancellation | Verified tested denials and allowed controls; no password/JWT/MFA API exists |
| Object/field/function access | Two real users, shared wallet with different labels; foreign/missing/revoked actor CLI matrix; eight protected import fields; private backfill IDs; fake Telegram operator commands | Database snapshots and outgoing requests checked, not status alone; no external BOLA found in these scenarios |
| Tenant isolation / jobs | Actual outbox revalidation with inconsistent synthetic references, revoked grant, authorized second recipient | Unauthorized destination cancelled; valid recipient still receives only its own label; corrupt-DB resilience is not claimed as an external write primitive |
| Injection and unsafe interpreters | Parameter-binding/raw-query source review; SQL-shaped query/labels; literal shell payload filename and real tar extraction; inert XML/serialized bodies | No user-selected SQL/OS/template/XML interpreter found. HTTP body tests prove no handler, not coverage of a nonexistent XML parser |
| SSRF / external trust | HTTPS/no-userinfo checks, redirect rejection, exact request ID, chain identity, failed receipt; static URL-source review | No ordinary-user URL input. Operator-configured destinations can target arbitrary HTTPS hosts; no private-IP/DNS egress firewall is claimed |
| Files / import / restore | TXT/CSV/JSON limits and atomicity; invalid later row; traversal/symlink/hash/private-path manifest fixtures; forged encrypted lengths, reordered/trailing records; real restore | No partial writes/escaped paths/command marker; restored grants invalidated; actual manifest component tested, not a fresh native OS installation |
| Input validation | Null/missing/wrong types, invalid formats, overflow-shaped queries, extra ownership fields, deep/malformed bodies, excessive update count | Tested bounded synthetic cases only; large HTTP-body rejection used size metadata with a tiny payload, not a real huge upload |
| Notification business rules | Existing full suite: official contracts/finality/receipts, exact amounts, stable transfer/outbox identity, filters, explicit backfill floors, replay and revocation | No payments, prices, balances, refunds, or custodial operations to test |
| Concurrency / retries | Existing actual two-process SQLite barrier redeems one key once; repeated updates/imports/ranges, leases, 429/403/5xx/ambiguous sends in full suite | No destructive race/load exercise; exactly-once Telegram delivery is not promised |
| Abuse / resources | 20/minute command and activation throttle tests, bounded anonymous cleanup, bytes/token/depth/count guards, read deadlines, import and backup chunk bounds | Confirmed availability fixes above. No real DoS, high-load capacity measurement, or global authenticated-ledger retention implementation |
| Browser / CORS / CSRF | Real routes/middleware, foreign origins, forged forwarding/identity headers, preflight and methods, no-session probes | Public stateless health only. Missing `api/*` preflight may answer 204 with wildcard origin and no credentials; real operations remain absent. No cookie-authenticated mutation exists |
| Webhooks / GraphQL / WebSocket | Route inventory and representative 404 requests; Telegram arrives only through authenticated polling | Not implemented, so no invented signature/resolver/subscription test coverage |
| Configuration / leaks | `APP_DEBUG=false` genuine SQLite/liveness failure tests; no trace/secret marker in output; sanitized upstream errors; tracked artifact scan; deployment source review | No exposed credential confirmed. No exhaustive Git-history or deployed-host secret scan was performed |
| Dependencies | Online locked Composer audit plus official upstream/GitHub advisory metadata and relevant patch-range review | Details below; vulnerability-database results are a dated snapshot, not a proof of safety |

## Dependency evidence

`bin/app composer audit --locked --format=json --no-interaction` completed successfully: no advisories or abandoned packages for 74 production and 30 development packages in the lock file. Dependencies were not changed just to produce a clean audit.

Official repository advisory indexes were retrieved for Laravel, Symfony's monorepo and HTTP components, Guzzle, PSR-7, and CommonMark: seven indexes, 107 returned advisory records. Three historical noncanonical version ranges were reviewed using their stated patched versions; installed PSR-7 3.2.0 and CommonMark 2.10.4 are beyond those affected versions.

The relevant [CVE-2026-114508 / GHSA-5wcm-3v2w-8362](https://github.com/advisories/GHSA-5wcm-3v2w-8362) required manual triage because raw repository metadata listed an overly broad range. GitHub's normalized advisory bounds it to Laravel `>=12.45.0,<12.68.0` and `>=13.0.0,<13.27.0`; installed Laravel **13.38.0** is beyond the 13.27.0 fix. The [official 13.27.0 release](https://github.com/laravel/framework/releases/tag/v13.27.0) links the resource-loading fix. This application also exposes no relationship-inclusion data endpoint. It is not reported as an unfixed project vulnerability.

This did not scan the deployment OS/container layers, proprietary provider infrastructure, or every transitive dependency's source. No unknown/zero-day safety claim follows from the empty Composer result.

## Reproduce and results

After normal development setup:

```bash
bin/verify
bin/app php vendor/bin/phpunit --filter 'Security.*AuditTest' --fail-on-warning --fail-on-risky --fail-on-deprecation --fail-on-phpunit-deprecation
bin/verify-security-manifest
bin/app composer audit --locked --format=json --no-interaction
```

For the real loopback HTTP probe, Docker must have the local pinned runtime image already built:

```bash
DOCKER_CONFIG=/tmp/coin-bot-docker docker run --rm --init --network none \
  --user "$(id -u):$(id -g)" \
  --volume "$PWD:/app:ro" --workdir /app \
  coin-bot-php:8.5 php tests/Support/HttpTcpProbe.php

DOCKER_CONFIG=/tmp/coin-bot-docker docker run --rm --init --network none \
  --user "$(id -u):$(id -g)" \
  --volume "$PWD:/app:ro" --workdir /app \
  coin-bot-php:8.5 php tests/Support/StreamingTlsProbe.php
```

Final strict `bin/verify` passed: runtime/SQLite/index checks, Composer validation, Pint, and **338 tests / 1,880 assertions**. The audit adds **91 cases** under `tests/Feature/Security*AuditTest.php` to the previous 247-test suite. The TCP probe passed 16 HTTP requests with no cookies/sessions; the actual native manifest validator passed 12 fixtures, and GNU tar handled option-like filenames literally without creating the injected marker. Separate controlled loopback response/INI probes and pre-fix failures were also executed; they are not relabelled as live-provider or deployed-host tests.

The reproducible TLS probe uses the actual `RpcTransport` without booting the application or reading `.env`. It passed four controlled loopback scenarios: a trusted ephemeral certificate with a valid small response, a 16 KiB body without `Content-Length` rejected under a 4 KiB budget, rejection of an untrusted CA with a sanitized error, and a 302 redirect not followed. TLS verification was never disabled. This validates the explicit streaming handler locally, not actual provider accounts.

The full suite additionally reruns prior blockchain, privacy, concurrency, routing, import, reconciliation, backup, and recovery acceptance tests. One old Telegram test fixture now returns a fresh stream per request, matching real HTTP response lifetimes; the production close requirement was not weakened to accommodate a reused fake body.

## Remaining limits and deployment implications

- No actual public deployment was tested. Nginx/FPM/UFW/systemd source was reviewed; prior native Debian and Nginx checks are historical evidence, not newly executed penetration tests. A future active deployed-host test needs the exact address and agreed request budget first.
- Update with all writers stopped and preserve `.env`/`APP_KEY`/SQLite/backups. After installing verified code, run `sudo coin-bot optimize:clear` to clear cached routes as well as configuration, then `sudo coin-bot migrate --force` for the new partial index before restarting the complete worker set. Old cached routes must not keep the removed file endpoints or session middleware. The full-ZIP installer remains first-install only. See [operations](operations.md#deployment-and-rollback-discipline); do not overwrite a live installation blindly.
- The versioned PHP CLI needs `allow_url_fopen=On` for the explicit streaming handler. No shared INI/FPM configuration was changed. New byte settings are documented in [configuration](configuration.md); excessively dense/large real blocks can intentionally block a scan until investigated rather than certify incomplete data.
- The response timeout consists of bounded connection/header and body/read windows. A single blocking read can consume its read timeout before the next monotonic deadline check. No strict one-timeout end-to-end SLA is asserted.
- Authenticated/key audit evidence, transfer ledgers, sent replies and delivery history still require capacity/retention monitoring. Anonymous cleanup does not implement retention for every table or cache file. Legacy anonymous backlogs drain in bounded chunks; previously user-associated records are preserved.
- Parallel independent backup creators can still race status publication and retention under a one-artifact retention setting. Use one backup writer; this concurrency risk was found in review, not reproduced as an external exploit. The serial clock-rollback defect was reproduced and fixed.
- Restore fencing still relies on an operator stopping/fencing all writers; `--workers-stopped` is an assertion, not universal process detection. Off-host recovery and the actual mount's encryption/permissions were not validated here.
- Grant/allowlist changes cannot recall a Telegram request already in flight. Ambiguous Telegram acceptance can produce a duplicate on retry; no client idempotency key exists. Operator credentials/host compromise remain outside ordinary-user authorization guarantees.
- Real account quotas/history/failover, live TLS/provider compatibility, real recipients, high-load capacity, and off-host production recovery remain deferred. No amount of passing local tests establishes that the API or deployment is completely secure.
