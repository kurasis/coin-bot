#!/usr/bin/env bash
# Native Coin Bot update, pinned to source revision 2a6ea84.
set -euo pipefail
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
umask 077

staging_directory=''
workers_stopped=0
stage=preflight

cleanup() {
    local result=$?
    if (( result == 0 )); then
        if [[ -n "$staging_directory" ]]; then rm -rf -- "$staging_directory"; fi
    else
        printf 'Update stopped during %s.\n' "$stage" >&2
        if [[ "$stage" == startup ]]; then
            printf 'Worker startup did not complete. Inspect service state before retrying.\n' >&2
        elif (( workers_stopped )); then
            printf 'Workers were not restarted. Resolve the update failure before starting them.\n' >&2
        fi
        if [[ -n "$staging_directory" ]]; then
            printf 'Update files retained at: %s\n' "$staging_directory" >&2
        fi
    fi
}

main() {
    [[ $EUID == 0 ]] || { printf 'Run this update with sudo.\n' >&2; exit 1; }
    [[ -x /usr/bin/php8.5 && -x /usr/local/sbin/coin-bot ]] \
        || { printf 'An existing native Coin Bot installation with PHP 8.5 is required.\n' >&2; exit 1; }
    /usr/bin/php8.5 -r 'exit(class_exists(PharData::class) ? 0 : 1);'
    for program in curl sha256sum mktemp bash; do
        command -v "$program" >/dev/null || { printf 'Missing required command: %s\n' "$program" >&2; exit 1; }
    done
    trap cleanup EXIT
    cd /
    staging_directory=$(mktemp -d /tmp/coin-bot-update.XXXXXXXX)
    stage=download
    printf 'Downloading verified Coin Bot release 2a6ea84...\n'
    curl --fail --silent --show-error --location --retry 3 \
        --connect-timeout 15 --max-time 300 --proto '=https' --proto-redir '=https' \
        'https://raw.githubusercontent.com/kurasis/coin-bot/b41a864847dad2f21a165fdf89413963557bf474/coin-bot-full-2a6ea84.zip' \
        --output "$staging_directory/update.zip"
    stage=checksum
    printf '%s  %s\n' '1533a24e5ad3fb3afe58074398ca142bc581d12e46e7ae17e2c89a572959f7fa' \
        "$staging_directory/update.zip" | sha256sum --check --status
    stage=extraction
    /usr/bin/php8.5 -r '(new PharData($argv[1]))->extractTo($argv[2]);' \
        "$staging_directory/update.zip" "$staging_directory"
    [[ -f "$staging_directory/coin-bot/deploy/update-native-monitoring.sh" \
        && -f "$staging_directory/coin-bot/release-manifest.json" ]]
    stage=shutdown
    printf 'Stopping Coin Bot workers...\n'
    workers_stopped=1
    /usr/local/sbin/coin-bot stop
    stage=update
    printf 'Applying the native update and creating its encrypted backup...\n'
    bash "$staging_directory/coin-bot/deploy/update-native-monitoring.sh"
    stage=startup
    /usr/local/sbin/coin-bot start
    workers_stopped=0
    stage=health
    /usr/local/sbin/coin-bot status
    printf 'Coin Bot update 2a6ea84 completed. Default early Ethereum interval: 30 seconds.\n'
}

main "$@"
