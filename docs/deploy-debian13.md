# Debian 13 deployment quick guide

Use a regular sudo user on Debian 13 (amd64), with Docker access, GitHub read access, local SSD storage, and roughly 2 vCPU / 2 GB RAM. Host PHP, Composer, Node.js, and Nginx are not required. Workers use outbound HTTPS and need no inbound application port. Live release checks remain in the [release checklist](release-checklist.md).

## 1. Install Docker

If Docker Engine, Buildx, and Compose are already installed, ensure Git is available and continue to step 2. Commands follow the [official Debian instructions](https://docs.docker.com/engine/install/debian/).

```bash
sudo apt-get update
sudo apt-get install -y ca-certificates curl git
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/debian/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
sudo tee /etc/apt/sources.list.d/docker.sources >/dev/null <<EOF
Types: deb
URIs: https://download.docker.com/linux/debian
Suites: trixie
Components: stable
Architectures: $(dpkg --print-architecture)
Signed-By: /etc/apt/keyrings/docker.asc
EOF
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo systemctl enable --now docker
sudo usermod -aG docker "$USER"
```

Log out and back in, then confirm `docker version` and `docker compose version` work without sudo.

## 2. Prepare the application

```bash
git clone --branch main --single-branch https://github.com/kurasis/coin-bot.git
cd coin-bot
bin/setup
bin/verify
```

Edit the generated `.env` (keep mode `0600`):

- Set `APP_ENV` to `production`; keep `APP_DEBUG=false` and preserve the generated `APP_KEY` securely off-host. It also decrypts backups.
- Supply `TELEGRAM_BOT_TOKEN`, both Ethereum RPC URLs, both TRON RPC/native URLs, and any required TRON API keys.
- Set each `*_HISTORY_START_BLOCK` to that account's verified retained-history floor. Blank means unknown; do not invent `0` to enable reconciliation.
- Set `COIN_BOT_UID` / `COIN_BOT_GID` to `id -u` / `id -g` of this deployment user.
- Set `COIN_BOT_BACKUP_PATH` to a protected, writable backup mount with verified off-host persistence. Creating an ordinary local directory alone does not satisfy this requirement.

Other settings are documented in [configuration](configuration.md). Once development checks pass, install runtime dependencies:

```bash
bin/app composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
```

## 3. Enroll users and add wallets

From the checkout, define this helper in each shell. Keep the project name unchanged so Compose reuses the same SQLite volume:

```bash
dc() { docker compose --project-name coin-bot -f deploy/compose.yaml "$@"; }
dc up -d telegram-updates telegram-delivery backups
dc exec -T telegram-updates php artisan access:issue
```

Distribute the single-use key securely; the intended user sends `/start <key>` in a private chat with the bot. Replace the uppercase placeholders below with real IDs/addresses. `USER_ID` is the internal ID returned by `access:users`:

```bash
dc exec -T telegram-updates php artisan access:users --telegram-id=TELEGRAM_ID
dc exec -T telegram-updates php artisan wallet:add tron TRON_ADDRESS --user=USER_ID
dc exec -T telegram-updates php artisan wallet:add ethereum ETHEREUM_ADDRESS --user=USER_ID
dc up -d
```

Repeat enrollment/wallet setup for each user. The full stack expects wallets configured for both chains. Adding without `--start-block` starts from the next finalized block; historical import is a separate operation. After deployment, use `dc exec` for administration: `bin/app` uses the separate development database.

## 4. Check operation

```bash
dc ps
dc logs --tail=50 ethereum tron telegram-delivery backups
dc exec -T telegram-updates php artisan ops:health
```

Allow initial backups and finalized-range reconciliation to run. Health should exit zero; inspect reported issues rather than treating a running container as readiness. Add independent host/health monitoring and test off-host restore before relying on delivery. Full recovery instructions are in [operations](operations.md).

## 5. Update or stop

```bash
dc exec -T telegram-updates php artisan ops:backup
dc stop
git pull --ff-only
bin/build
bin/app composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
dc run --rm --no-deps telegram-updates php artisan optimize:clear
dc up -d --force-recreate
dc exec -T telegram-updates php artisan ops:health
```

For an ordinary shutdown use `dc stop`. Preserve `.env`, `APP_KEY`, the named database/storage volumes, and encrypted backups; do not use `dc down -v`. Keep the checkout path/project name stable. A rollback must follow the fenced restore procedure, not reset the database or cursors.
