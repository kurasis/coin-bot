# Coin Bot

A notification-only Telegram bot for official USDT transfers on Ethereum and TRON, designed for approximately 100 watched addresses.

Planned stack: **PHP 8.5, Laravel 13, SQLite**. The service observes public blockchain data; it never holds private keys or signs transactions.

See [the architecture and reliability design](docs/architecture.md) for the recommended approach, provider comparison, delivery guarantees, deployment model, and implementation acceptance criteria.

## Project status

Design only. Live read-only mainnet probes verified the core RPC filtering approach on both networks. No Laravel application, production deployment, subscribed wallet list, or Telegram delivery has been implemented or validated yet.

Repository documentation and code use English.
