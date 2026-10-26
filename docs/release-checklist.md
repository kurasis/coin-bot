# Release acceptance checklist

Current scope is development only. P12 offline checks are implemented; live and external operational gates are **deferred by the user**. Offline acceptance is necessary but does not establish production readiness. P13 is deferred and cannot begin until every required gate below has evidence from the actual deployment and authorized accounts.

## Offline verification

Run from the repository root after `bin/setup`:

```bash
bin/verify
```

The verifier checks the selected PHP/runtime extensions, SQLite durability configuration, tracked local artifacts, a heuristic scan of staged secret additions, strict Composer validation, formatting, and the complete PHPUnit suite with warnings/risky tests/deprecations treated as failures. The secret heuristic cannot prove the absence of every possible credential format; code review and secure environment configuration remain necessary. The verifier does not send Telegram messages or require production API credentials.

Focused end-to-end acceptance:

```bash
bin/app php vendor/bin/phpunit tests/Feature/ReleaseAcceptanceTest.php
```

These cases use real Laravel services and real SQLite, with only the external blockchain and Telegram boundaries simulated. They are deterministic functional checks, not a provider throughput benchmark.

Targeted run on 2026-10-26: **2 tests, 270 assertions passed** under PHP 8.5.12 in approximately 3.05 seconds. This duration describes the small deterministic simulation, not recovery throughput on a real provider plan.

| Scenario | Required offline evidence |
| --- | --- |
| Shared scanning and user isolation | Two separately key-activated users, 100 distinct TRON addresses, 101 private user-wallet records, distinct private labels, and a shared address |
| Simulated 24-hour TRON backlog | 28,800 finalized blocks at three-second intervals, processed in 29 persistent ranges of at most 1,000 blocks |
| Interrupted collection | Failure after 10 completed ranges leaves the cursor at block 10,000, with 35 transfers and 36 outbox rows; a new client/scanner resumes at 10,001 |
| Complete recovery and event identity | 104 actual fixture events, including repeated identical events inside one transaction and a self-transfer, total 5,300.000000 USDT, exactly 105 destination notifications |
| Actual delivery pipeline and privacy | Simulated HTTP acceptance for all 105 notifications: Alice receives 52 and Bob receives 53; private labels never cross destinations, and the shared transfer keeps the same stable event ID |
| Transactional persistence | An SQLite trigger rejects outbox insertion; transfers, cursor advancement, and completed coverage all roll back together and succeed on retry |
| Revocation during pending work | Revoking Alice cancels her pending authorizations while Bob continues to receive the shared wallet events |
| Lost local acknowledgment after remote acceptance | A real SQLite update failure follows simulated Telegram success; the expired lease retries the identical message, demonstrating the documented duplicate boundary rather than losing the event |
| Reenrollment and silent historical repair | Fresh key activation preserves private labels, advances Alice's notification floor, and a silent repair does not send an older omitted event to her; Bob remains independently eligible |
| New activity after reenrollment | Both authorized users receive the new event; five notifications end as sent and two old Alice notifications remain cancelled, with six simulated acceptances because one was deliberately ambiguous |

Release acceptance also requires the wider suites for address/amount decoding, provider protocol failures, primary/secondary reconciliation, access-key races, wallet cutovers, Telegram throttling and enrollment, file-backed encrypted backup/restore, tamper detection, maintenance/fencing rules, and restored access invalidation. Link actual run output in the [development log](development-log.md); do not infer those results solely from this checklist.

## Required live and external gates

| Gate | Status | Evidence required to close |
| --- | --- | --- |
| Actual primary and secondary accounts for each chain | pending | Read-only `chain:probe` results for configured accounts, correct networks/finality, 100-address topic batching, representative recent/historical transfers, documented limits and history retention |
| Recovery capacity on actual provider plans | pending | Measured replay of the supported outage window with real endpoint response sizes, weighted billing/quota headroom, rate limits, and recovery throughput |
| Authorized staging Telegram delivery | pending | Bot credentials supplied securely; intended recipient enrollment through an individual key; confirmed received messages and appropriate permission/rate behavior |
| Genuine encrypted off-host persistence | pending | A backup artifact survives loss of the application host, with a verified recovery key stored separately and tested retention/access controls |
| External monitoring and host-loss detection | pending | A real independent monitor receives representative alerts and detects unavailable workers/host without relying exclusively on this bot |
| Passive-host restore and single-writer fencing | pending | A separate authorized target restores a backup, keeps the former writer fenced, invalidates stale access, and resumes verified scanning/delivery |
| Measured RPO/RTO | pending | Record actual lost-state interval, replay duration, and any user-reactivation delay; compare with provisional one-minute backup and 5-15-minute recovery objectives |
| Production target and protected configuration | pending | Authorized deployment target, actual credentials/configuration, persistent local storage, key issuance procedures, rollback/runbooks, and operational ownership |
| Production observation | pending | At least the initial 24-hour observation period with no unexplained coverage gaps or misrouted notifications |

Public research RPC probes and simulated HTTP acknowledgments do not close the actual-account or authorized-recipient gates. No application code path can guarantee exactly-once Telegram delivery after an ambiguous response. SQLite remains a single-active-host design; asynchronous backups do not provide zero RPO.
