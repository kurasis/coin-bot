# Debian 12/13: install the complete ZIP

The **full** ZIP already includes `vendor/`, Composer, the application, and its configuration templates. Use a fresh Debian 12 (Bookworm) or Debian 13 (Trixie) server with sudo access and local SSD. The installer handles PHP 8.5, SQLite, permissions, migrations, and systemd. No Docker or server-side Composer installation is needed.

The application is installed at `/home/www/coinbot`; its private configuration is `/home/www/coinbot/.env`. The installer creates `/home/www` if absent and preserves existing parent permissions. Existing `/home` and `/home/www` must permit directory traversal for the application user and must not be symlinks. Extract the archive elsewhere before running the installer.

The installer detects the supported Debian release and uses its matching PHP repository. The earlier `deploy/install-debian13.sh` command remains a compatibility entry point for both releases.

## 1. Install

Upload the full archive as `coin-bot-full.zip` to your home directory, then run:

```bash
sudo apt-get update && sudo apt-get install -y unzip
unzip coin-bot-full.zip
cd coin-bot
sudo bash deploy/install-debian.sh
sudo nano /home/www/coinbot/.env
```

Fill in your Telegram bot token, allowed numeric Telegram user IDs, and the Ethereum/TRON provider URLs/API keys. Keep provider history floors blank until verified; never invent `0`. Settings are labelled in `.env`; see [configuration](configuration.md) for details. Preserve the generated `.env` and `APP_KEY` securely off-host.

Use your one QuickNode endpoint for Ethereum. For TRON, use TronGrid plus a separate [Chainstack TRON Mainnet node](https://console.chainstack.com/). Copy **JSON-RPC API** into `TRON_SECONDARY_RPC_URL`; copy **HTTP API** into `TRON_SECONDARY_NATIVE_URL`, removing a final `/wallet` or `/wallet/` if present. Leave `TRON_SECONDARY_API_KEY` blank. See [the exact settings](configuration.md#one-quicknode-endpoint).

Connect a protected, writable off-host backup mount at `/mnt/coin-bot-backups`, with write access for the `coin-bot` user. This depends on your backup provider; an ordinary local folder does not replace it. Installation completes before this mount exists, but startup requires it.

## 2. Activate your Telegram user

```bash
sudo coin-bot enroll
```

Send `/start <displayed-key>` to your bot in a private chat. In BotFather, `/setjoingroups` → **Disable** prevents adding it to groups. Unknown users receive no reply; an ID on the allowed list still needs its individual key. Repeat `enroll` for each intended user.

## 3. Add wallets and start

Get the internal user ID, then replace the uppercase placeholders:

```bash
sudo coin-bot access:users
sudo coin-bot wallet:add tron TRON_ADDRESS --user=USER_ID
sudo coin-bot wallet:add ethereum ETHEREUM_ADDRESS --user=USER_ID
sudo coin-bot start
sudo coin-bot status
```

For around 100 wallets, use `sudo coin-bot wallet:import --help` to import a CSV/JSON file. Initial backup/reconciliation work must finish before health is complete. Keep independent monitoring and verify off-host recovery before relying on delivery.

## Stop, configure, or update

`sudo coin-bot stop` stops workers. After editing `.env`, run `sudo coin-bot config:clear`, then `sudo coin-bot start`; do this with every worker stopped so all workers use the same access policy. `start` never resumes an operational recovery pause automatically.

The installer is **first-install only** and refuses an existing installation. Do not overwrite a live installation with another ZIP: preserve configuration/data, stop all writers, and follow the [update/recovery discipline](operations.md#deployment-and-rollback-discipline). Source-checkout installation and its update commands remain in the [manual guide](deploy-debian-manual.md).

The bot needs no inbound website port. The optional [Telegram-only website configuration](telegram-only-website.md) is included and remains a separate setup; the installer opens no web listener or firewall port. Actual provider/recipient, backup, and production acceptance remain in the [release checklist](release-checklist.md).
