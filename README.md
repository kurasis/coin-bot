# Coin Bot

A notification-only Telegram bot for official USDT transfers on Ethereum and TRON, designed for approximately 100 watched addresses.

Stack: **PHP 8.5, Laravel 13, SQLite**. The service observes public blockchain data; it never holds private keys or signs transactions.

Multiple users have isolated wallets, settings, and notifications. Access requires an individual activation key bound to the recipient's Telegram user ID. Unknown/inactive users receive no replies. An optional `TELEGRAM_ALLOWED_USER_IDS` list further restricts enrollment and all outgoing messages; see [private access settings](docs/configuration.md#private-bot-access).

The operator CLI supports [bulk wallet import from a plain text file](docs/wallet-import.md): one address per line with an explicit chain and internal user ID. Existing CSV/JSON imports remain available.

See [the architecture and reliability design](docs/architecture.md) for the recommended approach and [the development plan](docs/development-plan.md) for stages P01-P13, dependencies, and acceptance criteria.

## Project status

Development code for P01-P12 and its offline verification are complete. The development plan separately records deferred live/operational acceptance and P13. No production deployment or real recipient delivery has been validated yet.

Current scope is development only: production deployment and real recipient delivery are deferred. All integration settings live in `config/` and the credential-free `.env.example`; see [configuration](docs/configuration.md).

For a later server installation, see the [native Debian 12/13 deployment guide](docs/deploy-debian.md) and [Telegram-only website access](docs/telegram-only-website.md).

The complete release ZIP includes production Composer dependencies and Composer itself. On a fresh Debian 12 or Debian 13 server, `sudo bash deploy/install-debian.sh` prepares the application; `sudo coin-bot` provides enrollment, startup, and local administration. To build this package from committed source, use `bin/release` on the build machine.

For an existing web server, use `sudo bash deploy/install-debian.sh --no-packages` with a ready PHP 8.5 CLI runtime. This mode refuses to provision missing packages and leaves existing web/PHP configuration and CLI alternatives untouched. The bot does not need Nginx or PHP-FPM.

The build machine can verify the full package with `bin/verify-native-install ARCHIVE debian:bookworm-slim` or `debian:trixie-slim`. These disposable native-install checks require Docker only on the build/test machine and start no application workers.

Repository documentation and code use English.

## Local development

Requirements: Docker with BuildKit and Bash. The checked-in image pins PHP 8.5 and Composer by digest; no host PHP or Node.js is required.

```bash
bin/setup
bin/verify
```

`bin/setup` builds the runtime, installs `composer.lock`, creates a private local `.env` without overwriting an existing one, initializes the SQLite database, and applies migrations. Re-running it preserves the application key and existing data. Review configuration before running against an existing deployment.

Use `bin/app php artisan list` to discover available commands. `/health` checks the application's SQLite connection; `/up` is Laravel's liveness endpoint. The bot's workers are introduced in later stages.

Build/run helpers forward existing proxy settings and explicitly named application environment variables without displaying their values, and mount the host CA bundle when available, preserving TLS verification in managed environments. PHPUnit forces its isolated SQLite database and test settings even when deployment variables are present. Credentials belong in ignored environment files or secure runtime configuration, never in source control. SQLite databases and generated outputs are ignored.
