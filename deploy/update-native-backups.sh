#!/usr/bin/env bash
set -euo pipefail
umask 0077
export PATH=/usr/sbin:/usr/bin:/sbin:/bin

usage() {
  cat <<'HELP'
Usage: sudo bash deploy/update-native-backups.sh

Install only the verified native backup-mode components into an existing
Coin Bot installation. Run from the newly extracted complete release ZIP.
Stop all Coin Bot workers first. Configuration, keys, SQLite, dependencies,
web servers, system packages, and service activation are not changed.
HELP
}
fail() { printf '%s\n' "$1" >&2; exit 1; }
if [[ ${1:-} == --help || ${1:-} == -h ]]; then usage; exit 0; fi
[[ $# == 0 ]] || { usage >&2; exit 64; }
[[ $EUID == 0 ]] || fail 'Run this updater with sudo.'
[[ -x /usr/bin/php8.5 ]] || fail 'The installed PHP 8.5 CLI runtime is required; no packages were changed.'
[[ -x /usr/bin/systemctl ]] || fail 'Native systemd administration is required.'
coinbot_uid="$(id -u coin-bot 2>/dev/null)" || fail 'The existing coin-bot system account is required.'
coinbot_gid="$(id -g coin-bot 2>/dev/null)" || fail 'The existing coin-bot system group is required.'
[[ $coinbot_uid != 0 && $coinbot_gid != 0 ]] || fail 'The coin-bot account must remain unprivileged.'

# Inspect every loaded Coin Bot instance, including independently started units.
# Unknown/transitioning states fail closed; no service is stopped by this updater.
unit_states="$(/usr/bin/systemctl list-units --all --plain --no-legend --no-pager 'coin-bot@*.service' coin-bot.target)" \
  || fail 'Cannot inspect Coin Bot service state; no application files were changed.'
while read -r unit_name load_state active_state remaining; do
  [[ -n ${unit_name:-} ]] || continue
  case "$active_state" in
    inactive|failed) ;;
    *) fail 'Stop all Coin Bot workers first: sudo coin-bot stop' ;;
  esac
done <<< "$unit_states"

source_root="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
staging_directory="$(mktemp -d /tmp/coin-bot-backup-update.XXXXXXXX)"
trap 'rm -rf -- "$staging_directory"' EXIT

# No application bootstrap or environment file is loaded by the privileged
# verifier. Only fixed code paths from the complete package can be installed.
/usr/bin/php8.5 -n -r '
function rejectUpdate(string $message): never {
    fwrite(STDERR, $message."\n");
    exit(1);
}
function requireDirectory(string $path): void {
    if (is_link($path) || ! is_dir($path)) {
        rejectUpdate("A required native directory is missing or unsafe: ".$path);
    }
}
function requireParents(string $path): void {
    $parent = dirname($path);
    while ($parent !== "/") {
        requireDirectory($parent);
        $parent = dirname($parent);
    }
}
function requireRegular(string $path): void {
    requireParents($path);
    if (is_link($path) || ! is_file($path)) {
        rejectUpdate("A required regular native file is missing or unsafe: ".$path);
    }
}
function requireRootCode(string $path): void {
    requireRegular($path);
    $permissions = fileperms($path);
    if (fileowner($path) !== 0 || $permissions === false || ($permissions & 0022) !== 0) {
        rejectUpdate("Native code must remain root-owned and protected: ".$path);
    }
}
function digest(string $path): string {
    $hash = hash_file("sha256", $path);
    if (! is_string($hash)) {
        rejectUpdate("Cannot verify a required file hash.");
    }
    return $hash;
}
function verifyExisting(string $path, array $allowed): void {
    requireRootCode($path);
    if (! in_array(digest($path), $allowed, true)) {
        rejectUpdate("Unknown or customized native file; preserve it and use a reviewed manual update: ".$path);
    }
}

$source = $argv[1];
$staging = $argv[2];
$application = "/home/www/coinbot";
requireDirectory($application);
requireParents($application."/placeholder");
requireRegular($application."/.env");
requireRegular("/var/lib/coin-bot/database.sqlite");
requireRegular($application."/vendor/autoload.php");
requireRegular($application."/artisan");
requireRootCode("/usr/local/sbin/coin-bot");
requireRegular($source."/release-manifest.json");
$raw = file_get_contents($source."/release-manifest.json", length: 2097153);
if (! is_string($raw) || strlen($raw) > 2097152) {
    rejectUpdate("The complete release manifest is unreadable or oversized.");
}
try {
    $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    rejectUpdate("Use the complete release ZIP with a valid release manifest.");
}
if (! is_array($manifest) || ($manifest["format"] ?? null) !== 1
    || ! is_string($manifest["git_commit"] ?? null)
    || ! preg_match("/^[a-f0-9]{40}$/D", $manifest["git_commit"])
    || ! is_array($manifest["files"] ?? null)) {
    rejectUpdate("Use the complete release ZIP with a valid release manifest.");
}

// These are the only code files installed by this narrow compatibility update.
$approved = [
    "app/Operations/NativeBackupPolicy.php" => 0644,
    "deploy/native-backup-config.php" => 0644,
    "deploy/systemd/coin-bot-local-backups.conf" => 0644,
    "config/operations.php" => 0644,
    "app/Operations/HealthReport.php" => 0644,
    "deploy/native-preflight.php" => 0644,
    "deploy/systemd/worker" => 0755,
    "deploy/coin-bot" => 0755,
];
// Compatible deployed components from the 88483c3 security package; these
// unchanged components also shipped in the earlier complete native packages.
$baseline = [
    "deploy/native-preflight.php" => "947fb2857a4e917c9de1f67c1c4531b75b8921939bc5d623dcc5339c86ffcd87",
    "deploy/systemd/worker" => "2ae42053ecea24c68181140a6e51e256e206d4d2594d8e9b20c1d9772158f482",
    "deploy/coin-bot" => "5b1eb36049dc5add3ab65e2f34ef76fb20325061a4ebd0a36b352b01c2f67b2c",
    "config/operations.php" => "7153341c4fb6fad562965caf2c25258fa77c7fb649a77e9d0628c71691eaa87b",
    "app/Operations/HealthReport.php" => "20a533a1cba1afec16f175a4069a913a5a5d272c1847b7a2efcb0b5b1f4a5117",
];
$expected = [];
foreach (array_merge(array_keys($approved), ["deploy/update-native-backups.sh"]) as $relative) {
    $hash = $manifest["files"][$relative] ?? null;
    if (! is_string($hash) || ! preg_match("/^[a-f0-9]{64}$/D", $hash)) {
        rejectUpdate("The complete package is missing a verified backup-mode component.");
    }
    requireRegular($source."/".$relative);
    if (! hash_equals($hash, digest($source."/".$relative))) {
        rejectUpdate("The backup-mode package failed integrity verification.");
    }
    $expected[$relative] = $hash;
}
foreach ($approved as $relative => $mode) {
    $target = $application."/".$relative;
    requireParents($target);
    if (file_exists($target) || is_link($target)) {
        verifyExisting($target, array_filter([$baseline[$relative] ?? null, $expected[$relative]]));
    } elseif (isset($baseline[$relative])) {
        rejectUpdate("An expected installed native component is missing: ".$target);
    }
}
verifyExisting("/usr/local/sbin/coin-bot", [$baseline["deploy/coin-bot"], $expected["deploy/coin-bot"]]);

// The default manager-side mount assertion stays intact. Do not replace any
// installed service or override a custom service with a generic template.
verifyExisting("/etc/systemd/system/coin-bot@.service", ["1aad21583fc96734f8da5d165ded5f94708aee5a59d47ac32c993d177d8d5f99"]);
verifyExisting("/etc/systemd/system/coin-bot.target", ["64474d94ee0b4bf8644d4a137610a783def834f6a39c88231704c2edc21252de"]);
foreach (["/etc/systemd/system/coin-bot@.service.d", "/etc/systemd/system/coin-bot.target.d"] as $directory) {
    if (! file_exists($directory) && ! is_link($directory)) {
        continue;
    }
    requireDirectory($directory);
    foreach (glob($directory."/*.conf") ?: [] as $override) {
        if ($override !== "/etc/systemd/system/coin-bot@.service.d/local-backups.conf") {
            rejectUpdate("Unknown Coin Bot service override; preserve it and use a reviewed manual update: ".$override);
        }
        verifyExisting($override, [$expected["deploy/systemd/coin-bot-local-backups.conf"]]);
    }
}
// No installation path has changed yet. Stage verified bytes privately before
// any replacements, and verify the staged copy against the same manifest.
foreach ($approved as $relative => $mode) {
    $target = $staging."/".$relative;
    if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0700, true)) {
        rejectUpdate("Cannot stage the verified backup-mode components.");
    }
    if (! copy($source."/".$relative, $target) || ! hash_equals($expected[$relative], digest($target))) {
        rejectUpdate("A backup-mode component changed during verification.");
    }
}
echo "Verified eight backup-mode components from ".$manifest["git_commit"].".\n";
' "$source_root" "$staging_directory"

replace_code() {
  local relative="$1" mode="$2" destination="$3" temporary
  temporary="$(mktemp "$(dirname -- "$destination")/.coin-bot-backup-update.XXXXXXXX")"
  if ! install -o root -g root -m "$mode" -- "$staging_directory/$relative" "$temporary"; then
    rm -f -- "$temporary"
    fail 'Cannot stage a native component; keep Coin Bot workers stopped and rerun this updater.'
  fi
  if ! mv -f -- "$temporary" "$destination"; then
    rm -f -- "$temporary"
    fail 'Cannot replace a native component; keep Coin Bot workers stopped and rerun this updater.'
  fi
}
replace_code app/Operations/NativeBackupPolicy.php 0644 /home/www/coinbot/app/Operations/NativeBackupPolicy.php
replace_code deploy/native-backup-config.php 0644 /home/www/coinbot/deploy/native-backup-config.php
replace_code deploy/systemd/coin-bot-local-backups.conf 0644 /home/www/coinbot/deploy/systemd/coin-bot-local-backups.conf
replace_code config/operations.php 0644 /home/www/coinbot/config/operations.php
replace_code app/Operations/HealthReport.php 0644 /home/www/coinbot/app/Operations/HealthReport.php
replace_code deploy/native-preflight.php 0644 /home/www/coinbot/deploy/native-preflight.php
replace_code deploy/systemd/worker 0755 /home/www/coinbot/deploy/systemd/worker
replace_code deploy/coin-bot 0755 /home/www/coinbot/deploy/coin-bot
replace_code deploy/coin-bot 0755 /usr/local/sbin/coin-bot

cat <<'DONE'
Updated the native backup-mode components. No workers or web services were started.
Configuration, APP_KEY, SQLite, dependencies, and the strict base service are unchanged.

To use encrypted local backups without mounting external storage:
  sudo coin-bot backup:local
  sudo coin-bot enroll

Local backups are stored on this server and do not protect against losing its disk.
DONE
