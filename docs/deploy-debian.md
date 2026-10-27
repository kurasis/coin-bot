# Debian 12/13: install the complete ZIP

The **full** ZIP already includes `vendor/`, Composer, the application, and its configuration templates. Use Debian 12 (Bookworm) or Debian 13 (Trixie) with sudo access and local SSD. The installer prepares the application's SQLite, permissions, migrations, and systemd. On a clean dedicated host it can also provision PHP 8.5; on an existing web host use the ready CLI runtime with `--no-packages`. No Docker or server-side Composer installation is needed.

The application is installed at `/home/www/coinbot`; its private configuration is `/home/www/coinbot/.env`. The installer creates `/home/www` if absent and preserves existing parent permissions. Existing `/home` and `/home/www` must permit directory traversal for the application user and must not be symlinks. Extract the archive elsewhere before running the installer.

The installer detects the supported Debian release and uses its matching PHP repository. The earlier `deploy/install-debian13.sh` command remains a compatibility entry point for both releases.

On a server with existing websites, use `sudo bash deploy/install-debian.sh --no-packages` instead. A complete `/usr/bin/php8.5` CLI runtime must already exist; otherwise installation stops before package/repository/account changes. This mode never runs APT/dpkg or installs/enables PHP extensions. The normal mode also refuses automatic PHP installation when it detects an existing web stack. Custom control-panel paths cannot be detected exhaustively, so use the explicit flag on every shared host.

The bot uses CLI PHP only. Its installer does not write Nginx sites, FPM pools or PHP configuration, change CLI alternatives, or restart web services when reusing the ready runtime. PHP package installation on a clean host can change shared system packages and CLI alternatives; review any separate runtime provisioning on a host with existing sites.

The CLI runtime also needs `allow_url_fopen=On` for bounded streaming HTTPS requests. Clients fail closed when it is disabled; do not change shared PHP-FPM configuration to enable a CLI-only requirement.

## 1. Install

Upload the full archive as `coin-bot-full.zip` to your home directory. The command below reuses an existing PHP 8.5 CLI runtime and leaves system packages untouched. On a clean dedicated host that needs PHP, omit `--no-packages` to allow automatic provisioning.

```bash
sudo apt-get update && sudo apt-get install -y unzip
unzip coin-bot-full.zip
cd coin-bot
sudo bash deploy/install-debian.sh --no-packages
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

For a text list, put one address per line in `wallets-tron.txt`, then import it for the selected internal user:

```bash
sudo install -o coin-bot -g coin-bot -m 0600 wallets-tron.txt /home/www/coinbot/storage/app/wallets-tron.txt
sudo coin-bot wallet:import /home/www/coinbot/storage/app/wallets-tron.txt --chain=tron --user=USER_ID
```

Use `--chain=ethereum` for an Ethereum list. Up to 100 address lines are accepted per command; one invalid record rejects the entire batch, and repeat imports preserve existing settings. See [bulk text import](wallet-import.md) for all options and CSV/JSON compatibility. Initial backup/reconciliation work must finish before health is complete. Keep independent monitoring and verify off-host recovery before relying on delivery.

## Stop, configure, or update

`sudo coin-bot stop` stops workers. After editing `.env`, run `sudo coin-bot config:clear`, then `sudo coin-bot start`; do this with every worker stopped so all workers use the same access policy. `start` never resumes an operational recovery pause automatically.

The installer is **first-install only** and refuses an existing installation. Do not overwrite a live installation with another ZIP: preserve configuration/data, stop all writers, and follow the [update/recovery discipline](operations.md#deployment-and-rollback-discipline). Source-checkout installation and its update commands remain in the [manual guide](deploy-debian-manual.md).

The bot needs no inbound website port. The optional [Telegram-only website configuration](telegram-only-website.md) is included and remains a separate setup; the installer opens no web listener or firewall port. Actual provider/recipient, backup, and production acceptance remain in the [release checklist](release-checklist.md).
