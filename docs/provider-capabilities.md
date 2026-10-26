# Provider capability gates

The application has no default production RPC URLs and does not silently fall back to public research endpoints. Configure both independent vendors per chain before production. Secrets and credential-bearing URLs must be provided securely.

| Candidate account | Implementation tested | Actual account status | Outstanding certification |
| --- | --- | --- | --- |
| Ethereum / Alchemy primary | Ethereum JSON-RPC tested on independent public endpoint | Not configured | Identity/finality, 100-address OR, receipts, recent/history ranges, truncation behavior, retention, quota |
| Ethereum / QuickNode secondary | Common adapter covered by HTTP fixtures | Not configured | All account capability checks and independent history reconciliation |
| TRON / TronGrid primary | Public TronGrid JSON-RPC and native solidity probes passed | API key not configured | Actual plan limits, history retention, recovery quota |
| TRON / QuickNode secondary | TRON JSON-RPC/native adapter covered by HTTP fixtures | Not configured | Native solidity endpoints, numeric ranges, receipts, topic batching, retention, quota |

## Observed read-only behavior

On 2026-10-26 the implemented Ethereum client verified mainnet identity, read a finalized header at height 26,278,637, found one filtered USDT event, and checked its successful full receipt (33 logs). The implemented TRON client verified mainnet identity, read native solidified height 87,455,558, found one filtered event, and verified its successful native solidity receipt.

The fixture suite also exercises 100-address OR filters, malformed responses, redirect rejection, bounded retry/rate-limit handling, and native receipt completeness. These are not a substitute for repeating checks on the configured accounts. Default timeouts are bounded and configurable; hosted-proxy TLS handshakes required more than five seconds in this development environment.

Live tests must be read-only, use known expected events and empty ranges, and keep URLs/API headers out of reports. Record each account's maximum block span, topic count, result ceiling, historical depth, billed method weights, steady-state allowance, and 24-hour backlog recovery allowance before closing P04.

## Explicit retention floor

Each actual provider supports `<PREFIX>_HISTORY_START_BLOCK`. Blank/unset means uncertified; reconciliation waits without advancing or certifying an empty range. Configure only a minimum height actually verified on that account. A range below either provider's floor remains pending. Public development scanner probes do not certify an unknown production account.
