# Debian 12/13 deployment without containers

Use a fresh dedicated Debian 12 (Bookworm) or Debian 13 (Trixie) server, a regular sudo user, local SSD, and roughly 2 vCPU / 2 GB RAM. This guide uses native PHP 8.5 and systemd. The bot uses outbound HTTPS long polling: no website, inbound application port, Node.js, or Redis is needed. Production acceptance remains in the [release checklist](release-checklist.md).

Code lives at `/home/www/coinbot`. Ensure its parent directories allow traversal for `coin-bot`; preserve any existing parent ownership and permissions. The worker unit exposes only this application under its otherwise hidden `/home`.

## 1. Install PHP 8.5 and Composer

Debian 12 ships PHP 8.2; Debian 13 ships PHP 8.4. Add the signed [Sury PHP repository](https://packages.sury.org/php/README.txt), then install the explicit 8.5 packages:

```bash
source /etc/os-release
case "${ID:-}:${VERSION_ID:-}" in
  debian:12) coin_bot_suite=bookworm ;;
  debian:13) coin_bot_suite=trixie ;;
  *) echo 'Use Debian 12 or Debian 13.' >&2; exit 1 ;;
esac
sudo apt-get update
sudo apt-get install -y ca-certificates curl git unzip
keyring_tmp="$(mktemp -d)"
curl -fsSL https://packages.sury.org/debsuryorg-archive-keyring.deb -o "$keyring_tmp/keyring.deb"
sudo dpkg -i "$keyring_tmp/keyring.deb"
rm -rf "$keyring_tmp"
printf 'deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ %s main\n' "$coin_bot_suite" | sudo tee /etc/apt/sources.list.d/php.list >/dev/null
sudo apt-get update
sudo apt-get install -y php8.5-cli php8.5-common php8.5-bcmath php8.5-sqlite3 php8.5-mbstring php8.5-xml php8.5-curl php8.5-zip
php8.5 -r 'foreach (["pdo_sqlite", "sqlite3", "bcmath", "sodium"] as $ext) { if (!extension_loaded($ext)) { fwrite(STDERR, "Missing extension: $ext\n"); exit(1); } } echo PHP_VERSION, "\n";'
```

Install Composer using its [official checksum verification](https://getcomposer.org/doc/faqs/how-to-install-composer-programmatically.md). Run this block in a Bash subshell so a failed download/check stops installation:

```bash
(
set -euo pipefail
composer_tmp="$(mktemp -d)"
trap 'rm -rf "$composer_tmp"' EXIT
curl -fsSL https://getcomposer.org/installer -o "$composer_tmp/installer.php"
expected="$(curl -fsSL https://composer.github.io/installer.sig)"
actual="$(php8.5 -r 'echo hash_file("sha384", $argv[1]);' "$composer_tmp/installer.php")"
test "$actual" = "$expected"
php8.5 "$composer_tmp/installer.php" --install-dir="$composer_tmp" --filename=composer
sudo install -m 0755 "$composer_tmp/composer" /usr/local/bin/composer
)
```

## 2. Prepare code, storage, and configuration

```bash
umask 0022
sudo useradd --system --user-group --home-dir /var/lib/coin-bot --shell /usr/sbin/nologin coin-bot
sudo install -d -o "$USER" -g "$(id -gn)" -m 0755 /home/www/coinbot
git clone --branch main --single-branch https://github.com/kurasis/coin-bot.git /home/www/coinbot
cd /home/www/coinbot
php8.5 /usr/local/bin/composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader
php8.5 /usr/local/bin/composer check-platform-reqs --no-dev
sudo install -d -o coin-bot -g coin-bot -m 0700 /var/lib/coin-bot
sudo chown -R "$USER":coin-bot storage bootstrap/cache
sudo chmod -R u=rwX,g=rwX,o= storage bootstrap/cache
if [ ! -f .env ]; then sudo install -o coin-bot -g coin-bot -m 0600 .env.example .env; fi
sudoedit .env
```

Set these values in `.env`; keep credentials in this private file:

```dotenv
APP_ENV=production
APP_DEBUG=false
DB_DATABASE=/var/lib/coin-bot/database.sqlite
OPERATIONS_BACKUP_DIRECTORY=/mnt/coin-bot-backups
OPERATIONS_REQUIRED_WORKERS=scan-ethereum,scan-tron,telegram-poll,telegram-deliver,backfill,backups,reconcile-ethereum,reconcile-tron
```

Supply `TELEGRAM_BOT_TOKEN`, both Ethereum RPC URLs, both TRON RPC/native URLs, and required TRON API keys. Set each `*_HISTORY_START_BLOCK` only to verified account retention; blank means unknown. Other settings: [configuration](configuration.md).

For access limited to known Telegram IDs, set `TELEGRAM_ALLOWED_USER_IDS` to their comma-separated numeric IDs before enrollment. They still need individual keys. Blank keeps invitation by key; strangers and invalid-key attempts receive no reply in either mode. In BotFather, use `/setjoingroups` → **Disable** to prevent adding the bot to groups.

Mount a protected, writable, independently retained **off-host backup destination** at `/mnt/coin-bot-backups` and configure its persistent mount before continuing. A normal local directory is insufficient. Allow the `coin-bot` user to write there. The service refuses startup if this path is not a mount point; mount presence alone does not prove remote persistence.

```bash
cb() { sudo -u coin-bot /usr/bin/php8.5 /home/www/coinbot/artisan "$@"; }
(
set -euo pipefail
mountpoint -q /mnt/coin-bot-backups
sudo -u coin-bot test -w /mnt/coin-bot-backups
cb package:discover
if ! sudo -u coin-bot grep -qE '^APP_KEY=.+$' /home/www/coinbot/.env; then cb key:generate --force; fi
cb migrate --force
cb ops:backup
)
```

Generate the key **only for the first installation with an empty `APP_KEY`**. Preserve it securely off-host, separately from backups: it is required to decrypt them. Define `cb` again in each new shell. Keep the live database on this host's local disk, never on the backup/network mount.

## 3. Install systemd services and enroll users

```bash
sudo install -m 0644 deploy/systemd/coin-bot@.service deploy/systemd/coin-bot.target /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl start coin-bot@telegram-updates coin-bot@telegram-delivery coin-bot@backups
cb access:issue
```

Give the displayed single-use key securely to its intended user, who sends `/start <key>` in the private bot chat. Replace the uppercase placeholders below; `USER_ID` is the internal ID returned by `access:users`:

```bash
cb access:users --telegram-id=TELEGRAM_ID
cb wallet:add tron TRON_ADDRESS --user=USER_ID
cb wallet:add ethereum ETHEREUM_ADDRESS --user=USER_ID
sudo systemctl enable --now coin-bot.target
cb ops:health
sudo journalctl -u 'coin-bot@*' --since '10 minutes ago'
```

Repeat enrollment/wallet setup for each user. Scanners need configured wallets on both chains for this full service set. Without `--start-block`, monitoring starts at the next finalized block. Allow initial backup/reconciliation work to complete before expecting healthy status. Run `cb ops:health` from independent monitoring and test off-host recovery; systemd process restart alone cannot detect stalled progress.

## 4. Restrict the optional website

Keep inbound web ports closed if a site is unnecessary. If the site is required, follow [Telegram-only HTTPS access](telegram-only-website.md): the supplied Nginx policy and firewall rules allow only Telegram's documented source subnets. They do not add webhook support or change polling. The server has not been configured merely by adding these files to the repository.

## 5. Update or stop

From `/home/www/coinbot`, with `cb` defined:

```bash
(
set -euo pipefail
umask 0022
cb ops:pause
cb ops:backup
sudo systemctl stop coin-bot.target 'coin-bot@*.service'
if systemctl is-active --quiet php8.5-fpm; then sudo systemctl stop php8.5-fpm; fi
git pull --ff-only
php8.5 /usr/local/bin/composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader
php8.5 /usr/local/bin/composer check-platform-reqs --no-dev
cb optimize:clear
cb package:discover
cb migrate --force
sudo install -m 0644 deploy/systemd/coin-bot@.service deploy/systemd/coin-bot.target /etc/systemd/system/
sudo systemctl daemon-reload
if [ -f /etc/php/8.5/fpm/pool.d/coin-bot.conf ]; then sudo systemctl start php8.5-fpm; fi
cb ops:resume --force
sudo systemctl start coin-bot.target
cb ops:health
)
```

The update stops the optional FPM site before changing code/schema; Nginx's access policy remains active. If any update command fails, leave workers stopped and investigate before resuming. For an ordinary shutdown, stop the target and all instances as above. Current workers do not drain HTTP on SIGTERM; a send in flight can have an ambiguous outcome and retry after lease expiry. Preserve `.env`, `APP_KEY`, `/var/lib/coin-bot`, `storage`, and encrypted backups. Never regenerate the key, reset cursors, or restore a raw database over active writers; use the fenced [recovery procedure](operations.md#pause-and-restore).
