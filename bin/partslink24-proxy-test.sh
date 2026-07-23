#!/usr/bin/env bash
#
# bin/partslink24-proxy-test.sh — verify PARTSLINK24_PROXY is up and egress is the shop IP.
#
# Exit codes:
#   0  proxy OK (and optional PL24 reachability OK)
#   1  misconfigured / proxy down / egress wrong
#   2  usage / env missing
#
# Usage:
#   bin/partslink24-proxy-test.sh
#   bin/partslink24-proxy-test.sh --pl24     # also HEAD partslink24.com via proxy
#   bin/partslink24-proxy-test.sh --quiet    # only exit code + one summary line
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

CHECK_PL24=0
QUIET=0
for arg in "$@"; do
    case "$arg" in
        --pl24) CHECK_PL24=1 ;;
        --quiet|-q) QUIET=1 ;;
        -h|--help)
            sed -n '2,20p' "$0" | sed 's/^# \?//'
            exit 0
            ;;
        *)
            echo "Unknown arg: $arg" >&2
            exit 2
            ;;
    esac
done

log() {
    if [[ "$QUIET" -eq 0 ]]; then
        printf '%s\n' "$*"
    fi
}

# Load PARTSLINK24_PROXY from .env without sourcing the whole file (secrets-safe).
load_proxy_from_env_file() {
    local file="$1"
    [[ -f "$file" ]] || return 1
    local line
    line="$(grep -E '^[[:space:]]*PARTSLINK24_PROXY=' "$file" | tail -n1 || true)"
    [[ -n "$line" ]] || return 1
    line="${line#PARTSLINK24_PROXY=}"
    line="${line#export PARTSLINK24_PROXY=}"
    # Strip optional surrounding quotes
    if [[ "$line" =~ ^\"(.*)\"$ ]]; then
        line="${BASH_REMATCH[1]}"
    elif [[ "$line" =~ ^\'(.*)\'$ ]]; then
        line="${BASH_REMATCH[1]}"
    fi
    printf '%s' "$line"
}

PROXY="${PARTSLINK24_PROXY:-}"
if [[ -z "$PROXY" ]]; then
    PROXY="$(load_proxy_from_env_file "$ROOT/.env" || true)"
fi

if [[ -z "$PROXY" ]]; then
    echo "FAIL: PARTSLINK24_PROXY is empty (set in .env or export it)." >&2
    exit 2
fi

# Never print password. Show host:port only.
PROXY_HOST_PORT="$(
    P="$PROXY" php -r '
        $p = getenv("P") ?: "";
        $u = parse_url($p);
        if (! is_array($u) || empty($u["host"])) {
            fwrite(STDERR, "bad proxy url\n");
            exit(1);
        }
        $port = $u["port"] ?? 80;
        echo $u["host"] . ":" . $port;
    '
)"

log "proxy_target=${PROXY_HOST_PORT}"

if ! command -v curl >/dev/null 2>&1; then
    echo "FAIL: curl not found" >&2
    exit 1
fi

# 1) Egress IP through proxy (prefer plain /ip)
EGRESS_IP="$(
    curl -fsS --max-time 25 -x "$PROXY" "https://ifconfig.me/ip" 2>/dev/null \
        | tr -d '[:space:]' || true
)"

if [[ -z "$EGRESS_IP" ]]; then
    # Fallback HTML scrape of ifconfig.me is messy; try ipify
    EGRESS_IP="$(
        curl -fsS --max-time 25 -x "$PROXY" "https://api.ipify.org" 2>/dev/null \
            | tr -d '[:space:]' || true
    )"
fi

if [[ -z "$EGRESS_IP" || ! "$EGRESS_IP" =~ ^[0-9.]+$ ]]; then
    echo "FAIL: proxy unreachable or did not return a public IPv4 (proxy down / auth / firewall)." >&2
    exit 1
fi

log "via_proxy_ip=${EGRESS_IP}"

# 2) Optional: direct IP for comparison (do not fail if direct fails)
DIRECT_IP="$(
    curl -fsS --max-time 15 "https://api.ipify.org" 2>/dev/null | tr -d '[:space:]' || true
)"
if [[ -n "$DIRECT_IP" ]]; then
    log "direct_ip=${DIRECT_IP}"
    if [[ "$DIRECT_IP" == "$EGRESS_IP" ]]; then
        log "note: direct and proxy egress match (you may already be on shop network)"
    fi
fi

# 3) Optional PL24 reachability via proxy only
if [[ "$CHECK_PL24" -eq 1 ]]; then
    CODE="$(
        curl -sS --max-time 25 -o /dev/null -w '%{http_code}' -x "$PROXY" \
            -I "https://www.partslink24.com/portal-ui" || echo "000"
    )"
    log "partslink24_portal_http=${CODE}"
    if [[ "$CODE" != "200" && "$CODE" != "301" && "$CODE" != "302" && "$CODE" != "303" && "$CODE" != "307" && "$CODE" != "308" ]]; then
        echo "FAIL: partslink24.com via proxy returned HTTP ${CODE}" >&2
        exit 1
    fi
fi

if [[ "$QUIET" -eq 1 ]]; then
    printf 'OK proxy=%s egress=%s\n' "$PROXY_HOST_PORT" "$EGRESS_IP"
else
    printf 'OK: proxy is up; egress %s (target %s)\n' "$EGRESS_IP" "$PROXY_HOST_PORT"
fi

exit 0
