#!/usr/bin/env bash
set -euo pipefail

# Compatibility entry point for earlier deployment instructions.
installer_directory="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
exec bash "$installer_directory/install-debian.sh" "$@"
