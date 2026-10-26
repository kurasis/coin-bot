# Coin Bot

A notification-only Telegram bot for official USDT transfers on Ethereum and TRON, designed for approximately 100 watched addresses.

Stack: **PHP 8.5, Laravel 13, SQLite**. The service observes public blockchain data; it never holds private keys or signs transactions.

Multiple users have isolated wallets, settings, and notifications. Access requires an individual activation key bound to the recipient's Telegram user ID.

See [the architecture and reliability design](docs/architecture.md) for the recommended approach and [the development plan](docs/development-plan.md) for stages P01-P13, dependencies, and acceptance criteria.

## Project status

Implementation is in progress through stages P01-P13. Completed checks and remaining live gates are recorded in the development plan. No production deployment or real recipient delivery has been validated yet.

Repository documentation and code use English.

## Local development

Requirements: Docker with BuildKit and Bash. The checked-in image pins PHP 8.5 and Composer by digest; no host PHP or Node.js is required.

```bash
bin/setup
bin/app php artisan test
bin/app composer validate --strict
bin/app vendor/bin/pint --test
```

`bin/setup` builds the runtime, installs `composer.lock`, creates a private local `.env` without overwriting an existing one, initializes the SQLite database, and applies migrations. Re-running it preserves the application key and existing data. Review configuration before running against an existing deployment.

Use `bin/app php artisan list` to discover available commands. `/health` checks the application's SQLite connection; `/up` is Laravel's liveness endpoint. The bot's workers are introduced in later stages.

Build/run helpers forward existing proxy settings and mount the host CA bundle when available, preserving TLS verification in managed environments. Credentials belong in ignored environment files or secure runtime configuration, never in source control. SQLite databases and generated outputs are ignored.
