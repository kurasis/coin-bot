# API pacing, usage estimates, and a free Ethereum secondary

This release applies the operator-approved resource controls without changing the transfer ledger or its SQLite schema. Initial independent reconciliation, receipt verification, network identity, cursor boundaries and mandatory provider cooldowns remain enforced.

## Runtime changes

- Automatic Alchemy profile: all Alchemy endpoints on this host share a default pacing group at 200 throughput CU/s. `eth_chainId` costs 5 throughput CU, headers/receipts 20, and `eth_getLogs` 60. Requests wait outside the shared file lock and outside application SQLite transactions. This pacing smooths this bot's requests; it does not control other applications or hosts using the same account. It does not enforce a monthly allowance.
- Automatic free-tier query spans: Alchemy defaults to 10 blocks, QuickNode to 5, so recursive splitting happens locally before oversized parent requests are sent. Explicit `_MAX_BLOCK_RANGE` overrides are preserved.
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

## Permanent free candidate: Infura

Official documentation checked on 2026-10-27 lists a recurring Free tier with **3,000,000 credits/day**, **500 credits/s**, and full archive data. This is not a time-limited QuickNode-style trial. Published terms may change, and the dashboard for the actual new account must be checked. No free service uptime guarantee or guaranteed future pricing is implied. Infura is a separate provider from the existing Alchemy primary; brand separation alone does not certify independent upstream infrastructure.

At the configured 12-hour repeat policy and roughly 225 new Ethereum finalized ranges/day, a sparse-wallet example with about 450 total range checks/day needs roughly 0.30-0.40 million credits/day for two log queries, two boundaries and amortized heads. Receipt-heavy wallets, large address sets, outages and backfills add usage. This is a model, not acceptance of the user's not-yet-created account.

1. Create a Free account at https://developer.metamask.io/ and an Ethereum Mainnet API key. Verify the plan allowance and absence of a trial-expiration condition in Billing. Keep the key in the server's private `.env`; never send it in chat or commit it.
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

- https://docs.infura.io/get-started/pricing/
- https://docs.infura.io/get-started/pricing/credit-cost/
- https://www.infura.io/pricing (the comparison table agrees with 3M/day and 500/s; its FAQ contains conflicting higher figures, so the conservative documented allowance is used)
- https://www.alchemy.com/docs/reference/compute-unit-costs
- https://www.quicknode.com/api-credits

The new user's Infura account and production deployment have not been accessed from this development environment. Switching requires their own key and a successful read-only account probe. No provider subscription was purchased or endpoint silently replaced.
