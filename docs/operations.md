# Operations and recovery

This is an implementation/runbook, not evidence that production or off-host storage has been configured. Production launch still requires verified provider accounts, an authorized bot and recipients, a protected off-host backup mount, external monitoring, and measured P12 recovery drills.

## Runtime and deployment

For native PHP 8.5/systemd installation, use the [Debian 12/13 guide](deploy-debian.md). The optional container deployment below uses the pinned PHP 8.5 image produced by `bin/build`. Install locked dependencies before either deployment. No Node, Redis, public webhook listener, or public web server is required for the notification workers. Keep `.env` outside source control with mode `0600`; preserve `APP_KEY` in secure operator-managed storage separately from encrypted backups. SQLite storage must be local to one active host. If a website is enabled, apply the [Telegram-only access policy](telegram-only-website.md).

`deploy/compose.yaml` mounts application files read-only and keeps database, storage, and generated bootstrap cache in local named volumes. Workers run as the configured unprivileged UID/GID. The one-time `prepare` service creates/chowns the persistent directories, then `migrate` applies migrations before worker startup. The checkout must already contain installed `vendor` dependencies and a valid environment file.

Set `COIN_BOT_BACKUP_PATH` to a protected host mount backed by an independently retained off-host backup destination, then use `docker compose -f deploy/compose.yaml up -d`. This setting only mounts a path: operators must verify remote persistence, encryption at rest, access controls, and restore access. A directory on the same host is useful for development but does not satisfy the off-host requirement. Filesystem hosting requirements for encrypted backup artifacts do not permit sharing the live SQLite WAL database over NFS.

Production workers are `chain:scan ethereum`, `chain:scan tron`, `telegram:poll`, `telegram:deliver`, `wallet:backfill-run`, `chain:reconcile ethereum`, `chain:reconcile tron`, and `ops:backup --loop --interval=60`. Supervisor restarts a failed worker; Docker health status alone does not restart an unhealthy-but-running process. Use external monitoring for the whole host and alert on health failures independently of this bot's Telegram route.

Bootstrap enrollment before starting scanners that require configured wallets:

1. Configure real bot/provider credentials and the protected backup mount. Start only enrollment, delivery, and backups with `docker compose -f deploy/compose.yaml up -d telegram-updates telegram-delivery backups`; their dependencies prepare and migrate the persistent database. Health remains incomplete until the expected services/wallets are provisioned.
2. Issue a key through the trusted local console, for example `docker compose -f deploy/compose.yaml run --rm --no-deps telegram-updates php artisan access:issue`. Show/distribute the raw key securely once; do not place it in deployment logs. The intended user activates it in a private bot chat.
3. Resolve the internal user ID with the operator-only `access:users --telegram-id=<numeric-id>` command, then use `wallet:add ... --user=<internal-id>` or `wallet:import ... --user=<internal-id>`. These commands are never exposed as Telegram administration. Verify the user mapping before importing wallets.
4. Start the remaining worker set with `docker compose -f deploy/compose.yaml up -d` and confirm operational coverage, delivery, and backup health.

Missing cursors or credentials must remain visible rather than inventing a start block or silently switching public RPC endpoints. Empty installations may report incomplete operational readiness until configuration and initial backups exist.

## Health and diagnostics

`php artisan ops:health` outputs JSON containing operational metrics and issue codes, never wallet addresses, chat IDs, provider URLs, activation keys, or bot tokens. It exits nonzero when paused, database access fails, a cursor is paused/stale, deliveries fail/back up, destinations are suspended, disk/WAL thresholds are exceeded, backups are missing/stale, or a required worker heartbeat is missing/stale.

Configure `OPERATIONS_REQUIRED_WORKERS` as a comma-separated list for enabled daemons. The compose configuration expects `scan-ethereum`, `scan-tron`, `telegram-poll`, `telegram-deliver`, `backfill`, `backups`, `reconcile-ethereum`, and `reconcile-tron`. A worker records a heartbeat after a successful/idle unit. Watchdog thresholds are configurable; the cursor progress default is 1,200 seconds to avoid treating normal Ethereum finality intervals as scanner failure. Worker-heartbeat default is 120 seconds; backup-age default is 180 seconds. `OPERATIONS_MAX_UNRECONCILED_AGE` defaults to 1,800 seconds: older completed primary ranges without independent reconciliation cause degraded health even when the primary cursor and all worker heartbeats remain fresh. Metrics include the independent watermark and block lag; private historical backfills are excluded. Open provider circuits and unresolved reconciliation conflicts also raise health issues. Review finality stalls and provider disagreement alongside these local measurements.

The backup status reports creation of a local mounted artifact, not proof that an asynchronous remote replication service copied it. Monitor the remote destination separately. Monitor all daemons externally as well as from within containers; an entirely dead host cannot report its own health.

## Consistent authenticated backups

`php artisan ops:backup` uses SQLite's online backup API to produce a consistent temporary local snapshot, verifies `PRAGMA integrity_check`, and streams it into a `.cbk` artifact using authenticated XChaCha20-Poly1305 secretstream encryption. Plaintext temporary snapshots stay beside the local database with restricted permissions and are removed after processing. The protected destination receives encrypted artifacts only. No raw main-file copy is used, and active WAL contents are included by SQLite's backup API.

The encryption key is derived from the configured application key with an application-specific HKDF context. This avoids a second unconfigured secret while keeping backup encryption separate from Laravel ciphertext. Restoring requires the same original application key; preserve it securely off-host and never place it in the artifact or the repository. Application-key rotation requires retaining old keys for their backup retention period. A wrong key, modified ciphertext, truncation, or trailing data makes restore fail.

`OPERATIONS_BACKUP_DIRECTORY` selects the encrypted artifact directory. Default retention is 1,440 completed artifacts, corresponding to approximately one day at one-minute intervals; choose a separate long-term retention policy at the off-host destination. The backup directory cannot be under `public/`. Keep filesystem permissions restrictive and monitor failures/capacity. Taking a backup after important user/key/subscription configuration changes reduces the normal interval's RPO, but does not by itself preserve later revocations.

## Pause and restore

`php artisan ops:pause` persists a marker checked by worker units in addition to Laravel maintenance mode. This prevents new units; a request already in flight can still finish. **Pause is not fencing** and does not prove every process is stopped.

For a host-loss or rollback recovery:

1. Fence the old active host and stop all writers, update polling, delivery, and backup daemons. Ensure only one host can open the persistent database for writing. Preserve the existing database and its consistent backup separately before destructive replacement when possible.
2. On the recovery host with matching code/dependencies and the original application key, run `ops:pause`. Keep Laravel maintenance active as appropriate. Do not expose enrollment or resume sends yet.
3. Put the encrypted artifact on protected storage and run `php artisan ops:restore /protected/path/backup.cbk --force --workers-stopped --operator=operator-id`. The explicit flag records the operator's confirmation that writers were stopped; the command cannot remotely fence another host.
4. Restore authenticates/decrypts into a staged local database, runs integrity checks, rejects migration histories newer than the installed code, applies available forward migrations, and invalidates **all** restored keys/grants, pending-delivery authorizations, and queued command replies before replacing the database atomically. Existing public-chain data and wallet configuration remain. The command leaves operations paused.
5. Verify SQLite integrity, wallet configuration, cursor boundaries, provider history access, backup destination, and pending/failed delivery state. Restoring an older sent-status database can resend some messages during controlled recovery; no exactly-once promise applies. Retain the restore audit event.
6. Issue fresh individual activation keys. An older backup must never resurrect a consumed key or revoked access grant. The safe default requires reenrollment; preserving prior access instead would require a separately durable verified replay of every later redemption/revocation, which is not assumed by this implementation.
7. Run `php artisan up` if Laravel maintenance is active and, after the recovery checklist, `php artisan ops:resume --force`. Start the single active worker set and let historical replay recover from restored coverage. Verify authorized recipient delivery after fresh enrollment. Reenable only deliberately approved historical notifications.

The original machine and restored instance must never run as independent active writers. A successful local restore test is not an off-host disaster recovery drill. Measure actual RPO/RTO, including user reenrollment and quota-constrained historical catch-up; the design's 5-15-minute RTO and one-minute backup interval remain targets until measured.

## Deployment and rollback discipline

Stop or gracefully drain workers before schema/application changes that affect leased work. Allow SQLite transactions to commit/roll back; interrupted HTTP sends may remain ambiguous and retry after lease expiry. Keep a tested backup and compatible application version before deployment. A code rollback must remain compatible with the current schema and preserve coverage; do not reset cursors to make old code start. A database rollback is a restore and must use the paused, fenced, access-invalidating procedure above.
