#!/usr/bin/env bash
#
# bin/chrome-pl24.sh — launch real Google Chrome with CDP + PartsLink24 shop proxy.
#
# Loads PARTSLINK24_PROXY from the environment or project .env, refuses to start
# if the proxy is missing or down, then starts Chrome with:
#   --remote-debugging-port=9222
#   --proxy-server=http://host:port  (+ proxy-auth if user:pass present)
#   dedicated user-data-dir (~/chrome-pl24-capture by default)
#
# Usage:
#   bin/chrome-pl24.sh
#   bin/chrome-pl24.sh --no-proxy-check   # only if you know what you are doing
#   bin/chrome-pl24.sh --url 'https://www.partslink24.com/portal-ui'
#   bin/chrome-pl24.sh --port 9222
#
# Then: dev-browser --connect http://127.0.0.1:9222
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PORT=9222
USER_DATA_DIR="${HOME}/chrome-pl24-capture"
START_URL="https://www.partslink24.com/pt/index.html?preferredLanguage=pt"
SKIP_PROXY_CHECK=0

while [[ $# -gt 0 ]]; do
    case "$1" in
        --port)
            PORT="${2:?}"
            shift 2
            ;;
        --user-data-dir)
            USER_DATA_DIR="${2:?}"
            shift 2
            ;;
        --url)
            START_URL="${2:?}"
            shift 2
            ;;
        --no-proxy-check)
            SKIP_PROXY_CHECK=1
            shift
            ;;
        -h|--help)
            sed -n '2,25p' "$0" | sed 's/^# \?//'
            exit 0
            ;;
        *)
            echo "Unknown arg: $1" >&2
            exit 2
            ;;
    esac
done

load_proxy_from_env_file() {
    local file="$1"
    [[ -f "$file" ]] || return 1
    local line
    line="$(grep -E '^[[:space:]]*PARTSLINK24_PROXY=' "$file" | tail -n1 || true)"
    [[ -n "$line" ]] || return 1
    line="${line#PARTSLINK24_PROXY=}"
    line="${line#export PARTSLINK24_PROXY=}"
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
    echo "FAIL: PARTSLINK24_PROXY is empty. Set it in .env (shop 3proxy) before launching Chrome." >&2
    exit 2
fi

if [[ "$SKIP_PROXY_CHECK" -eq 0 ]]; then
    if ! "$ROOT/bin/partslink24-proxy-test.sh" --quiet; then
        echo "FAIL: proxy health check failed. Fix 3proxy / port forward / PARTSLINK24_PROXY." >&2
        exit 1
    fi
else
    echo "warn: skipping proxy health check (--no-proxy-check)" >&2
fi

# Parse proxy URL into Chrome flags (Chrome wants --proxy-server without credentials
# and --proxy-auth user:pass separately on some builds; we pass full URL form which
# works on current Chrome for HTTP proxies.)
PROXY_SERVER="$(
    P="$PROXY" php -r '
        $p = getenv("P") ?: "";
        $u = parse_url($p);
        if (! is_array($u) || empty($u["host"])) { exit(1); }
        $scheme = $u["scheme"] ?? "http";
        $port = $u["port"] ?? 80;
        echo $scheme . "://" . $u["host"] . ":" . $port;
    '
)"
PROXY_AUTH="$(
    P="$PROXY" php -r '
        $p = getenv("P") ?: "";
        $u = parse_url($p);
        if (! is_array($u)) { exit(0); }
        $user = $u["user"] ?? "";
        $pass = $u["pass"] ?? "";
        if ($user === "") { exit(0); }
        echo rawurldecode($user) . ":" . rawurldecode($pass);
    '
)"

CHROME=""
for candidate in \
    "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" \
    "/Applications/Chromium.app/Contents/MacOS/Chromium" \
    "google-chrome" \
    "chromium" \
    "chromium-browser"
do
    if [[ -x "$candidate" ]]; then
        CHROME="$candidate"
        break
    fi
    if command -v "$candidate" >/dev/null 2>&1; then
        CHROME="$(command -v "$candidate")"
        break
    fi
done

if [[ -z "$CHROME" ]]; then
    echo "FAIL: Google Chrome not found." >&2
    exit 1
fi

# Refuse if something is already on the debug port without us.
if curl -fsS --max-time 1 "http://127.0.0.1:${PORT}/json/version" >/dev/null 2>&1; then
    echo "note: CDP already listening on :${PORT} — not starting a second Chrome."
    echo "attach with: dev-browser --connect http://127.0.0.1:${PORT}"
    exit 0
fi

mkdir -p "$USER_DATA_DIR"

ARGS=(
    --remote-debugging-port="${PORT}"
    --user-data-dir="${USER_DATA_DIR}"
    --no-first-run
    --no-default-browser-check
    --proxy-server="${PROXY_SERVER}"
)

if [[ -n "$PROXY_AUTH" ]]; then
    # Chromium flag for proxy basic auth (user:pass)
    ARGS+=(--proxy-auth="${PROXY_AUTH}")
fi

ARGS+=("${START_URL}")

echo "Starting Chrome with shop proxy ${PROXY_SERVER} (CDP :${PORT})"
echo "profile: ${USER_DATA_DIR}"
echo "attach:  dev-browser --connect http://127.0.0.1:${PORT}"

# Detach so the terminal returns; logs to /tmp
nohup "$CHROME" "${ARGS[@]}" >/tmp/chrome-pl24.log 2>&1 &
sleep 2

if curl -fsS --max-time 3 "http://127.0.0.1:${PORT}/json/version" >/dev/null 2>&1; then
    echo "OK: Chrome CDP ready on http://127.0.0.1:${PORT}"
    exit 0
fi

echo "FAIL: Chrome did not open CDP on :${PORT}. See /tmp/chrome-pl24.log" >&2
exit 1
