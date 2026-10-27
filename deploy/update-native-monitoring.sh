#!/usr/bin/env bash
set -euo pipefail
umask 0077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin

usage() {
  cat <<'HELP'
Usage: sudo bash deploy/update-native-monitoring.sh

Apply the verified early-Ethereum and monitoring-alert update to an existing
native Coin Bot installation. Extract the complete new ZIP separately first.
Stop all Coin Bot workers and fence any other writers before this command.
An encrypted pre-upgrade backup is required. APP_KEY, users, wallets, backup
policy, dependencies and existing web services are preserved; no workers start.
Custom code, overrides or unsafe code-parent ownership require manual review.
HELP
}
fail() { printf '%s\n' "$1" >&2; exit 1; }
if [[ ${1:-} == --help || ${1:-} == -h ]]; then usage; exit 0; fi
[[ $# == 0 ]] || { usage >&2; exit 64; }
[[ $EUID == 0 ]] || fail 'Run this updater with sudo.'
[[ -x /usr/bin/php8.5 && -x /usr/bin/systemctl ]] || fail 'The installed PHP 8.5 CLI and systemd are required; no packages were changed.'
coinbot_uid="$(id -u coin-bot 2>/dev/null)" || fail 'The existing coin-bot account is required.'
coinbot_gid="$(id -g coin-bot 2>/dev/null)" || fail 'The existing coin-bot group is required.'
[[ $coinbot_uid != 0 && $coinbot_gid != 0 ]] || fail 'The coin-bot account must remain unprivileged.'

require_stopped() {
  local unit_states unit_name load_state active_state remaining
  unit_states="$(/usr/bin/systemctl list-units --all --plain --no-legend --no-pager 'coin-bot@*.service' coin-bot.target)" \
    || fail 'Cannot inspect Coin Bot service state; keep all writers stopped.'
  while read -r unit_name load_state active_state remaining; do
    [[ -n ${unit_name:-} ]] || continue
    case "$active_state" in
      inactive|failed) ;;
      *) fail 'Stop all Coin Bot workers first: sudo coin-bot stop' ;;
    esac
  done <<< "$unit_states"
}
require_stopped

source_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
staging_directory="$(mktemp -d /tmp/coin-bot-monitoring-update.XXXXXXXX)"
trap 'rm -rf -- "$staging_directory"' EXIT

# Verify before booting even the unprivileged application. The privileged
# verifier never loads Laravel, writable bootstrap caches or environment data.
/usr/bin/php8.5 -n -r '
function rejectUpdate(string $message): never {
    fwrite(STDERR, $message."\n");
    exit(1);
}
function directoryPath(string $path, bool $protected = false): void {
    if (is_link($path) || !is_dir($path)) {
        rejectUpdate("A required native directory is missing or unsafe: ".$path);
    }
    if ($protected && (fileowner($path) !== 0 || (fileperms($path) & 0022) !== 0)) {
        rejectUpdate("Native code parents must be root-owned and protected; existing permissions were preserved for review: ".$path);
    }
}
function parents(string $path, bool $protected = false): void {
    for ($parent=dirname($path); $parent!=="/"; $parent=dirname($parent)) {
        directoryPath($parent, $protected);
    }
}
function regularFile(string $path, bool $protected = false): void {
    parents($path, $protected);
    if (is_link($path) || !is_file($path)) {
        rejectUpdate("A required regular native file is missing or unsafe: ".$path);
    }
    if ($protected && (fileowner($path) !== 0 || (fileperms($path) & 0022) !== 0)) {
        rejectUpdate("Native code must remain root-owned and protected: ".$path);
    }
}
function digest(string $path): string {
    $hash=hash_file("sha256", $path);
    if (!is_string($hash)) { rejectUpdate("Cannot verify a native component hash."); }
    return $hash;
}
function jsonFile(string $path, int $maximum): array {
    regularFile($path);
    $raw=file_get_contents($path, length: $maximum+1);
    if (!is_string($raw) || strlen($raw)>$maximum) { rejectUpdate("A release metadata file is unreadable or oversized."); }
    try { $value=json_decode($raw,true,32,JSON_THROW_ON_ERROR); }
    catch (Throwable) { rejectUpdate("A release metadata file is invalid."); }
    if (!is_array($value)) { rejectUpdate("A release metadata object is required."); }
    return $value;
}
function validHashes(mixed $hashes): array {
    if (!is_array($hashes) || !array_is_list($hashes)) { rejectUpdate("Invalid component compatibility metadata."); }
    foreach ($hashes as $hash) {
        if (!is_string($hash) || !preg_match("/^[a-f0-9]{64}$/D",$hash)) { rejectUpdate("Invalid component compatibility hash."); }
    }
    return $hashes;
}
function verifyExisting(string $path, array $hashes): void {
    regularFile($path,true);
    if (!in_array(digest($path),$hashes,true)) {
        rejectUpdate("Unknown or customized native file; preserve it for a reviewed update: ".$path);
    }
}
function allowedComponent(string $path): bool {
    return (bool) preg_match("~^(?:app/(?:Blockchain|Monitoring|Reconciliation|Telegram|Wallets|Operations|Console/Commands)/[A-Za-z][A-Za-z0-9_]*\\.php|config/(?:ethereum|operations)\\.php|database/migrations/2026_10_27_000(?:100_add_ethereum_early_monitoring|200_create_monitoring_incidents)\\.php|deploy/(?:native-monitoring-config\\.php|systemd/worker|systemd/coin-bot\\.target))$~D",$path);
}

$source=$argv[1]; $staging=$argv[2]; $application="/home/www/coinbot";
$uid=(int)$argv[3]; $gid=(int)$argv[4];
directoryPath($application,true); parents($application."/placeholder",true);
regularFile($application."/.env"); regularFile("/var/lib/coin-bot/database.sqlite");
if (fileowner($application."/.env")!==$uid || (fileperms($application."/.env") & 0077)!==0) {
    rejectUpdate("Keep the native .env private and owned by coin-bot; its contents were not changed.");
}
regularFile($application."/vendor/autoload.php",true);
regularFile($application."/tools/composer.phar",true);
$manifest=jsonFile($source."/release-manifest.json",2097152);
if (($manifest["format"]??null)!==1 || !is_array($manifest["files"]??null)
    || !is_string($manifest["git_commit"]??null) || !preg_match("/^[a-f0-9]{40}$/D",$manifest["git_commit"])) {
    rejectUpdate("Use the complete new release ZIP with a valid manifest.");
}
$self="deploy/update-native-monitoring.sh"; $metadataPath="deploy/native-monitoring-components.json";
foreach ([$self,$metadataPath] as $relative) {
    regularFile($source."/".$relative);
    if (!is_string($manifest["files"][$relative]??null)
        || !hash_equals($manifest["files"][$relative],digest($source."/".$relative))) {
        rejectUpdate("The monitoring update package failed integrity verification.");
    }
}
$metadata=jsonFile($source."/".$metadataPath,262144);
if (($metadata["format"]??null)!==1 || !is_array($metadata["components"]??null)
    || $metadata["components"]===[] || count($metadata["components"])>128 || !is_array($metadata["guards"]??null)) {
    rejectUpdate("Invalid monitoring update component metadata.");
}
$guards=["app/Operations/NativeBackupPolicy.php","deploy/native-backup-config.php","deploy/native-preflight.php",
    "deploy/systemd/coin-bot-local-backups.conf","deploy/systemd/coin-bot@.service","deploy/coin-bot",
    "bootstrap/app.php","artisan","composer.lock","composer.json"];
if (array_diff(array_keys($metadata["guards"]),$guards)!==[] || array_diff($guards,array_keys($metadata["guards"]))!==[]) {
    rejectUpdate("The native compatibility guard set is invalid.");
}
$expected=[];
foreach ($metadata["components"] as $relative=>$settings) {
    if (!is_string($relative) || !allowedComponent($relative) || !is_array($settings)
        || !is_bool($settings["required"]??null) || !in_array($settings["mode"]??null,[0644,0755],true)) {
        rejectUpdate("The monitoring update contains an unapproved installation path.");
    }
    $hash=$manifest["files"][$relative]??null;
    if (!is_string($hash) || !preg_match("/^[a-f0-9]{64}$/D",$hash)) { rejectUpdate("A verified monitoring component is missing."); }
    regularFile($source."/".$relative);
    if (!hash_equals($hash,digest($source."/".$relative))) { rejectUpdate("A monitoring component failed integrity verification."); }
    $expected[$relative]=$hash;
    $target=$application."/".$relative; parents($target,true);
    $compatible=validHashes($settings["baseline_sha256"]??null);
    if (file_exists($target)||is_link($target)) { verifyExisting($target,array_merge($compatible,[$hash])); }
    elseif ($settings["required"]) { rejectUpdate("An expected installed monitoring component is missing: ".$target); }
}
foreach ($guards as $relative) {
    $hash=$manifest["files"][$relative]??null;
    if (!is_string($hash)||!preg_match("/^[a-f0-9]{64}$/D",$hash)) { rejectUpdate("A required native compatibility guard is missing."); }
    regularFile($source."/".$relative);
    if (!hash_equals($hash,digest($source."/".$relative))) { rejectUpdate("A native compatibility guard failed integrity verification."); }
    $compatible=validHashes($metadata["guards"][$relative]["baseline_sha256"]??null);
    if (!is_file($application."/".$relative) && str_contains($relative,"NativeBackupPolicy")) {
        rejectUpdate("Apply the native backup-mode update first; this installation has no managed backup policy.");
    }
    verifyExisting($application."/".$relative,array_merge($compatible,[$hash]));
    $expected[$relative]=$hash;
}
foreach (["composer.lock","composer.json"] as $relative) {
    if (!hash_equals(digest($application."/".$relative),$expected[$relative])) {
        rejectUpdate("Dependencies changed; use a reviewed dependency upgrade instead of this native update.");
    }
}
$helperHashes=array_merge(validHashes($metadata["guards"]["deploy/coin-bot"]["baseline_sha256"]),[$expected["deploy/coin-bot"]]);
verifyExisting("/usr/local/sbin/coin-bot",$helperHashes);
verifyExisting("/etc/systemd/system/coin-bot@.service",array_merge(
    validHashes($metadata["guards"]["deploy/systemd/coin-bot@.service"]["baseline_sha256"]),[$expected["deploy/systemd/coin-bot@.service"]]));
$target="deploy/systemd/coin-bot.target";
if (!isset($metadata["components"][$target])) { rejectUpdate("The verified monitoring target is missing."); }
verifyExisting("/etc/systemd/system/coin-bot.target",array_merge(
    validHashes($metadata["components"][$target]["baseline_sha256"]),[$expected[$target]]));
foreach (["/etc/systemd/system/coin-bot@.service.d","/etc/systemd/system/coin-bot.target.d"] as $directory) {
    if (!file_exists($directory)&&!is_link($directory)) { continue; }
    directoryPath($directory,true);
    foreach (glob($directory."/*.conf")?:[] as $override) {
        if ($override!=="/etc/systemd/system/coin-bot@.service.d/local-backups.conf") {
            rejectUpdate("Unknown Coin Bot override; existing settings were preserved for review: ".$override);
        }
        verifyExisting($override,[digest($application."/deploy/systemd/coin-bot-local-backups.conf")]);
    }
}
$replacements=fopen($staging."/replacements","wb");
if ($replacements===false) { rejectUpdate("Cannot stage monitoring replacements."); }
foreach ($metadata["components"] as $relative=>$settings) {
    $copy=$staging."/files/".$relative;
    if (!is_dir(dirname($copy))&&!mkdir(dirname($copy),0700,true)) { rejectUpdate("Cannot create private monitoring staging."); }
    if (!copy($source."/".$relative,$copy)||!hash_equals($expected[$relative],digest($copy))) {
        rejectUpdate("A monitoring component changed during verification.");
    }
    fwrite($replacements,$relative."\t".decoct($settings["mode"])."\n");
}
fclose($replacements);
echo "Verified ".count($metadata["components"])." monitoring components from ".$manifest["git_commit"].".\n";
' "$source_root" "$staging_directory" "$coinbot_uid" "$coinbot_gid"

app_run() {
  runuser -u coin-bot -- env -i PATH=/usr/bin:/bin HOME=/var/lib/coin-bot \
    APP_ENV=production APP_DEBUG=false "$@"
}
app_read_raw() {
  # This missing path is inside a private root-owned directory. Laravel must
  # read the actual .env instead of accepting a stale installed config cache.
  app_run /usr/bin/env APP_CONFIG_CACHE="$staging_directory/uncached.php" "$@"
}
app_mutation() {
  # Readiness already checked the raw configuration. Pin every application
  # mutation to the validated native SQLite file even if an external actor
  # changes environment configuration during this stopped-writer operation.
  app_read_raw /usr/bin/env DB_CONNECTION=sqlite DB_DATABASE=/var/lib/coin-bot/database.sqlite DB_URL=null "$@"
}
cd /home/www/coinbot
require_stopped
backup_mode="$(app_read_raw /usr/bin/php8.5 deploy/native-backup-config.php mode)" \
  || fail 'The installed backup policy cannot be read; no application code or schema was changed.'
case "$backup_mode" in
  offhost)
    mountpoint -q /mnt/coin-bot-backups \
      || fail 'Mount the configured off-host backup destination before updating, or explicitly select encrypted local backups first.'
    ;;
  local) ;;
  *) fail 'The installed backup policy is invalid; no application code or schema was changed.' ;;
esac
if ! app_read_raw /usr/bin/php8.5 deploy/native-preflight.php worker >"$staging_directory/preflight.log" 2>&1 \
    || ! app_read_raw /usr/bin/php8.5 -r '
require "vendor/autoload.php";
$app=require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config("database.default")!=="sqlite"
    || config("database.connections.sqlite.database")!=="/var/lib/coin-bot/database.sqlite"
    || config("database.connections.sqlite.url")!==null) {
    throw new RuntimeException("Use the native local SQLite configuration before this update.");
}
' >"$staging_directory/database.log" 2>&1; then
  fail 'The native database or backup configuration is inconsistent; no application code or schema was changed.'
fi
if ! app_mutation /usr/bin/php8.5 artisan ops:backup --no-interaction >"$staging_directory/backup.log" 2>&1; then
  fail 'The encrypted pre-upgrade backup failed; no application code or schema was changed.'
fi
printf '%s\n' 'Encrypted pre-upgrade backup completed. All writers must remain stopped.'

replace_code() {
  local relative="$1" mode="$2" destination="$3" temporary
  temporary="$(mktemp "$(dirname -- "$destination")/.coin-bot-monitoring-update.XXXXXXXX")"
  if ! install -o root -g root -m "$mode" -- "$staging_directory/files/$relative" "$temporary" \
      || ! mv -f -- "$temporary" "$destination"; then
    rm -f -- "$temporary"
    fail 'A monitoring component could not be installed. Keep workers stopped and review the update; the encrypted backup remains available.'
  fi
}
require_stopped
while IFS=$'\t' read -r relative mode; do
  replace_code "$relative" "$mode" "/home/www/coinbot/$relative"
done < "$staging_directory/replacements"
replace_code deploy/systemd/coin-bot.target 0644 /etc/systemd/system/coin-bot.target

require_stopped
if ! app_run /usr/bin/php8.5 -r '
$path="/home/www/coinbot/bootstrap/cache/config.php";
if (is_link($path) || (is_file($path) && !unlink($path))) {
    throw new RuntimeException("Cannot clear the installed native configuration cache.");
}
' >"$staging_directory/cache.log" 2>&1 \
    || ! app_mutation /usr/bin/php8.5 artisan optimize:clear --no-interaction >>"$staging_directory/cache.log" 2>&1 \
    || ! app_mutation /usr/bin/php8.5 artisan migrate --force --no-interaction >"$staging_directory/migrate.log" 2>&1; then
  fail 'Monitoring migrations or cache clearing failed. Keep all workers stopped; preserve the encrypted backup and investigate before starting.'
fi
if ! /usr/bin/php8.5 /home/www/coinbot/deploy/native-monitoring-config.php enable \
    || ! app_mutation /usr/bin/php8.5 artisan optimize:clear --no-interaction >"$staging_directory/cache.log" 2>&1; then
  fail 'Monitoring configuration could not be completed. Keep workers stopped; the application key and encrypted backup must be preserved.'
fi
if ! /usr/bin/systemctl daemon-reload; then
  fail 'The monitoring update is installed but systemd could not reload. Keep workers stopped and resolve the manager error before startup.'
fi

cat <<'DONE'
Updated early Ethereum monitoring and durable monitoring alerts. No workers were started.
APP_KEY, existing users/wallets, backup policy, dependencies and web services were preserved.

Start when ready:
  sudo coin-bot start
  sudo coin-bot status

Ethereum early messages are preliminary and can be corrected after a reorganization.
Monitoring failures notify the configured internal user after the configured threshold.
DONE
