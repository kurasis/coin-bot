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

The updater supports the released native components from `88483c3` and `b908565`, including the earlier release with its verified backup-mode update. If the installation has no managed backup policy yet, apply `sudo bash deploy/update-native-backups.sh` from the new ZIP first. Select local backups with `sudo coin-bot backup:local` if that is your chosen policy; offhost mode still needs its configured mount.

```bash
sudo coin-bot stop
sudo bash deploy/update-native-monitoring.sh
sudo coin-bot start
sudo coin-bot status
```

The monitoring updater verifies compatible code, requires stopped/fenced writers, creates an encrypted pre-upgrade backup, installs the monitoring components, clears caches, and runs migrations. It preserves `APP_KEY`, credentials, SQLite user/wallet state, the selected local/offhost backup policy, installed dependencies, and existing sites. It enables the early settings above, sets the alert recipient to internal User ID 1, adds the heartbeat requirement, and reloads systemd. **It does not start workers.** Check the intended recipient before starting.

The updater refuses unknown custom code, unsupported overrides, or unsafe code-parent ownership rather than changing shared parent permissions. If it refuses, keep workers stopped and review the reported paths; do not change ownership of existing website parents to force the update. If any update step fails after the backup, preserve that backup and resolve the failure before restarting.

## Worker and capacity

The additional ninth native unit is `coin-bot@ethereum-early.service`, running `ethereum:early-scan` with a normal 15-second interval. Its health name is `scan-ethereum-early`; the native installer/updater adds it to `OPERATIONS_REQUIRED_WORKERS`. The existing `coin-bot start` command starts the complete target, including this unit. To inspect the early worker:

```bash
sudo systemctl status coin-bot@ethereum-early.service
sudo journalctl -u coin-bot@ethereum-early.service --since '10 minutes ago'
```

Early monitoring adds head, receipt, and block checks and therefore increases RPC quota consumption. Reorg recovery also needs the independent provider. Verify the actual account limits and leave quota for finalization, reconciliation, and recovery. The 1-2 minute target applies to normal operation, not backlog recovery or provider throttling.

Developers can run `bin/verify-native-monitoring` or `bin/verify-native-monitoring FULL_ZIP` to exercise the native update in an isolated, network-free fixture. These checks do not enroll real recipients or start production workers.
