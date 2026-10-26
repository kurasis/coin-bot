# Configuration

Current scope: development only. `bin/setup` and `bin/verify` do not start notification daemons, enroll real recipients, purchase accounts, or deploy production. Tests force an isolated SQLite connection even when an external database environment variable is present, and block unexpected outbound HTTP.

Copy `.env.example` through `bin/setup` and supply any future credentials through ignored `.env` files or secure runtime environment settings. `bin/app` forwards only named application-setting prefixes and proxy/trust settings to Docker; it never displays their values. Runtime environment variables override `.env`. After changing cached Laravel configuration, clear/rebuild it deliberately before restarting workers.

| File | Responsibility |
| --- | --- |
| `config/database.php` | SQLite location, foreign keys, busy timeout, WAL/FULL durability, IMMEDIATE transactions |
| `config/access.php` | Activation-key lifetime, attempt limits, and throttle interval |
| `config/blockchain.php` | Independent RPC URLs/native TRON URLs, authentication, per-provider timeouts/limits/retries, verified historical coverage |
| `config/telegram.php` | Bot credential binding, optional future staging ID, polling/request/lease timing, rate and attempt limits |
| `config/reconciliation.php` | Persistent circuit cooldowns, repeat-scan interval, and per-chain overlap windows |
| `config/operations.php` | Backup/state locations, retention, lag/health thresholds, and required workers |
| `deploy/compose.yaml` | Optional future process supervision and local persistent volumes; not automatically launched |
| `deploy/systemd/` | Native Debian worker supervision, fixed worker commands, and restricted writable paths |
| `deploy/nginx/`, `deploy/php-fpm/` | Optional Telegram-source-only HTTPS website and private application FPM pool |

## Provider settings

Use prefixes `ETHEREUM_PRIMARY`, `ETHEREUM_SECONDARY`, `TRON_PRIMARY`, and `TRON_SECONDARY`. Each supports these suffixes:

| Suffix | Default / meaning |
| --- | --- |
| `_RPC_URL` | Unset; use the actual account's HTTPS JSON-RPC URL |
| `_NATIVE_URL` | TRON only, unset; use the actual account's native HTTP base URL |
| `_API_KEY` | TRON only, optional provider-specific `TRON-PRO-API-KEY` header |
| `_TIMEOUT` / `_CONNECT_TIMEOUT` | 20 / 15 seconds |
| `_ATTEMPTS` | 3 bounded transport attempts |
| `_BACKOFF_MS` / `_MAX_BACKOFF_MS` | 125 / 1,000 milliseconds for short inline retries; never shorten upstream cooldowns |
| `_MAX_BLOCK_RANGE` | 5,000; must reflect actual hosted-plan limits |
| `_MAX_TOPIC_ADDRESSES` | 100; split batches as required by the account |
| `_MAX_RESULTS` | 1,000; reaching the ceiling triggers splitting rather than assuming completeness |
| `_HISTORY_START_BLOCK` | Unknown when blank/unset; set only after verifying retained history on that account |

There is no default public production provider or fabricated API key. Independent reconciliation waits when retention is unknown or a range predates the declared floor. A value of zero asserts verified full history; it must not be copied from fixtures as a way to bypass certification. See [provider capabilities](provider-capabilities.md).

Bot credentials are `TELEGRAM_BOT_TOKEN`; `TELEGRAM_STAGING_CHAT_ID` only records a future permitted test target and does not enroll a user or trigger a send. Real destinations and private wallet settings are persisted per user after key activation. There is intentionally no global recipient ID that overrides tenant routing. Official mainnet token/network identities and private-only delivery policy remain protected application defaults, rather than user-submitted addresses or secrets.

## Development and future operations

`.env.example` documents the supported bot/access/circuit/health settings with safe empty credential values. Per-provider optional tuning suffixes are listed above rather than duplicated four times in the example. CLI worker interval/range flags are discoverable with `php artisan <command> --help`; they do not start merely because configuration is present.

For optional future Compose deployment, set `COIN_BOT_BACKUP_PATH` to a protected verified off-host backup mount and choose `COIN_BOT_UID/GID`. Keep `APP_KEY` separately and securely backed up; a proxy placeholder cannot replace a raw local encryption key. Production settings are not needed to run offline tests. See [operations](operations.md) for enrollment bootstrap, supervision, health, and fenced restore.

For native deployment, use the [Debian 13 guide](deploy-debian13.md): the database is `/var/lib/coin-bot/database.sqlite`, encrypted backups use `/mnt/coin-bot-backups`, and Laravel reads the private `.env` directly. Container-specific `COIN_BOT_UID/GID/BACKUP_PATH` settings are unused. Optional web access uses Nginx source-IP rules; it does not introduce Telegram webhooks.

Cloud environment draft settings contain installation/start instructions and future integration requirements. Saving those declarations neither supplies values nor launches processes; the development snapshot is separate from production deployment.
