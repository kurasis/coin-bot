#!/usr/bin/env bash
set -euo pipefail
umask 0077

usage() {
  cat <<'USAGE'
Usage: sudo bash deploy/install-debian.sh [--no-packages]

Fresh native Debian 12 (Bookworm) or Debian 13 (Trixie) installation from the full Coin Bot release ZIP.
Installs PHP 8.5, the bundled application, its private configuration/database,
and systemd definitions. No workers or web services are started.
Existing installations and account/state collisions are refused.
Use --no-packages on a server with existing sites: PHP 8.5 CLI and its required
extensions must already be available; packages and repositories are untouched.
USAGE
}

fail() { printf 'Installation stopped: %s\n' "$*" >&2; exit 1; }

php_runtime_ready() {
  [[ -x /usr/bin/php8.5 ]] && /usr/bin/php8.5 -r 'if (PHP_MAJOR_VERSION !== 8 || PHP_MINOR_VERSION !== 5) { exit(1); } foreach (["bcmath", "pdo_sqlite", "sqlite3", "sodium", "mbstring", "dom", "curl", "zip"] as $extension) { if (!extension_loaded($extension)) { exit(1); } }' >/dev/null 2>&1
}

web_stack_present() {
  if command -v nginx >/dev/null 2>&1 || command -v php-fpm >/dev/null 2>&1; then return 0; fi
  local pattern package_status
  for pattern in \
    '/etc/nginx' '/usr/local/etc/nginx' '/usr/local/nginx' \
    '/etc/php/*/fpm' '/etc/php-fpm*' '/usr/local/etc/php-fpm*' \
    '/usr/local/php*/etc/php-fpm*' '/opt/plesk/php/*/etc/php-fpm*' \
    '/usr/sbin/php-fpm*' '/usr/local/sbin/php-fpm*' '/usr/local/php*/sbin/php-fpm*' \
    '/etc/apache2' '/usr/local/apache*' \
    '/etc/systemd/system/nginx*.service' '/etc/systemd/system/php*fpm*.service' \
    '/lib/systemd/system/nginx*.service' '/lib/systemd/system/php*fpm*.service' \
    '/usr/lib/systemd/system/nginx*.service' '/usr/lib/systemd/system/php*fpm*.service' \
    '/etc/init.d/nginx' '/etc/init.d/php*fpm*'; do
    if compgen -G "$pattern" >/dev/null; then return 0; fi
  done
  package_status="$(dpkg-query -W -f='${db:Status-Status} ${binary:Package}\n' 'nginx*' 'php*-fpm' 'apache2*' 'libapache2-mod-php*' 2>/dev/null || true)"
  grep -Eq '^(installed|unpacked|half-configured|half-installed|triggers-awaited|triggers-pending) ' <<<"$package_status"
}

no_packages=false
while (( $# > 0 )); do
  case "$1" in
    --help) usage; exit 0 ;;
    --no-packages) no_packages=true ;;
    *) usage >&2; exit 64 ;;
  esac
  shift
done
[[ $EUID -eq 0 ]] || fail 'Run this installer with sudo.'

# The installer supports the native Debian host, not a different distribution.
# shellcheck source=/dev/null
source /etc/os-release
[[ "${ID:-}" == debian ]] || fail 'Debian 12 (Bookworm) or Debian 13 (Trixie) is required.'
case "${VERSION_ID:-}" in
  12) debian_codename=bookworm ;;
  13) debian_codename=trixie ;;
  *) fail 'Debian 12 (Bookworm) or Debian 13 (Trixie) is required.' ;;
esac
[[ "${VERSION_CODENAME:-$debian_codename}" == "$debian_codename" ]] || fail 'Debian version and codename do not match; no packages were changed.'

source_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
application_dir=/home/www/coinbot
state_dir=/var/lib/coin-bot
[[ "$source_root" != "$application_dir" ]] || fail 'Extract the full ZIP outside /home/www/coinbot, then run the installer there.'
for parent in /home /home/www; do
  [[ ! -L "$parent" ]] || fail "$parent must not be a symlink. Existing files were left unchanged."
  if [[ -e "$parent" ]]; then
    [[ -d "$parent" ]] || fail "$parent must be a directory."
    parent_mode="$(stat -c '%a' -- "$parent")"
    (( (8#$parent_mode & 1) != 0 )) || fail "$parent must permit application-user directory traversal. Existing parent permissions were left unchanged."
  fi
done
[[ ! -e "$application_dir" && ! -L "$application_dir" ]] || fail '/home/www/coinbot already exists. Existing code and secrets were left unchanged.'
[[ ! -L "$state_dir" ]] || fail '/var/lib/coin-bot must not be a symlink.'
if [[ -e "$state_dir" ]]; then
  [[ -d "$state_dir" ]] || fail '/var/lib/coin-bot is not a directory.'
  [[ -z "$(find "$state_dir" -mindepth 1 -maxdepth 1 -print -quit)" ]] || fail '/var/lib/coin-bot already contains state. Use the update/recovery procedure.'
fi
if getent passwd coin-bot >/dev/null || getent group coin-bot >/dev/null; then
  fail 'The coin-bot account or group already exists. Use the update/recovery procedure.'
fi
for installed_path in /usr/local/sbin/coin-bot /etc/systemd/system/coin-bot@.service /etc/systemd/system/coin-bot.target; do
  [[ ! -e "$installed_path" && ! -L "$installed_path" ]] || fail 'Existing Coin Bot administration or service files were found. Use the update/recovery procedure.'
done
for required in release-manifest.json vendor/autoload.php tools/composer.phar artisan .env.example deploy/install-debian.sh deploy/coin-bot deploy/native-preflight.php deploy/systemd/worker deploy/systemd/coin-bot@.service deploy/systemd/coin-bot.target; do
  [[ -f "$source_root/$required" && ! -L "$source_root/$required" ]] || fail 'Use the full release ZIP containing vendor, Composer, and deployment tools.'
done
command -v systemctl >/dev/null || fail 'A systemd Debian host is required.'

php_ready=false
if php_runtime_ready; then
  php_ready=true
elif [[ "$no_packages" == true ]]; then
  fail 'PHP 8.5 CLI and required extensions are missing; no system packages were changed.'
elif web_stack_present; then
  fail 'Existing web stack detected; automatic PHP package installation is disabled. Prepare PHP 8.5 CLI in a reviewed maintenance window, then use --no-packages.'
fi

installer_tmp="$(mktemp -d /tmp/coin-bot-install.XXXXXXXX)"
cleanup() {
  local status=$?
  rm -rf -- "$installer_tmp"
  if [[ $status -ne 0 ]]; then
    printf '%s\n' 'Installation did not finish. No workers were started; preserve any generated .env and database before recovery.' >&2
  fi
  exit "$status"
}
trap cleanup EXIT

# Debian 12/13 ship PHP 8.2/8.4. Use PHP 8.5 from the matching signed Sury repository.
# Source: https://packages.sury.org/php/README.txt
if [[ "$php_ready" == false ]]; then
  apt-get update
  DEBIAN_FRONTEND=noninteractive apt-get install -y ca-certificates curl
  curl --fail --silent --show-error --location --proto '=https' --proto-redir '=https' \
    https://packages.sury.org/debsuryorg-archive-keyring.deb -o "$installer_tmp/keyring.deb"
  dpkg -i "$installer_tmp/keyring.deb"
  printf 'deb [signed-by=/usr/share/keyrings/debsuryorg-archive-keyring.gpg] https://packages.sury.org/php/ %s main\n' "$debian_codename" \
    > /etc/apt/sources.list.d/coin-bot-php.list
  apt-get update
  DEBIAN_FRONTEND=noninteractive apt-get install -y php8.5-cli php8.5-common php8.5-bcmath php8.5-sqlite3 php8.5-mbstring php8.5-xml php8.5-curl php8.5-zip
fi
php_runtime_ready || fail 'PHP 8.5 CLI or required extensions are unavailable.'

# Verify and copy only manifest members. Extra files beside an extracted release,
# including a locally edited .env, never enter the installed application.
/usr/bin/php8.5 -n -r '
$root = $argv[1];
$manifest = json_decode(file_get_contents($root."/release-manifest.json"), true, flags: JSON_THROW_ON_ERROR);
if (($manifest["format"] ?? null) !== 1
    || !is_string($manifest["git_commit"] ?? null)
    || !preg_match("/^[a-f0-9]{40}$/D", $manifest["git_commit"])
    || !is_int($manifest["source_date_epoch"] ?? null)
    || $manifest["source_date_epoch"] < 0
    || !is_array($manifest["files"] ?? null) || $manifest["files"] === []) {
    throw new RuntimeException("Invalid full-release manifest.");
}
$required = ["vendor/autoload.php", "tools/composer.phar", "artisan", ".env.example", "deploy/install-debian.sh", "deploy/coin-bot", "deploy/native-preflight.php", "deploy/systemd/worker", "deploy/systemd/coin-bot@.service", "deploy/systemd/coin-bot.target"];
foreach ($required as $path) {
    if (!array_key_exists($path, $manifest["files"])) {
        throw new RuntimeException("Required release member is absent.");
    }
}
$list = fopen($argv[2], "wb");
foreach ($manifest["files"] as $path => $digest) {
    if (!is_string($path) || $path === "" || str_contains($path, "\\") || str_contains($path, "\0")
        || str_starts_with($path, "/") || !is_string($digest) || !preg_match("/^[a-f0-9]{64}$/D", $digest)) {
        throw new RuntimeException("Unsafe release member.");
    }
    $current = $root;
    foreach (explode("/", $path) as $part) {
        if ($part === "" || $part === "." || $part === ".." || $part === ".git"
            || (str_starts_with($part, ".env") && $part !== ".env.example")) {
            throw new RuntimeException("Unsafe release member.");
        }
        $current .= "/".$part;
        if (is_link($current)) {
            throw new RuntimeException("Release symlinks are not permitted.");
        }
    }
    if (!is_file($current) || !hash_equals($digest, hash_file("sha256", $current))) {
        throw new RuntimeException("A release file failed its integrity check.");
    }
    fwrite($list, $path."\0");
}
fwrite($list, "release-manifest.json\0");
fclose($list);
' "$source_root" "$installer_tmp/members"

useradd --system --user-group --home-dir "$state_dir" --shell /usr/sbin/nologin coin-bot
if [[ ! -d /home/www ]]; then install -d -o root -g root -m 0755 /home/www; fi
for parent in /home /home/www; do
  runuser -u coin-bot -- /usr/bin/test -x "$parent" || fail "$parent does not allow the application user to traverse it; check its existing ACL."
done
install -d -o root -g root -m 0755 "$application_dir"
tar -C "$source_root" --null --files-from="$installer_tmp/members" -cf - \
  | tar -C "$application_dir" --no-same-owner --same-permissions -xf -
chown -R root:root "$application_dir"
chmod -R u=rwX,go=rX "$application_dir"
install -d -o coin-bot -g coin-bot -m 0700 "$state_dir"
chown -R coin-bot:coin-bot "$application_dir/storage" "$application_dir/bootstrap/cache"
chmod -R u=rwX,go= "$application_dir/storage" "$application_dir/bootstrap/cache"
install -o coin-bot -g coin-bot -m 0600 "$application_dir/.env.example" "$application_dir/.env"

app_run() {
  runuser -u coin-bot -- env -i PATH=/usr/bin:/bin HOME="$state_dir" \
    APP_ENV=production APP_DEBUG=false "$@"
}

app_run /usr/bin/php8.5 -r '
$path = $argv[1];
$contents = file_get_contents($path);
$values = [
    "APP_ENV" => "production", "APP_DEBUG" => "false",
    "DB_DATABASE" => "/var/lib/coin-bot/database.sqlite",
    "OPERATIONS_BACKUP_DIRECTORY" => "/mnt/coin-bot-backups",
    "OPERATIONS_REQUIRED_WORKERS" => "scan-ethereum,scan-tron,telegram-poll,telegram-deliver,backfill,backups,reconcile-ethereum,reconcile-tron",
];
foreach ($values as $key => $value) {
    $contents = preg_replace("/^".preg_quote($key, "/")."=.*$/m", $key."=".$value, $contents, count: $count);
    if ($count !== 1) { throw new RuntimeException("The release environment template is invalid."); }
}
if (file_put_contents($path, $contents) === false) { throw new RuntimeException("Cannot write application configuration."); }
' "$application_dir/.env"

app_run /usr/bin/php8.5 "$application_dir/tools/composer.phar" --working-dir="$application_dir" --no-plugins check-platform-reqs --no-dev
app_run /usr/bin/php8.5 "$application_dir/artisan" package:discover --no-interaction
if ! grep -qE '^APP_KEY=.+$' "$application_dir/.env"; then
  app_run /usr/bin/php8.5 "$application_dir/artisan" key:generate --force --no-interaction
fi
app_run /usr/bin/php8.5 "$application_dir/artisan" migrate --force --no-interaction

install -o root -g root -m 0755 "$application_dir/deploy/coin-bot" /usr/local/sbin/coin-bot
install -o root -g root -m 0644 "$application_dir/deploy/systemd/coin-bot@.service" "$application_dir/deploy/systemd/coin-bot.target" /etc/systemd/system/
systemctl daemon-reload

cat <<'DONE'
Installed Coin Bot in /home/www/coinbot. No workers or web services were started.

Next:
  1. Edit /home/www/coinbot/.env with the bot token, provider URLs, and allowed IDs.
  2. Preserve .env (including APP_KEY) securely off-host, separately from backups.
  3. Run sudo coin-bot backup:local for encrypted local copies without a mount,
     or mount the protected off-host destination at /mnt/coin-bot-backups.
  4. Run: sudo coin-bot enroll

The full ZIP already includes production dependencies and Composer.
DONE
