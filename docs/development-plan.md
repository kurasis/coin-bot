# Development plan

Status: implementation in progress. See the stage table and [development evidence](development-log.md) for completed work and outstanding live gates. Existing research probes alone are not application acceptance tests.

Scope: PHP 8.5, Laravel 13, SQLite; official USDT on Ethereum and TRON; approximately 100 distinct addresses shared across multiple isolated users; individual key-based activation; finalized incoming/outgoing transfer notifications. See [the architecture](architecture.md) for the governing decisions and delivery limitations.

## Execution and tracking

Use stable stage IDs P01-P13 in implementation tasks, commits, and pull requests. Track each stage as `planned`, `in_progress`, `blocked`, or `done`, with evidence linked when it is complete. Stage numbering describes the recommended order; independent work may proceed when external prerequisites block live checks.

A stage is done only when its deliverables and required acceptance checks pass. Record simulated, live, skipped, and blocked checks separately. Test each feature in its own stage; P12 combines them into release-level acceptance rather than postponing correctness tests until the end. Complete relevant checks and commit/push/merge automatically within the task's authorized scope, following repository instructions.

Missing production credentials do not block fixture-based implementation. They do block the affected live validation and production launch. Receive values through secure environment configuration, never repository files or chat. Do not buy services or send messages to unapproved recipients as part of planning or offline testing.

## Stage overview

| ID | Stage | Main result | Dependencies | Status |
| --- | --- | --- | --- | --- |
| P01 | Runtime and project foundation | Reproducible Laravel application and CI | None | done |
| P02 | Multi-user domain and persistence | Isolated ownership, ledger, cursors, and outbox schema | P01 | done |
| P03 | Individual access keys | Issuance, single-use activation, expiry, and revocation | P02 | done |
| P04 | RPC adapters and provider capabilities | Validated chain clients and capability matrix | P01, P02 | planned |
| P05 | Durable scanning engine | Atomic range processing and resumable coverage | P02, P04 | planned |
| P06 | Ethereum USDT monitoring | Finalized Ethereum ingestion | P05 | planned |
| P07 | TRON USDT monitoring | Solidified TRON ingestion and native fallback | P05 | planned |
| P08 | User wallets and historical import | Scoped administration, watch-set cutovers, and backfill | P03, P06, P07 | planned |
| P09 | Telegram activation and delivery | Authorized enrollment and durable notifications | P03, P05, P08 | planned |
| P10 | Independent reconciliation and failover | Detect omissions and recover provider outages | P06, P07, P08, P09 | planned |
| P11 | Deployment, monitoring, and backups | Supervised single-host service and recoverable state | P09, P10 | planned |
| P12 | Release acceptance and recovery drills | Measured correctness, load, quota, and restore evidence | P10, P11 | planned |
| P13 | Production launch | Verified authorized deployment and operational handover | P12 | planned |

## P01 - Runtime and project foundation

**Deliverables:** Bootstrap Laravel 13 with pinned Composer dependencies on PHP 8.5. Provide a safe `.env.example`, local setup instructions, SQLite initialization, and CI checks for the selected runtime. Verify required extensions, including PDO SQLite and BCMath. Use existing isolated checkouts; do not create an extra Git worktree by default.

**Acceptance:** A clean checkout installs from its lockfile, creates/migrates a temporary SQLite database, and runs a meaningful Laravel feature test under PHP 8.5. No application secret or generated database enters source control. Record the commands needed to reproduce setup.

## P02 - Multi-user domain and persistence

**Deliverables:** Implement users, hashed access-key metadata, access grants, global chain/address records, user-owned wallet labels, destinations/permissions, subscriptions, immutable watch sets, coverage cursors/ranges, transfers, outbox authorizations, and delivery attempts. Add durable inbound update deduplication/offset state for later Telegram integration. Enable WAL, foreign keys, `synchronous=FULL`, busy timeout, and short write transactions.

Establish subscription routing and outbox uniqueness now so scanners can save event, notification intent, and coverage atomically before the Telegram transport exists. Store raw token amounts as decimal strings and identify transfers by chain, transaction hash, and full-receipt log position.

**Acceptance:** Real SQLite integration tests prove unique event/destination constraints, transaction rollback, precise uint256 handling, and user ownership enforcement. Two users may follow one public address with separate labels/settings; neither can inspect or mutate the other's configuration. Shared scanning does not create shared private data access.

## P03 - Individual access keys

**Deliverables:** Add operator-only commands to issue, expire, and revoke individual activation keys and revoke user grants. Use at least 256 bits of random entropy, store only hashes, show the raw key once at issuance, and separate activation-key expiry from grant expiry. Default activation-key lifetime is 24 hours. Implement a transport-independent activation service bound to numeric Telegram user IDs.

Reject shared-password behavior: one key activates one user atomically. Ordinary user access never grants operator privileges. Add rate limits and secret-redacted audit records. Model revocation so pending delivery loses authorization unless another valid subscription still permits that destination.

**Acceptance:** Test valid, invalid, expired, and revoked keys; simultaneous redemption by two users; duplicate activation updates; grant expiry; pending deliveries after revocation; and privilege escalation attempts. Logs and stored records contain no raw activation keys. Offline activation tests need no Telegram messages.

## P04 - RPC adapters and provider capabilities

**Deliverables:** Implement separate Ethereum and TRON clients behind shared scanner interfaces. Support bounded timeouts, JSON-RPC/body errors, response validation, retries with jitter, and vendor-specific configuration. Verify network identity and normalize receipts without conflating chain-specific finality/address conventions.

Maintain a capability matrix for both actual vendors per chain: finality source, numeric historical log ranges, 100-address topic OR filters, receipt access, range/result limits, retention, and quotas. TRON finality comes from native solidity APIs; numeric ranges avoid assuming hosted gateways share finality-tag support.

**Acceptance:** Deterministic HTTP fixtures cover malformed data, HTTP-200 errors, 403/429/5xx, timeouts, and wrong networks. Read-only live probes on authorized accounts verify known recent/historical events and filter results. If credentials are unavailable, keep the live capability gate explicitly blocked while independent offline work continues; public research endpoints do not certify production accounts.

## P05 - Durable scanning engine

**Deliverables:** Build bounded block-range processing with immutable watch-set snapshots, separate sender/recipient filters, adaptive range/address batching, receipt-based decoding, canonical boundary checks, and stable event identities. Merge overlapping matches and create authorized outbox intents in the same database transaction as transfer persistence and cursor advancement.

Keep chain progress independent. Retry unfinished ranges after crashes; do not advance coverage on incomplete directions, missing receipts, truncated results, or failed writes. Include shared-wallet/per-destination deduplication and hook points for reconciliation.

**Acceptance:** Tests interrupt work before/after commit, repeat ranges, split oversized results, and include multiple identical events in one transaction. Coverage never skips an unfinished range. One range creates one event per actual log and one notification intent per authorized destination, even when multiple users follow the address.

## P06 - Ethereum USDT monitoring

**Deliverables:** Wire the engine to Ethereum mainnet's official USDT contract and `finalized` head. Validate receipt success, block identity, indexed addresses, and exact amounts. Add an independently supervised scanner command and documented operational settings.

**Acceptance:** Exercise incoming, outgoing, watched-to-watched, self-transfer, contract-initiated transfers, zero values, large amounts, and fake-USDT rejection. Verify no final notification intent is created for a nonfinal block. Known authorized historical ranges produce the expected event set; a TRON outage does not stop this scanner.

## P07 - TRON USDT monitoring

**Deliverables:** Wire the engine to official TRON USDT, validate Base58Check addresses and TRON-to-topic conversion, and derive the scan ceiling from `/walletsolidity/getnowblock`. Implement solidified receipt verification and native block/receipt fallback for RPC result limitations.

**Acceptance:** Run the same transfer cases as P06 plus invalid address checks, numeric-range compatibility, and provider event-index normalization. Compare sampled JSON-RPC events with native solidified receipts. Indexer delay or an unavailable solidified receipt must not close a range incorrectly. An Ethereum outage does not stop this scanner.

## P08 - User wallets and historical import

**Deliverables:** Add operator-only, explicitly user-scoped wallet/subscription administration and validated batch import for the expected address count. Support private labels, directions, minimum amounts, enable/disable state, monitoring start blocks, and destination permissions. Registering a second user's existing address reuses scanning without reusing private settings.

Serialize watch-set changes at an atomic per-chain boundary `H`: the new membership applies from `H+1`, with separate requested backfill through `H`. Historical import has its own cursor and explicit notification policy; silent import is the default. Activation never sends another user's stored history.

**Acceptance:** Test ownership violations and two-user shared-address subscriptions, add/remove during an in-flight range, backlog import, restart, and duplicate imports. Prove no activation gap, no unsolicited old notifications, and no change to another user's monitoring or settings.

## P09 - Telegram activation and delivery

**Deliverables:** Integrate private-chat `/start <key>` with P03 using a single supervised long-polling worker, durable offsets, and idempotent update handling. Unknown users receive a neutral activation prompt. Add minimal account/delivery controls; user commands cannot perform operator administration.

Implement the durable outbox worker with leases, per-chat/global rate limits, `retry_after`, backoff, visible failed deliveries, and controlled replay. Check user grants, subscription state, and destination permissions immediately before sending. Format exact amounts and include network, wallet label, direction, finality, explorer link, and stable event ID. Private chats are the initial delivery target; group/channel delivery is optional and requires separate permission tests before enabling it.

**Acceptance:** Prove private-chat binding to Telegram user ID, duplicate update handling, two-user notification isolation, correct delivery for shared wallets, and no access after revocation/expiry. Simulate success, rate limits, blocked chats, lost responses, and worker crashes. Test real delivery only to explicitly authorized staging recipients. Document unavoidable ambiguous-send duplicates; do not claim exactly-once Telegram delivery.

## P10 - Independent reconciliation and failover

**Deliverables:** Add secondary-provider scanning with separate coverage, identical watch-set versions, canonical boundary comparison, normalized event-set comparison, and a reconciled-through watermark. Restrict secondary ranges to that provider's verified finalized/available history. Resolve omissions through receipt verification and replay; deliver recovered events through the same outbox rules.

Switch unfinished primary ranges to an independently configured backup with bounded circuit breaking and recovery. Preserve backlog rather than skipping ahead during an outage. Define and validate supported history depth and recovery quota.

**Acceptance:** Simulate a silent omitted transfer, lagging secondary head, vendor outage, mismatched block hash, exhausted quota, and restoration. Recovered transfers appear once in the ledger; no unavailable range is marked reconciled and unrelated chains/destinations continue working.

## P11 - Deployment, monitoring, and backups

**Deliverables:** Provide a reproducible deployment with persistent local SQLite storage and supervised scanner, Telegram update, outbox, and scheduled jobs. Implement graceful stop/restart, bounded concurrency, consistent encrypted off-host backups, retention, and a passive-host recovery runbook with single-writer fencing. Restore with enrollment/sending disabled; default to invalidating all restored activation keys/grants and their delivery authorizations before issuing fresh access. Preserving access instead requires verified replay of all later security changes from a separately durable journal.

Monitor cursor/reconciliation lag, finality stalls, oldest pending delivery, denied destinations, worker heartbeat, disk/WAL growth, database contention, backup freshness, and grant/key administration. External failure reporting must not depend solely on this bot. Capture installation/startup instructions for the deployment and development environments.

**Acceptance:** Restart every worker safely, verify configuration permissions and secret redaction, restore a consistent backup and run SQLite integrity checks, and trigger representative external alerts. An old backup cannot reactivate a consumed key or revoked grant. Never copy only the live main database while ignoring WAL. Do not run writable replicas over a shared network filesystem.

## P12 - Release acceptance and recovery drills

**Deliverables:** Execute and record an end-to-end acceptance matrix using 100 distinct addresses and multiple users, including shared addresses and busy-wallet activity. Include grant revocation and key-redemption races under background scanning/delivery.

Run crash, provider-failure, SQLite-contention, and Telegram-failure scenarios. Recover at least a simulated 24-hour backlog, then verify representative real-history replay and measured quota/capacity on actual provider plans. Drill complete host loss, fence the old writer, restore the passive host, replay coverage, and recover delivery leases.

**Acceptance:** Required cases pass with measured delivery lag after chain finality, quota headroom, recovery throughput, RPO, and RTO. Record whether provisional RTO 5-15 minutes and one-minute backup objectives were met; do not substitute targets for evidence. Include any reactivation delay in delivery RTO. Restore a backup predating key redemption/grant revocation and prove both remain unusable under the recovery policy. Explicitly record possible resend behavior after ambiguous Telegram delivery and backup restore. No unresolved ownership leak, incomplete-range advancement, or required live capability check remains.

## P13 - Production launch

**Deliverables:** Deploy to the configured authorized production target using tested runtime/dependency versions. Configure actual primary/secondary providers, operator identity, bot credentials, backups, and external monitoring securely. Issue individual keys for intended users; import their wallets with explicit start blocks and notification policies.

Start with a small authorized cohort, compare both providers' coverage and actual recipient delivery, then expand to the target address/user count. Provide operating instructions for issuing/revoking access, user-scoped wallet changes, failed-delivery replay, provider switching, backup restore, and application rollback. Rollback must preserve database compatibility and monotonic coverage rather than resetting state.

**Acceptance:** Staging and production account/destination checks pass; all live P04/P09/P12 gates are complete. An initial 24-hour observation period has no unexplained coverage gaps or misrouted notifications. Actual service limits and recovery results are documented, along with any acknowledged nonblocking limitations.

## Deferred features

Graphical administration, self-service wallet editing, optional shared group/channel delivery, provisional Ethereum alerts, managed webhook acceleration, and PostgreSQL HA are outside the initial release unless requested. None substitutes for the required key access, user isolation, durable scanning, authorized delivery, reconciliation, and restore capability above.
