# API pacing, usage estimates, and a free Ethereum secondary

This release applies the operator-approved resource controls without changing the transfer ledger or its SQLite schema. Initial independent reconciliation, receipt verification, network identity, cursor boundaries and mandatory provider cooldowns remain enforced.

## Runtime changes

- Automatic Alchemy profile: all Alchemy endpoints on this host share a default pacing group at 200 throughput CU/s. `eth_chainId` costs 5 throughput CU, headers/receipts 20, and `eth_getLogs` 60. Requests wait outside the shared file lock and outside application SQLite transactions. This pacing smooths this bot's requests; it does not control other applications or hosts using the same account. It does not enforce a monthly allowance.
- Automatic free-tier query spans: Alchemy defaults to 10 blocks, QuickNode to 5, so recursive splitting happens locally before oversized parent requests are sent. Explicit `_MAX_BLOCK_RANGE` overrides are preserved.
- Known query spans now use full-sized consecutive chunks instead of binary subdivision: 100 blocks with a 10-block limit require 20 log calls across both directions instead of 32. The final partial chunk is included. Unexpected payload/result limits still split, and an incomplete chunk prevents coverage from advancing.
- Idle finalized scans reuse the current finalized-head response when it is the exact stored boundary. Early idle scans read the stored boundary once, without an additional confirmation-height lookup. These changes retain idle boundary mismatch detection and fresh before/after early checks around event collection; no headers are cached between cycles or providers.
- Ordinary productive TRON scans start no more often than every 15 seconds, including time spent on RPC work. Idle scans retain their command interval. Full-range historical catch-up retains its faster one-second cadence. Added notification delay is at most the remaining configured cadence under normal operation; RPC or provider delays can add more.
- Already verified ranges become eligible for repeat reconciliation after 12 hours instead of the old default 15 minutes. The first independent pass is unchanged. This trades slower detection of late indexer omissions for lower API usage. The existing 24-hour overlap still bounds repeat candidates; an old range may age out before another repeat if work is delayed.
- The native updater enables local accounting, adds the TRON cadence if missing, and converts missing/old-default `RECONCILIATION_RECHECK_SECONDS=900` to `43200`. Other explicitly configured repeat intervals and valid custom TRON cadence values are preserved. Existing providers, credentials, history declarations and queues are preserved.

## Inspect local usage

```bash
sudo coin-bot status
sudo coin-bot ops:api-usage
sudo coin-bot ops:api-usage --date=2026-10-27
```

Days are UTC, matching typical provider billing counters. Reports show admitted HTTP attempts (including retries), estimated billing units, method counts, transport failures, pacing wait and a configurable daily target. `status` omits method detail. Targets are advisory, not hard daily caps. A request cancelled immediately after admission can be counted without reaching the provider. Provider-specific error billing, other apps and other hosts can differ from these estimates. The provider dashboard remains the billing source of truth.

Accounting starts with the first request after this update; earlier usage cannot be reconstructed. Local files under `OPERATIONS_STATE_DIRECTORY/api` retain 35 UTC days and are not part of encrypted application-database backups. They contain fixed method labels and totals, never endpoints, headers, API keys, wallet addresses, transaction hashes or raw responses. Pacing groups use a stable lock file and atomically replaced private JSON. Corrupt/unavailable local control state blocks outgoing requests and is reported as unavailable; it is never silently reset to bypass accounting. Preserve evidence when troubleshooting it.

Automatic billing estimates:

| Profile | Estimates for methods used by this bot | Default rate | Daily target |
| --- | --- | --- | --- |
| Alchemy | chain ID 0 CU; headers/receipts 20; logs 60 | 200 throughput CU/s | 800,000 CU |
| QuickNode | Ethereum 20 credits/call; TRON 30 | No additional local rate cap | 250,000 credits |
| Infura | chain ID 5 credits; headers/receipts 80; logs 255 | 200 credits/s | 2,400,000 credits |
| Requests | One per HTTP attempt | No additional local rate cap | TRON: 75,000 requests |

URL hostname suffixes select Alchemy, QuickNode and Infura profiles automatically. Custom proxy hostnames use the generic requests profile unless overridden. All endpoints of an automatically detected vendor share one default group; use distinct groups only for genuinely separate account allowances. A report for roles sharing a group repeats the same account aggregate: do not add those rows together.

Optional settings use the existing provider prefix, for example:

```dotenv
ETHEREUM_PRIMARY_USAGE_PROFILE=alchemy
ETHEREUM_PRIMARY_RATE_GROUP=alchemy
ETHEREUM_PRIMARY_RATE_PER_SECOND=200
ETHEREUM_PRIMARY_DAILY_TARGET=800000
TRON_SCAN_MIN_INTERVAL_SECONDS=15
RECONCILIATION_RECHECK_SECONDS=43200
API_USAGE_ENABLED=true
```

Profiles: `auto`, `alchemy`, `quicknode`, `infura`, `requests`. Rate units are throughput CU/s for Alchemy, credits/s for Infura, and requests/s for QuickNode/generic requests. A zero rate disables pacing for that group; a zero daily target disables target comparison. All consumers sharing an account group must use consistent pacing settings. Change configuration while workers are stopped, clear cached configuration and restart. Disabling `API_USAGE_ENABLED` also disables local pacing.

## Investigate excessive usage

Inspect effective settings without spending provider quota or revealing credentials:

```bash
sudo coin-bot chain:diagnose ethereum --offline
sudo coin-bot chain:diagnose tron --offline
```

Each provider now reports `query_limits.max_block_range` and `max_topic_addresses`. `resource_controls` reports the configured repeat-reconciliation delay, minimum productive scan cadence, and Ethereum early polling interval. The minimum scan cadence is a floor, not the command's idle interval; an explicit early `--interval` overrides its environment setting. These are current settings, not the configuration that produced a past usage report.

Check these causes before changing accounts or budgets:

- An Infura secondary can retain a small explicit range from QuickNode. For a verified Infura account, the setup below uses `ETHEREUM_SECONDARY_MAX_BLOCK_RANGE=1000`. A retained value of 5 can multiply log calls on the same coverage. The updater preserves explicit provider limits; only increase one after checking that account's supported range. Oversized result/payload handling remains active.
- Verify `RECONCILIATION_RECHECK_SECONDS=43200`. A shorter custom setting repeatedly queries already verified ranges. The first independent verification remains mandatory; reducing its frequency or dropping it is not an optimization in this release.
- Preliminary Ethereum observations are an additional scan, including when no watched transfer occurs. Six confirmations do not eliminate the cost of polling, log filters and canonical boundary checks.

The approved lower-cost cadence is now the default, configurable through the private environment file:

```dotenv
ETHEREUM_EARLY_INTERVAL_SECONDS=30
```

The default is 30 seconds. Changing 15 to 30 can add up to 15 seconds of configured polling wait; RPC time and scheduling add their own delay. Six confirmations, receipt checks, fresh reorg anchors, independent reconciliation, and one-second full-range catch-up remain unchanged. The native updater sets missing values and the old default of 15 to 30; other valid custom intervals are preserved. Stop workers, edit the private environment, run `sudo coin-bot config:clear`, and restart workers using the normal procedure. Avoid intervals exceeding worker-heartbeat thresholds without reviewing monitoring settings too.

`tests/Feature/ApiBudgetSimulationTest.php` compares a fixed one-hour schedule with one address batch, 12-second Ethereum blocks, finalized advances of 32 blocks every 384 seconds, and no transfers. Finalized polling is fixed at 15 seconds for this model. Measured primary Alchemy usage:

| Implementation | Early interval | CU per model hour |
| --- | --- | ---: |
| Previous release (`c37ee0d`) | 15 seconds | 66,900 |
| Current | 15 seconds | 62,280 |
| Current | 30 seconds | 35,880 |

The code-only reduction in this scenario is 6.9%; the 30-second cadence reduces a further 42.4%. Known-span splitting has a separate 37.5% log-call reduction for a 100-block/10-block-limit query. These percentages describe different workloads and must not be added. The model excludes RPC latency, reconciliation, receipts, retries, backfills, reorg recovery and other consumers; it is not a production-day forecast or a guarantee of fitting the daily target. Compare fresh UTC usage deltas after deployment, including recovery reserve, against the actual provider dashboard.

## Permanent free candidate: Infura

Official documentation checked on 2026-10-27 lists a recurring Free tier with **3,000,000 credits/day**, **500 credits/s**, and full archive data. This is not a time-limited QuickNode-style trial. Published terms may change, and the dashboard for the actual new account must be checked. No free service uptime guarantee or guaranteed future pricing is implied. Infura is a separate provider from the existing Alchemy primary; brand separation alone does not certify independent upstream infrastructure.

At the configured 12-hour repeat policy and roughly 225 new Ethereum finalized ranges/day, a sparse-wallet example with about 450 total range checks/day needs roughly 0.30-0.40 million credits/day for two log queries, two boundaries and amortized heads. Receipt-heavy wallets, large address sets, outages and backfills add usage. This is a model, not acceptance of the user's not-yet-created account.

1. Open the dedicated Infura dashboard at https://app.infura.io/ (registration: https://app.infura.io/register), as linked by the [official Infura onboarding guide](https://docs.infura.io/get-started/infura/). Verify your email, select **My First Key** or **Configure**, then open **All Endpoints** and choose Ethereum Mainnet. The general MetaMask Developer portal can open Embedded Wallets/Web3Auth instead: **Authentication**, **Smart Accounts**, **Wallet Services**, or `sapphire_devnet` identify that separate product. Its Devnet/Mainnet upgrade is not the Ethereum RPC setup. Do not infer Infura payment-card eligibility from that screen; check the actual Infura account. Verify the plan allowance and absence of a trial-expiration condition in Billing. Keep the key in the server's private `.env`; never send it in chat or commit it.
2. Install this package first using the normal native monitoring updater. It keeps QuickNode working until the new account is ready.
3. Stop workers and edit `/home/www/coinbot/.env`. Replace `ETHEREUM_SECONDARY_RPC_URL` with the new Infura Ethereum Mainnet HTTPS URL. Set the following non-secret settings. Clear the old secondary history declaration until the new account passes its own history probe:

```dotenv
ETHEREUM_SECONDARY_USAGE_PROFILE=infura
ETHEREUM_SECONDARY_RATE_GROUP=infura
ETHEREUM_SECONDARY_RATE_PER_SECOND=200
ETHEREUM_SECONDARY_MAX_BLOCK_RANGE=1000
ETHEREUM_SECONDARY_HISTORY_START_BLOCK=
```

4. Clear config and verify the known historical official USDT transfer on the actual new account:

```bash
sudo coin-bot config:clear
sudo coin-bot chain:diagnose ethereum --provider=secondary
sudo coin-bot chain:probe ethereum --provider=secondary --from=26278475 --to=26278475 --address=0x71330642530889d2f1efbf3bcd1c623926cb26e0
```

Require `status=ok`, positive incoming matches and `receipts_checked > 0`; empty responses do not establish history support. Also check account retention terms. Old cooldown state is preserved and may need to expire. A failure needs investigation or restoration of the previous known working configuration before normal startup.

5. After successful account verification, set `ETHEREUM_SECONDARY_HISTORY_START_BLOCK=26278475` (or another actually verified lower boundary covering all pending work), clear config, run `chain:reconcile ethereum --once`, and start the existing worker set. Do not reset cursors or delete coverage to switch vendors. Inspect `status` and `ops:api-usage` for the new Infura group.

## Sources

- https://docs.infura.io/get-started/infura/
- https://docs.infura.io/get-started/pricing/
- https://docs.infura.io/get-started/pricing/credit-cost/
- https://www.infura.io/pricing (the comparison table agrees with 3M/day and 500/s; its FAQ contains conflicting higher figures, so the conservative documented allowance is used)
- https://www.alchemy.com/docs/reference/compute-unit-costs
- https://www.quicknode.com/api-credits

The new user's Infura account and production deployment have not been accessed from this development environment. Switching requires their own key and a successful read-only account probe. No provider subscription was purchased or endpoint silently replaced.
