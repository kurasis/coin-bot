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
| `deploy/install-debian.sh`, `deploy/coin-bot` | Full-archive first installation and short native administration commands |

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

### One QuickNode endpoint

Keep the existing QuickNode Ethereum Mainnet endpoint as `ETHEREUM_SECONDARY_RPC_URL`; Alchemy remains the independent Ethereum primary. A second QuickNode endpoint is unnecessary for the proposed TRON configuration: use TronGrid as primary and create one **TRON Mainnet** node in [Chainstack](https://console.chainstack.com/) as the independent secondary candidate.

Copy the links from the Chainstack node's **Access and credentials** panel:

| Chainstack field | Application setting |
| --- | --- |
| **JSON-RPC API** | `TRON_SECONDARY_RPC_URL`: copy the complete link, including its `/jsonrpc` suffix |
| **HTTP API** | `TRON_SECONDARY_NATIVE_URL`: copy this link and remove a final `/wallet` or `/wallet/` suffix if present |
| No separate header key | `TRON_SECONDARY_API_KEY`: leave empty; authentication is in the links |

The **Solidity HTTP API** and **gRPC endpoint** links are not entered separately: the application appends `/walletsolidity/...` to the native base itself. Do not paste a suffix twice. The [official API reference](https://docs.chainstack.com/reference/tron-api-reference) documents these interfaces on one node endpoint. Treat the complete URLs as credentials and keep them in the private `.env` only.

Chainstack advertises a free TRON developer node on its [TRON page](https://chainstack.com/build-better-with-tron/). Verify the actual account's limits, retained history, and native receipt fallback before production; this is a configuration option, not account certification. Keep history floors blank until verified. No paid provider or additional endpoint has been purchased.

Bot credentials are `TELEGRAM_BOT_TOKEN`; `TELEGRAM_STAGING_CHAT_ID` only records a future permitted test target and does not enroll a user or trigger a send. Real destinations and private wallet settings are persisted per user after key activation. There is intentionally no global recipient ID that overrides tenant routing. Official mainnet token/network identities and private-only delivery policy remain protected application defaults, rather than user-submitted addresses or secrets.

## Private bot access

The bot responds only to users with active individual-key access in their own private chat. Unknown, expired, revoked, disabled, and group-chat users receive no reply. The exception is successful enrollment with a valid single-use key. Invalid activation attempts are silent for users without active access. Old anonymous replies and queued replies whose user/grant is no longer authorized are cancelled before sending.

For a fixed recipient list, set `TELEGRAM_ALLOWED_USER_IDS` to comma-separated numeric Telegram user IDs, for example `123456789,987654321` (replace these placeholders). Whitespace around entries is accepted; usernames, wildcards, empty members, and malformed/nonstring settings deny everyone. The list restricts key activation, command replies, transfer routing, and pending deliveries. Listing an ID does **not** grant access: that person must still activate an individual key. Blank/unset means invitation by key without an additional ID restriction; it does not allow anonymous replies.

Use IDs from trusted operator records, or `message.from.id` in your bot's own updates; never a mutable username. Configuration stays in memory in long-running processes. To change access safely, pause operations, stop **all** bot workers, edit `.env`, clear cached configuration, resume operations, and restart the full worker set using the native/Compose operations procedure. A request already sent to Telegram cannot be recalled. Removing an ID cancels affected pending delivery when processed; adding it again does not replay cancelled history. Revoke the user's grant as well when permanently withdrawing access.

Telegram usernames remain discoverable; this policy controls application replies and notifications. In [BotFather](https://t.me/BotFather), disable joining groups with `/setjoingroups` → **Disable**. Telegram's `/setprivacy` setting controls group message visibility; it does not restrict private-chat access. Application checks ignore groups regardless of that setting.

## Development and future operations

`.env.example` documents the supported bot/access/circuit/health settings with safe empty credential values. Per-provider optional tuning suffixes are listed above rather than duplicated four times in the example. CLI worker interval/range flags are discoverable with `php artisan <command> --help`; they do not start merely because configuration is present.

For optional future Compose deployment, set `COIN_BOT_BACKUP_PATH` to a protected verified off-host backup mount and choose `COIN_BOT_UID/GID`. Keep `APP_KEY` separately and securely backed up; a proxy placeholder cannot replace a raw local encryption key. Production settings are not needed to run offline tests. See [operations](operations.md) for enrollment bootstrap, supervision, health, and fenced restore.

For native deployment, use the [Debian 12/13 guide](deploy-debian.md): the full ZIP includes Composer and locked production dependencies, and its first-install script prepares PHP, the private configuration, SQLite, and systemd without launching workers. Code and the private `.env` live at `/home/www/coinbot`; the database is `/var/lib/coin-bot/database.sqlite`, and encrypted backups use `/mnt/coin-bot-backups`. The worker namespace exposes only this application below its otherwise hidden `/home`. Container-specific `COIN_BOT_UID/GID/BACKUP_PATH` settings are unused. Optional web access uses Nginx source-IP rules; it does not introduce Telegram webhooks.

On shared web hosts, the installer's `--no-packages` option requires an existing PHP 8.5 CLI runtime and performs no APT/dpkg/repository changes. Automatic package provisioning is also blocked when common existing web-stack files/packages are detected. Existing Nginx/FPM configuration, PHP INI files, and CLI alternatives are not rewritten by the runtime-reuse installation path.

Cloud environment draft settings contain installation/start instructions and future integration requirements. Saving those declarations neither supplies values nor launches processes; the development snapshot is separate from production deployment.
