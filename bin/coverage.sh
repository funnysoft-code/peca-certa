#!/usr/bin/env bash
#
# bin/coverage.sh — run Pest with code coverage using whatever driver is available.
#
# Local macOS Herd ships PHP without Xdebug/PCOV loaded, so `XDEBUG_MODE=coverage`
# is a no-op there; coverage must be proxied through `herd coverage`. CI (and any
# environment with a loaded driver) uses `XDEBUG_MODE=coverage` directly.
#
# All arguments are forwarded to Pest after the `--coverage` flag, e.g.:
#   bin/coverage.sh --exactly=100.0 --exclude-testsuite=Browser
#
# NOTE: runs SERIALLY (no --parallel). Under `herd coverage`, ParaTest workers are
# separate PHP processes that would not inherit the coverage-enabled runtime, so a
# parallel run reports incomplete coverage. Serial keeps the number trustworthy on
# every platform.
set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1

# 1. A coverage driver is already loaded (CI with xdebug, or local PCOV) → use it.
if php -m 2>/dev/null | grep -qiE '^(xdebug|pcov)$'; then
    XDEBUG_MODE=coverage exec vendor/bin/pest --coverage "$@"
fi

# 2. No driver loaded — proxy through Laravel Herd's coverage wrapper (macOS).
HERD_BIN="$HOME/Library/Application Support/Herd/bin/herd"
if [[ -x "$HERD_BIN" ]]; then
    exec "$HERD_BIN" coverage vendor/bin/pest --coverage "$@"
fi
if command -v herd >/dev/null 2>&1; then
    exec herd coverage vendor/bin/pest --coverage "$@"
fi

printf '\033[0;31m✘ No code-coverage driver available.\033[0m\n' >&2
printf 'Install Xdebug or PCOV, or run on Laravel Herd (which provides `herd coverage`).\n' >&2
exit 1
