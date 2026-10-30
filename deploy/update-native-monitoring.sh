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
Custom code, overrides or unsafe directories inside the app require review.
Shared parent permissions are preserved. Keep the parent trusted for normal
runtime use; this updater pins the app directory for privileged file changes.
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

# A shared hosting parent may permit renames. Hold the actual app directory
# once: every privileged app access below is relative to this kernel CWD.
# The visible-path checks detect a concurrent replacement; they are not the
# security boundary and are never used to reopen a privileged app path.
application_path=/home/www/coinbot
[[ ! -L "$application_path" ]] || fail 'Refusing a symlinked installed application directory.'
application_identity="$(stat -c '%d:%i' -- "$application_path")" \
  || fail 'Cannot identify the existing application directory.'
cd -P -- "$application_path" || fail 'Cannot pin the existing application directory.'
[[ "$(stat -c '%d:%i' -- .)" == "$application_identity" ]] \
  || fail 'The application path changed while it was being pinned; keep workers stopped.'
require_pinned_path() {
  [[ ! -L "$application_path" && "$(stat -c '%d:%i' -- "$application_path" 2>/dev/null)" == "$application_identity" ]] \
    || fail 'The application path changed during the update. Keep workers stopped and review the shared parent; no replacement tree was used for privileged app writes.'
}
require_pinned_path

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
    for ($parent=dirname($path); $parent!=="/" && $parent!=="."; $parent=dirname($parent)) {
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
    return (bool) preg_match("~^(?:app/(?:Blockchain|Monitoring|Reconciliation|Telegram|Wallets|Operations|Console/Commands)/[A-Za-z][A-Za-z0-9_]*\\.php|config/(?:ethereum|operations|blockchain|reconciliation)\\.php|database/migrations/2026_10_27_000(?:100_add_ethereum_early_monitoring|200_create_monitoring_incidents)\\.php|deploy/(?:native-monitoring-config\\.php|coin-bot|systemd/worker|systemd/coin-bot\\.target))$~D",$path);
}

$source=$argv[1]; $staging=$argv[2]; $application=".";
$uid=(int)$argv[3]; $gid=(int)$argv[4];
directoryPath($application,true);
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
    "deploy/systemd/coin-bot-local-backups.conf","deploy/systemd/coin-bot@.service",
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
$helperHashes=array_merge(validHashes($metadata["components"]["deploy/coin-bot"]["baseline_sha256"]),[$expected["deploy/coin-bot"]]);
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
require_pinned_path
require_stopped
require_pinned_path
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
require_pinned_path
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
require_pinned_path
while IFS=$'\t' read -r relative mode; do
  replace_code "$relative" "$mode" "./$relative"
done < "$staging_directory/replacements"
require_pinned_path
replace_code deploy/systemd/coin-bot.target 0644 /etc/systemd/system/coin-bot.target
replace_code deploy/coin-bot 0755 /usr/local/sbin/coin-bot

require_stopped
require_pinned_path
if ! app_run /usr/bin/php8.5 -r '
$path="./bootstrap/cache/config.php";
if (is_link($path) || (is_file($path) && !unlink($path))) {
    throw new RuntimeException("Cannot clear the installed native configuration cache.");
}
' >"$staging_directory/cache.log" 2>&1 \
    || ! app_mutation /usr/bin/php8.5 artisan optimize:clear --no-interaction >>"$staging_directory/cache.log" 2>&1 \
    || ! app_mutation /usr/bin/php8.5 artisan migrate --force --no-interaction >"$staging_directory/migrate.log" 2>&1; then
  fail 'Monitoring migrations or cache clearing failed. Keep all workers stopped; preserve the encrypted backup and investigate before starting.'
fi
require_pinned_path
# Only an unprivileged PHP process loads installed code and dependencies.
# Its bounded stdout goes directly into root-private staging, never through
# an attacker-owned output path that root might follow as a symlink.
/usr/bin/php8.5 -n -r '
if (is_link("./.env") || !is_file("./.env")) { exit(1); }
$contents=file_get_contents("./.env",length:1048577);
if (!is_string($contents)||strlen($contents)>1048576||str_contains($contents,"\0")
    || file_put_contents($argv[1],$contents)!==strlen($contents)) { exit(1); }
' "$staging_directory/original.env" || fail 'Cannot snapshot private configuration; keep workers stopped.'
if ! app_run /usr/bin/timeout 30 /usr/bin/php8.5 ./deploy/native-monitoring-config.php render \
    2>"$staging_directory/render.log" \
    | /usr/bin/php8.5 -n -r '
$contents=stream_get_contents(STDIN,1048577);
if (!is_string($contents)||strlen($contents)>1048576||str_contains($contents,"\0")) { exit(1); }
if (file_put_contents($argv[1],$contents)!==strlen($contents)) { exit(1); }
' "$staging_directory/rendered.env"; then
  fail 'Monitoring configuration rendering failed. Keep workers stopped; secret values were omitted.'
fi
require_pinned_path
/usr/bin/php8.5 -n -r '
function configFailure(): never {
    fwrite(STDERR,"Monitoring configuration was not published; preserve the application key and keep workers stopped.\n");
    exit(1);
}
$before=file_get_contents($argv[1],length:1048577); $rendered=file_get_contents($argv[2],length:1048577);
$uid=(int)$argv[3]; $gid=filegroup("./.env");
if (!is_string($before)||!is_string($rendered)||strlen($before)>1048576||strlen($rendered)>1048576||$gid===false
    || is_link("./.env")||!is_file("./.env")||fileowner("./.env")!==$uid
    || (fileperms("./.env")&0077)!==0||!hash_equals(hash("sha256",$before),hash_file("sha256","./.env"))) { configFailure(); }
function oldNumber(string $contents, string $key, int $default, int $minimum, int $maximum): int {
    $pattern="/^[\t ]*(?:export[\t ]+)?".preg_quote($key,"/")."[\t ]*=(.*)$/m";
    $count=preg_match_all($pattern,$contents,$matches);
    if ($count===0) { return $default; }
    if ($count!==1) { configFailure(); }
    $raw=trim($matches[1][0]," \t\r".chr(34).chr(39));
    $number=filter_var($raw,FILTER_VALIDATE_INT,["options"=>["min_range"=>$minimum,"max_range"=>$maximum]]);
    if ($number===false) { configFailure(); }
    return $number;
}
$tronInterval=oldNumber($before,"TRON_SCAN_MIN_INTERVAL_SECONDS",15,1,60);
$recheck=oldNumber($before,"RECONCILIATION_RECHECK_SECONDS",43200,60,2147483647);
if ($recheck===900) { $recheck=43200; }
$earlyInterval=oldNumber($before,"ETHEREUM_EARLY_INTERVAL_SECONDS",30,1,3600);
if ($earlyInterval===15) { $earlyInterval=30; }
$values=["API_USAGE_ENABLED"=>"true","TRON_SCAN_MIN_INTERVAL_SECONDS"=>(string)$tronInterval,
    "RECONCILIATION_RECHECK_SECONDS"=>(string)$recheck,"ETHEREUM_EARLY_ENABLED"=>"true","ETHEREUM_EARLY_INTERVAL_SECONDS"=>(string)$earlyInterval,"ETHEREUM_EARLY_CONFIRMATIONS"=>"6","ETHEREUM_EARLY_REORG_WINDOW"=>"128",
    "OPERATIONS_ALERT_USER_ID"=>"1","OPERATIONS_ALERT_FAILURE_THRESHOLD"=>"3","OPERATIONS_ALERT_MIN_OUTAGE_SECONDS"=>"30",
    "OPERATIONS_ALERT_CHECK_INTERVAL_SECONDS"=>"15","OPERATIONS_REQUIRED_WORKERS"=>null];
$cleanBefore=$before; $cleanRendered=$rendered;
foreach ($values as $key=>$expected) {
    $pattern="/^[\t ]*(?:export[\t ]+)?".preg_quote($key,"/")."[\t ]*=(.*)(?:\n|$)/m";
    if (preg_match_all($pattern,$rendered,$matches)!==1) { configFailure(); }
    $value=rtrim($matches[1][0],"\r");
    if ($expected!==null && $value!==$expected) { configFailure(); }
    if ($expected===null && (!preg_match("/^[a-z0-9_-]+(?:,[a-z0-9_-]+)*$/D",$value)
        || !in_array("scan-ethereum-early",explode(",",$value),true))) { configFailure(); }
    $cleanBefore=preg_replace($pattern,"",$cleanBefore);
    $cleanRendered=preg_replace($pattern,"",$cleanRendered);
}
if (!is_string($cleanBefore)||!is_string($cleanRendered)
    || rtrim($cleanBefore,"\r\n")!==rtrim($cleanRendered,"\r\n")) { configFailure(); }
// Do not use tempnam: its absolute return value would reopen the shared parent.
$temporary="./.monitoring-config-".bin2hex(random_bytes(16));
$stream=fopen($temporary,"xb");
if ($stream===false) { configFailure(); }
try {
    if (fwrite($stream,$rendered)!==strlen($rendered)||!fflush($stream)||!fsync($stream)
        || !chmod($temporary,0600)||!chown($temporary,$uid)||!chgrp($temporary,$gid)) { configFailure(); }
    fclose($stream); $stream=null;
    if (!rename($temporary,"./.env")) { configFailure(); }
} finally {
    if (is_resource($stream)) { fclose($stream); }
    if (is_file($temporary)) { unlink($temporary); }
}
' "$staging_directory/original.env" "$staging_directory/rendered.env" "$coinbot_uid" "$coinbot_gid"
require_pinned_path
if ! app_mutation /usr/bin/php8.5 artisan optimize:clear --no-interaction >"$staging_directory/cache.log" 2>&1; then
  fail 'Monitoring configuration could not be completed. Keep workers stopped; the application key and encrypted backup must be preserved.'
fi
require_pinned_path
if ! /usr/bin/systemctl daemon-reload; then
  fail 'The monitoring update is installed but systemd could not reload. Keep workers stopped and resolve the manager error before startup.'
fi
require_pinned_path

cat <<'DONE'
Updated early Ethereum monitoring and durable monitoring alerts. No workers were started.
APP_KEY, existing users/wallets, backup policy, dependencies and web services were preserved.

Start when ready:
  sudo coin-bot start
  sudo coin-bot status

Ethereum early messages are preliminary and can be corrected after a reorganization.
Monitoring failures notify the configured internal user after the configured threshold.
DONE
