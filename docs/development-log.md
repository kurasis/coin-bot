# Development evidence

All results below were obtained on PHP 8.5.12 / Laravel 13.38.0 on 2026-10-26. Production readiness is tracked separately from offline implementation.

## P01 - Complete

- Pinned Docker runtime and locked Composer dependencies; `bin/setup` initializes local configuration and SQLite without overwriting existing data.
- A clean export of the staged foundation installed all 104 packages from `composer.lock`, generated its local key, and migrated a new SQLite database.
- Clean-export `HealthTest`: 1 test / 4 assertions passed with warnings/risky tests treated as failures.
- Clean-export `composer validate --strict` and Pint: passed (22 PHP files).
- CI workflow is committed; execution on GitHub Actions remains to be observed after push. No production processes or recipient messages were started.

## P02 - Complete

- Added real SQLite domain schema with composite ownership foreign keys, exact uint256 amounts, transfer/outbox uniqueness, and scoped user access.
- `DomainPersistenceTest`: 11 tests / 22 assertions passed on the configured SQLite runtime.
- Verified rollback, same-transaction repeated logs, two-user shared-wallet isolation, foreign ownership rejection, destination authorization, and cross-user grant misuse.
- Database schema migrated successfully; no production users or credentials were created.

## P03 - Complete

- Implemented hashed 256-bit single-use keys, numeric Telegram identity binding, expiry, rate limits, generic denials, per-grant revocation, and fail-closed restored-state invalidation.
- Access feature/concurrency suites: 12 tests / 72 assertions passed; the concurrency case used two independent PHP processes against one real SQLite database and produced one activation and one denial.
- Pint passed all 13 access source/test files. Operator commands are local Artisan commands, not Telegram administration commands.
- No live user keys were issued or sent; test keys are synthetic and isolated.

## P04 - Implemented; production capability gate blocked

- Ethereum/TRON clients validate network identity, bounded numeric ranges, indexed topic batches, canonical headers, complete receipts, malformed/HTTP-200 errors, rate limits, and sanitized connection failures. TLS verification remains enabled.
- Blockchain feature suite: 21 tests / 47 assertions passed; Pint passed.
- Live implemented-client probes succeeded against public Ethereum RPC and TronGrid: network identity, finality, filtered official-USDT logs, and full successful receipts.
- Actual paid primary/secondary account credentials, retained history depth, quota, and recovery headroom are not configured or certified. Public endpoints are proof of implementation, not production SLA/plan evidence. See [provider capabilities](provider-capabilities.md).

P04 follow-up: added read-only `chain:probe` with mandatory address filters, bounded numeric ranges, network/finality checks, receipt samples, and safe JSON output. Combined client/probe suites passed 26 tests / 62 assertions. It does not write wallets, transfers, or messages.

## P05-P07 - Complete implementation and mainnet verification

- Durable range scanner, full-receipt event decoder, active-grant/destination routing, atomic ledger/outbox/coverage transactions, CAS watch-set safety, overlap deduplication, and bounded result splitting are implemented.
- Monitoring suite: 21 tests / 73 assertions passed, including injected SQLite write failure, missing receipts, exact repeated logs, zero/max amounts, shared destinations, chain independence, and native TRON receipt fallback.
- Live full Laravel-to-RPC-to-SQLite probes used disposable databases without subscriptions: Ethereum 26,278,475-26,278,477 yielded 2 expected/verified stored transfers from 296 official-USDT logs; TRON 87,454,942-87,454,944 yielded 1 expected/verified stored transfer from 201 logs. Coverage advanced exactly over each three-block range.
- No Telegram messages were sent. Paid-provider certification remains the independent P04 gate.

## P08 - Complete

- Implemented user-scoped wallet commands, atomic/repeatable 100-record import, private destinations, immutable watch-set cutovers, and resumable owner-scoped backfill.
- Wallet suite: 21 tests / 86 assertions passed. Combined wallet/scanner rerun: 45 tests / 168 assertions passed with warnings/risky tests treated as failures.
- Separate subscription notification floors preserve silent history import. Explicit historical delivery is scoped and recorded on each authorization. Reenrollment restores scan membership without rewinding coverage; an already-active key renewal preserves pending delivery boundaries.
- Regression tests verify adding a wallet never cancels `/stop`, old reconciliation cannot send silent history, and an in-flight scan cannot commit across a cutover.
- Added shared operational maintenance/heartbeat primitives used by historical workers; deployment/backup validation remains P11.

## P09 - Implemented; live recipient gate blocked

- Implemented private-chat key enrollment with durable offsets/replies, authorized isolated notifications, atomic expiring leases, shared rate limits, retries, permanent-failure handling, replay, and maintenance checks.
- Telegram suite: 22 tests / 98 assertions passed. After global unexpected-HTTP blocking, combined Telegram/blockchain suites passed 48 tests / 160 assertions. No external Telegram messages were sent.
- Lease-expiry and redirect regressions prevent avoidable duplicate sends and credential forwarding. Active key renewal preserves `/stop`, destination restrictions, and notification boundaries. Actual inactive reenrollment enables private delivery from the new recorded boundary.
- Added an operator-only user-identity lookup for safely assigning wallets after enrollment. Activation parameters are marked sensitive in stack traces; local environment examples contain no credential values.
- Actual bot credentials and an explicitly authorized staging recipient remain required for live delivery acceptance.

## P10 - Implemented and offline verified; live acceptance deferred

- Added independent range/watermark reconciliation, canonical boundary and two-provider receipt witnesses, atomic omission repair, rolling rechecks, persistent circuit breakers, and retryable-error failover against the original primary cursor.
- Reconciliation/blockchain suites: 47 tests / 165 assertions passed after review fixes. TRON native fallback remains available through the cache wrapper; unknown or insufficient account retention never certifies coverage. Upstream two-day Retry-After survives HTTP parsing and circuit persistence.
- A conflict now defers pending notifications only for the affected chain, preserving healthy-chain delivery. Telegram suite after these cross-component regressions: 25 tests / 115 assertions passed.
- Actual independent account retention/capacity checks are deferred because the user selected development-only scope.

## P11 - Development implementation complete; external operation deferred

- Added consistent SQLite online backups with authenticated streaming encryption, staged/validated access-invalidating restore, health reports, supervision configuration, and the operations runbook.
- Operations suite: 12 tests / 58 assertions passed. Includes tamper/wrong-key/truncation protection, stale grant/key invalidation, reconciliation lag/missing-watermark detection, open circuits/conflicts, and healthy-backfill exclusions.
- Actual local Compose smoke used a uniquely named disposable project and persistent volumes: prepare/migrate/backup startup passed, encrypted artifacts were created, backup worker restart created further artifacts, and current schema was verified. The smoke exposed a missing read-only mount target; committed `var/.gitignore` fixes it. Only the disposable project/volumes/artifacts were then removed. No production/Telegram/scanner services were launched.
- Local backup behavior is not evidence of off-host persistence, external alerts, or measured full host-loss RPO/RTO; those checks are deferred.

## P12 - Offline acceptance complete; live acceptance deferred

- End-to-end acceptance uses real Laravel services and SQLite with simulated external blockchain/Telegram boundaries: 100 distinct addresses, two users, a shared wallet with private labels, 28,800-block replay in 29 ranges, outage resume, SQL atomic rollback, revocation/reenrollment, and deliberately ambiguous delivery.
- The two release cases passed 270 assertions. Expected backlog results: 104 transfer events, exact total 5,300.000000 USDT, and 105 authorized destination notifications. These are deterministic functional results, not real provider throughput.
- Final `bin/verify`: exit 0; runtime/SQLite/index checks, strict Composer validation, Pint (111 PHP files), and **163 tests / 907 assertions** passed. Composer audit reported no security vulnerability advisories.
- Verified secure runtime-variable forwarding without displaying a binding value. An external SQLite sentinel survived a health test run with `DB_DATABASE` injected; tests used isolated memory SQLite. PHPUnit forces both environment/server settings and the base test case guards its connection before database-refresh traits run.
- Re-running `bin/setup` completed successfully without changing the local configuration/application key, composer.lock, or tracked source files. Installation preserves an existing raw APP_KEY binding. All supported integration/timing/health settings are in config files and the credential-free environment example/configuration guide.
- Actual-account, recipient, external-monitor, off-host restore, and production observation gates remain unrun and deferred by the user's development-only instruction.

## P13 - Deferred by user

No production host is selected, no paid services were purchased, no real Telegram messages were sent, and no production deployment was performed. Future launch must close the release checklist's actual-account and authorized-recipient gates.

## P12 follow-up - Fresh environment configuration regression

- The first full CI run with the expanded environment example exposed a difference between missing and blank history settings. Blank/whitespace floors now normalize to unknown while an explicitly verified genesis floor of `0` remains distinct. Retention safety checks were preserved.
- Added four real bootstrapped-process configuration cases covering blank, whitespace, genesis, and a retained-height value.
- Replayed clean-checkout initialization from the full `.env.example` in a separate export with locked dependencies, then ran the entire strict suite: **167 tests / 911 assertions passed**. Existing development configuration/database were untouched.

## Native Debian deployment and website access configuration

- Replaced the Debian 13 deployment guide with native PHP 8.5, verified Composer installation, eight unprivileged systemd workers, durable local SQLite, and a required protected backup mount. Installation preserves an existing application key; updates stop writers before changing dependencies/schema.
- Added optional Nginx/PHP-FPM configuration and firewall instructions restricting HTTPS to Telegram's officially documented `149.154.160.0/20` and `91.108.4.0/22` source subnets. Forwarding headers are not trusted; IPv6 web access is denied. Polling is unchanged, and no webhook endpoint was added. Telegram's in-app browser uses the visitor's IP and is also blocked.
- Native Debian Nginx 1.26.3 validation passed: configuration syntax and 40 local HTTPS rejection checks covering IPv4, IPv6, application/static/unknown paths, forged forwarding headers, and alternate Host headers. A temporary local-source allowance separately verified static-file access and front-controller backend routing while other PHP files/dotfiles stayed denied; the repository allowlist was unchanged by that probe.
- Native PHP-FPM 8.5.12 configuration syntax passed using authenticated repository packages unpacked only into a temporary directory, with temporary socket/log paths and the current validation user. No FPM server was started. systemd unit verification passed with only the executable path mapped to this checkout; all guide Bash blocks and local documentation links passed checks.
- This is deployment preparation only. No live host, firewall, recipients, or production services were configured. P13 and real provider/recipient/off-host acceptance remain deferred.

## Private Telegram access - 2026-10-27

- Unknown/inactive users now receive no activation prompts or invalid-key replies. Successful single-use-key enrollment is preserved. Added optional `TELEGRAM_ALLOWED_USER_IDS` numeric-ID restrictions for enrollment, routing, queued notifications, and command replies; malformed lists fail closed.
- Replies revalidate the current grant and exact user/chat binding near HTTP dispatch. Anonymous legacy replies and work for revoked, expired, disabled, mismatched, or removed recipients are cancelled without sending. Transfer routing applies the audience check on both enqueue and delivery revalidation/replay, preserving other users' delivery.
- Added privacy regressions and real bootstrapped environment cases, including reserved boolean/null settings; test configuration isolates an externally injected allowlist. Updated architecture, deployment, and BotFather instructions. A blank list retains key-only invitations; listing an ID alone never grants access.
- Final `bin/verify` passed: runtime/SQLite/index checks, strict Composer validation, Pint (115 PHP files), and **212 tests / 1013 assertions**. All Telegram HTTP was simulated; no real bot settings, recipients, credentials, or production services were changed. Long-running workers must be stopped/restarted when applying audience configuration changes.

## Complete release ZIP and simple native installation - 2026-10-27

- Added `bin/release` to export committed source, install locked production dependencies in isolation, bundle Composer, write file hashes, and validate the actual ZIP after relocation before publishing it. Local configuration, databases, caches, and development dependencies are excluded; the development checkout's vendor directory is preserved.
- Added a first-install-only Debian 13 script for native PHP 8.5, private configuration/SQLite, and systemd. Manifest verification and existing-state checks protect configuration/data; no workers, web listeners, or firewall rules are started by installation. The `coin-bot` helper simplifies enrollment/startup and preserves backup-mount, access-policy, and recovery-pause checks.
- Replaced the primary installation guide with the complete-archive workflow; retained source installation instructions in the separate manual guide. Real credentials and the protected off-host backup mount remain operator-specific prerequisites.
- Before release packaging, strict `bin/verify` passed: runtime/SQLite/index checks, Composer, Pint (116 PHP files), and **212 tests / 1013 assertions**. Bash/embedded-PHP syntax, help/refusal behavior, guide shell blocks, and local links also passed. Production deployment and live acceptance remain deferred.

## Native application directory and one QuickNode endpoint - 2026-10-27

- Changed the native application/configuration path to `/home/www/coinbot` across installation, local administration, workers, Nginx, and both guides. SQLite and backup locations remain unchanged. Existing parent ownership/modes are preserved; symlinked or nontraversable parents are refused. The worker namespace hides other home directories while exposing only this application, with writable storage/cache exceptions.
- The user's sole QuickNode free endpoint is assigned to Ethereum. Added an independent Chainstack TRON Mainnet node as the secondary candidate, with one node base serving native HTTP and `/jsonrpc`. Actual account retention, capacity, and native receipt-by-block fallback remain unverified; no history floor or provider account was fabricated.
- Deployment Bash/guide syntax, mapped systemd verification, Pint (116 PHP files), and 40 local Nginx HTTPS rejection checks passed for the new path, including IPv6 and forged forwarding headers. Full-package relocation and isolated installation checks are part of release validation; production services and real recipients remain unconfigured.

## Debian 12 Bookworm support - 2026-10-27

- Added the shared `deploy/install-debian.sh` installer for Debian 12/Bookworm and Debian 13/Trixie. It validates version/codename before changes and selects the matching signed Sury repository for PHP 8.5. The earlier `install-debian13.sh` entry point remains a forwarding compatibility wrapper. Application, SQLite, backup, privacy, and existing-state protections are preserved.
- Added generic complete-package/manual guides and redirects from the previous Debian 13 documentation paths. Confirmed PHP 8.5 package availability for Bookworm and compatibility of the existing worker sandbox with systemd 252.
- Added `bin/verify-native-install` and CI matrix checks using fresh official Bookworm/Trixie filesystems: distribution rejection before APT, native PHP package provenance, application initialization, SQLite integrity, private permissions, environment isolation, parent preservation, reinstall refusal, and backup startup gates. Systemd reload is simulated, and no live workers or web services start.
