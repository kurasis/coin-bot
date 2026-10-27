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

The new template enables preliminary Ethereum notifications after six subsequent blocks, targeting 1-2 minutes after inclusion under normal conditions. The finalized scanner continues separately. Sustained monitoring outages notify internal application User ID 1 after three unavailable checks spanning at least 30 seconds. After enrollment, confirm that user's mapping/access with `sudo coin-bot access:users`; this setting is not a Telegram ID. See [fast monitoring and alerts](fast-monitoring.md) for the preliminary/reorg policy, quota impact, and configuration.

Use your one QuickNode endpoint for Ethereum. For TRON, use TronGrid plus a separate [Chainstack TRON Mainnet node](https://console.chainstack.com/). Copy **JSON-RPC API** into `TRON_SECONDARY_RPC_URL`; copy **HTTP API** into `TRON_SECONDARY_NATIVE_URL`, removing a final `/wallet` or `/wallet/` if present. Leave `TRON_SECONDARY_API_KEY` blank. See [the exact settings](configuration.md#one-quicknode-endpoint).

Choose backup storage before enrollment. To start without mounting external storage, explicitly enable encrypted local backups:

```bash
sudo coin-bot backup:local
```

This uses `/var/lib/coin-bot/backups`, keeps automatic encrypted backups and freshness checks enabled, and needs no mount. Local copies can be lost together with the database if the server or its disk fails.

For protection against host loss, use the default `offhost` mode: connect a protected, writable external backup mount at `/mnt/coin-bot-backups`, with write access for `coin-bot`. This depends on your backup provider; a normal local directory does not satisfy offhost mode. To switch back later, stop workers, run `sudo coin-bot backup:offhost`, and verify the mount before enrollment/startup. Both mode commands require stopped Coin Bot workers (`sudo coin-bot stop`).

For an existing installation that reports `Mount the protected off-host backup destination...`, extract the **new** full ZIP into a separate directory. From its `coin-bot` directory, apply only the backup-mode support update:

```bash
sudo coin-bot stop
sudo bash deploy/update-native-backups.sh
sudo coin-bot backup:local
```

Then continue with `enroll` below. The narrow updater preserves `.env`, `APP_KEY`, SQLite, and existing application data. Do not rerun the first-install script or extract the ZIP over the installed application.

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

Use `--chain=ethereum` for an Ethereum list. Up to 100 address lines are accepted per command; one invalid record rejects the entire batch, and repeat imports preserve existing settings. See [bulk text import](wallet-import.md) for all options and CSV/JSON compatibility. Initial backup/reconciliation work must finish before health is complete. Keep independent monitoring; local backup health does not establish recovery after host loss.

## Stop, configure, or update

`sudo coin-bot stop` stops workers. After editing `.env`, run `sudo coin-bot config:clear`, then `sudo coin-bot start`; do this with every worker stopped so all workers use the same access policy. `start` never resumes an operational recovery pause automatically.

The installer is **first-install only** and refuses an existing installation. Do not overwrite a live installation with another ZIP: preserve configuration/data, stop all writers, and follow the [update/recovery discipline](operations.md#deployment-and-rollback-discipline). Source-checkout installation and its update commands remain in the [manual guide](deploy-debian-manual.md).

For an existing installation based on `88483c3` / `b908565` that already has the managed backup policy, extract the new full ZIP separately and run this from its `coin-bot` directory:

```bash
sudo coin-bot stop
sudo bash deploy/update-native-monitoring.sh
sudo coin-bot start
sudo coin-bot status
```

The updater verifies compatible code, takes an encrypted pre-upgrade backup, installs monitoring components/migrations, enables early Ethereum and internal-User-ID-1 alerts, adds the ninth worker, and reloads systemd without starting workers. Credentials, `APP_KEY`, wallets, SQLite, local/offhost backup mode, installed dependencies, and existing sites are preserved. If the managed backup policy is missing, apply `deploy/update-native-backups.sh` first as described above. The updater refuses unknown custom code or unsafe parent ownership without changing existing website parent permissions. Follow the [detailed monitoring update notes](fast-monitoring.md#existing-native-installation) if it refuses or fails; keep workers stopped until resolved.

The bot needs no inbound website port. The optional [Telegram-only website configuration](telegram-only-website.md) is included and remains a separate setup; the installer opens no web listener or firewall port. Actual provider/recipient, backup, and production acceptance remain in the [release checklist](release-checklist.md).
