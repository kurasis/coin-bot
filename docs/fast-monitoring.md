# Faster Ethereum notifications and monitoring alerts

Ethereum can send a **Preliminary** USDT notification after six subsequent blocks, targeting roughly **1-2 minutes after inclusion in a block** under normal conditions. This includes about 72 seconds for six normal Ethereum blocks plus the early worker's 15-second polling interval, RPC work, and Telegram delivery. It is not a delivery SLA and does not include time spent waiting in the mempool. Missed blocks, provider limits, outages, and a delivery backlog can increase the delay.

The preliminary message says `not finalized`. Ethereum's finalized scan and independent reconciliation continue separately. TRON continues to notify from solidified state.

## Settings

The new `.env.example` enables early Ethereum notifications. An existing installation whose environment has no `ETHEREUM_EARLY_ENABLED` setting keeps the runtime default of `false` until explicitly upgraded/enabled.

```dotenv
ETHEREUM_EARLY_ENABLED=true
ETHEREUM_EARLY_CONFIRMATIONS=6
ETHEREUM_EARLY_REORG_WINDOW=128

OPERATIONS_ALERT_USER_ID=1
OPERATIONS_ALERT_FAILURE_THRESHOLD=3
OPERATIONS_ALERT_MIN_OUTAGE_SECONDS=30
OPERATIONS_ALERT_CHECK_INTERVAL_SECONDS=15
```

`OPERATIONS_ALERT_USER_ID=1` means **internal application User ID 1**, not Telegram user ID `1`. Check the mapping with:

```bash
sudo coin-bot access:users
```

The alert recipient still needs an active individual grant, an allowed Telegram ID when an allowlist is configured, and an enabled private destination. `/stop`, revoked/expired access, and allowlist removal also stop these operational messages. Selecting an alert ID does not grant access. To choose another existing operator, change `OPERATIONS_ALERT_USER_ID` while all workers are stopped and clear cached configuration before restart.

## Preliminary observations and finality

The early worker checks official USDT events against successful receipts and the observed canonical block data. It stores preliminary observations separately from the finalized transfer ledger and never advances finalized coverage. Six subsequent blocks reduce uncertainty but do not provide Ethereum consensus finality.

When the same observation becomes finalized, it is linked to the canonical transfer and uses the existing outbox entry for that destination. Normal finalization does not send a second ordinary notification after an already delivered preliminary message. A message that is still pending can be delivered with its finalized state instead.

If an observed block changes, bounded recovery requires an independent provider to agree on the replacement canonical evidence. A correction is eligible only for a destination that actually or possibly received the invalidated preliminary observation and is still authorized. Unsent observations are cancelled without a correction. The correction identifies the invalidated block observation; it does not claim the transaction can never appear in another block. A later canonical observation can be new information.

The default recovery window is 128 blocks. Missing independent evidence defers affected preliminary work; a change outside the bounded recovery evidence pauses early monitoring for investigation. Existing finalized coverage is not rewritten to make an early observation fit. Ambiguous Telegram responses can still produce a duplicate on retry; no exactly-once delivery guarantee is added.

If a wallet is disabled before its preliminary observation reaches the finalized scan, that old observation can remain unresolved. A later reorg outside the recovery window can conservatively pause early monitoring even for that disabled wallet. Review the retained evidence before resuming; finalized monitoring remains separate.

## Sustained monitoring outages

Each chain tries the available primary/fallback provider before reporting its monitored check as unavailable. A successful fallback or a real checked idle head resets the failure sequence. An isolated failure or two failed checks do not create an outage notification.

A provider returning valid but stale head data still counts as a successful API check. These outage alerts do not replace independent checks of chain-head freshness and coverage lag.

With the defaults, an outage requires **three consecutive unavailable checks spanning at least 30 seconds**. Ethereum's early and finalized workers share the incident state and count at most one failure every 15 seconds, including across rapid restarts. RPC timeouts, fallback attempts, provider cooldowns, and delivery queues can extend the time before a message arrives.

When both providers are cooling down, scheduled checks can still count known unavailability without making another HTTP request. The worker preserves `Retry-After` and circuit backoff; the alert counter is not permission to probe early.

One logical outage notification is queued per episode. A recovery notification is queued only if the outage notification was sent or could have reached the recipient. If the outage recovered before it could be delivered, there is no standalone recovery message. Intentional maintenance and incomplete initial setup do not create API outage messages; a safety-paused scanner can report that monitoring cannot continue.

These messages require the application, SQLite, delivery worker, Telegram, and the recipient's access to remain usable. A dead host, stopped entire process group, broken database, or Telegram outage cannot reliably report its own failure through this bot. Keep independent external monitoring for those failures.

## Existing native installation

Use the **new full ZIP**, extracted into a separate directory, and run the updater from that extracted `coin-bot` directory. Do not extract over `/home/www/coinbot` or rerun the first-install script.

The updater supports the released native components from `88483c3`, `b908565`, and `48df016`, including the earlier release with its verified backup-mode update. If the installation has no managed backup policy yet, apply `sudo bash deploy/update-native-backups.sh` from the new ZIP first. Preserve an already selected local backup policy; offhost mode still needs its configured mount.

```bash
sudo coin-bot stop
sudo bash deploy/update-native-monitoring.sh && sudo coin-bot start
sudo coin-bot status
```

The monitoring updater verifies compatible code, requires stopped/fenced writers, creates an encrypted pre-upgrade backup, installs the monitoring components, clears caches, and runs migrations. It preserves `APP_KEY`, credentials, SQLite user/wallet state, the selected local/offhost backup policy, installed dependencies, and existing sites. It enables the early settings above, sets the alert recipient to internal User ID 1, adds the heartbeat requirement, and reloads systemd. **It does not start workers.** Check the intended recipient before starting.

The updater supports an existing shared website parent such as `/home/www` owned by `www-data`, including mode `777`. It holds the verified root-owned application directory as its working directory and keeps privileged application writes relative to that directory. Application/PHP configuration rendering runs as `coin-bot`; root treats its output as bounded data and never loads installed vendor or application PHP. Root-owned protected application directories, compatible code, and protected system destinations remain required. The updater preserves website parent ownership/modes and refuses symlinks, unknown custom code, and unsupported overrides.

An error from the earlier updater saying `Native code parents must be root-owned` means it stopped before installing the feature. Extract the current ZIP separately and retry its updater. Changing `/home/www` ownership or permissions is unnecessary. If any update step fails after the backup, keep workers stopped, preserve that backup, and resolve the failure before restarting.

The shared parent remains part of the existing runtime trust boundary: an account that can replace the entire application path can interfere with unprivileged workers. The updater's held directory protects privileged update operations, not the entire hosting layout. Privileged `backup:local`/`backup:offhost` mode changes require protected root-owned path ancestors and refuse a shared writable parent; an already selected local/offhost policy continues to work with `start`, ordinary commands, and this updater.

## Diagnose stalled monitoring

A successful idle Ethereum scan records its heartbeat even while waiting for finality. A heartbeat missing for an hour and repeated worker exits indicate failed checks, not the normal finalized-block delay. The old generic failure line does not identify whether the cause is authentication, limits, transport, or blockchain data.

After updating, these commands report provider roles, safe failure categories/HTTP status, heads, cooldowns, and history readiness without displaying endpoint URLs or credentials:

```bash
sudo coin-bot chain:diagnose ethereum
sudo coin-bot chain:diagnose tron --offline
sudo coin-bot chain:reconcile tron --once
```

`chain:diagnose` leaves cursors, transfers, provider circuits, history declarations, and incident state unchanged. Online mode makes bounded read requests to configured available providers; `--offline` makes none. Copying the JSON output is suitable for troubleshooting; do not share `.env`, complete provider URLs, or tokens.

A fresh TRON scan heartbeat with growing unreconciled ranges means primary scanning and independent verification have different progress. A missing/too-high provider history floor can prevent reconciliation before any provider request. `waiting_for_history_configuration` requires verified account retention, and `waiting_for_finality` requires additional finalized provider evidence. Configure a history floor only after checking actual provider retention; `0` asserts verified full history and must not be used to silence a health warning.

## HTTP 400/413 recovery

The 2026-10-27 incident showed Ethereum primary HTTP 400, secondary HTTP 413, a stalled finalized cursor, and TRON `waiting_for_history_configuration`. These are separate failure paths; an open provider circuit alone does not establish rate limiting.

The current transport treats HTTP 413 **on `eth_getLogs` only** as a query payload limit. The scanner first halves the address topic array, then reduces the block range if needed. A positive `Retry-After` on HTTP 413 defers requests instead of splitting immediately. HTTP 400 is treated as a query limit only when its bounded JSON-RPC error explicitly reports a recognized range/result/payload limit. Authentication errors, malformed error bodies, and unclassified HTTP 400 responses remain provider failures. HTTP 413 on network/head/receipt operations remains a provider error too. If a single-block/single-address query still fails, coverage stays unfinished. No cursor reset, skipped range, fabricated finality, or circuit reset is required.

Use the full ZIP's native monitoring updater as above. It preserves the existing provider settings; it does not replace accounts or silently select public endpoints. Existing mandatory cooldowns remain in force after updating. A first check may still wait up to the remaining circuit delay or the provider's `Retry-After`.

Scanner failure lines now include a second JSON line with fixed categories, HTTP status, provider role and failed operation (`network`, `finalized`, `block`, `logs_incoming`, `logs_outgoing`, or `receipt`). URLs, addresses, request bodies, exception messages, and traces are excluded. `chain:diagnose` also reports each selected provider's persisted `last_failure.http_status` and `consecutive_failures` during cooldown. These fields describe the last stored circuit failure, not a new request or proof that the endpoint is still failing. An unclassified 400 needs its operation and actual account capabilities investigated; it cannot safely be assumed to be a size limit.

### TRON history prerequisite

The monitoring updater does not invent provider retention settings. If both TRON history floors are unknown, reconciliation deliberately waits even when scanning and its heartbeat are healthy.

1. Run `sudo coin-bot chain:diagnose tron --offline` and note `oldest_from_block` and `oldest_to_block`.
2. Verify historical log and receipt access on **both actual configured accounts**, including known matching transfers at the required boundary. `chain:probe tron --provider=primary --from=<first> --to=<last> --address=<known-transfer-address>` and the equivalent `--provider=secondary` are read-only sampled checks; an empty response alone does not certify retention. Check the account's documented retention as well.
3. Stop workers with `sudo coin-bot stop`. Use `sudoedit /home/www/coinbot/.env` to set `TRON_PRIMARY_HISTORY_START_BLOCK` and `TRON_SECONDARY_HISTORY_START_BLOCK` to the verified lower boundaries. Each must cover the oldest pending block. In the reported incident that block was **87477900**; this observation is a coverage requirement, not evidence that either account supports history from that height. Do not set `0` unless full history has actually been verified.
4. Run `sudo coin-bot config:clear`, then `sudo coin-bot chain:reconcile tron --once`. A successful check reports a verified range. Restart with `sudo coin-bot start` and monitor `sudo coin-bot status`; clearing a backlog takes time and depends on RPC limits.

If an account cannot serve the oldest pending range, restore access through a suitable account/provider. Do not delete the queue or advance a watermark past unverified coverage to clear health issues. Ethereum's history floors also need verification before its new finalized ranges can be independently reconciled.

## Catching up a TRON reconciliation backlog

A live scanner can produce many one- or two-block ranges. Revalidating the network and finalized head for every range, plus a one-second pause after each range, can make independent verification fall behind even with fresh worker heartbeats. Compare `reconciliation_lag_blocks_tron` and `oldest_unreconciled_age_tron` over time as well as pending range counts: a falling count alone does not establish catch-up when range widths differ.

The reconciliation daemon now drains bounded batches, defaulting to at most 25 ranges. Each provider's network verification and conservative finalized head are shared only within that batch. A new batch starts no additional ranges once 15 seconds have elapsed; an in-flight range may finish later. All boundary blocks, log queries and receipt witnesses still use per-range checks and per-range caches, and each range commits separately. History floors, maintenance, paused cursors, provider cooldowns, finality waits, conflicts and contiguous-watermark checks still apply to each range. An error stops the batch; previously committed ranges remain verified, and unfinished ranges remain pending. The default pause is one second after a productive batch and the configured idle interval after waiting/idle results.

Use `chain:reconcile tron --batch=1` to limit the daemon to one range per batch if the actual account needs a slower schedule. The supported maximum is 100. `--once` continues to process at most one range regardless of `--batch`. Batching reduces redundant calls and pauses; it is not permission to ignore provider quotas, reset cooldowns or start concurrent reconciliation workers.

Install with the full ZIP's usual native monitoring updater while workers are stopped, then start the existing target. The updater supports the `12bfcfa` components and preserves verified history-floor settings and all existing queues. No schema migration, extra worker or endpoint change is introduced. Measure catch-up on the actual accounts after updating; synthetic request counts do not predict a live recovery time.

## Worker and capacity

The additional ninth native unit is `coin-bot@ethereum-early.service`, running `ethereum:early-scan` with a normal 15-second interval. Its health name is `scan-ethereum-early`; the native installer/updater adds it to `OPERATIONS_REQUIRED_WORKERS`. The existing `coin-bot start` command starts the complete target, including this unit. To inspect the early worker:

```bash
sudo systemctl status coin-bot@ethereum-early.service
sudo journalctl -u coin-bot@ethereum-early.service --since '10 minutes ago'
```

Early monitoring adds head, receipt, and block checks and therefore increases RPC quota consumption. Reorg recovery also needs the independent provider. Verify the actual account limits and leave quota for finalization, reconciliation, and recovery. The 1-2 minute target applies to normal operation, not backlog recovery or provider throttling.

Developers can run `bin/verify-native-monitoring` or `bin/verify-native-monitoring FULL_ZIP` to exercise the native update in an isolated, network-free fixture. These checks do not enroll real recipients or start production workers.
