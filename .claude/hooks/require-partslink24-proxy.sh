#!/usr/bin/env bash
#
# PreToolUse hook (shell tools): block harness commands that hit PartsLink24
# without going through PARTSLINK24_PROXY, and refuse when the proxy is down.
#
# Matcher (settings): Bash|run_terminal_command|Shell
# Exit 2 = deny tool call (same contract as safety-rails / quality-gate hooks).
#
# Bypass (explicit, rare): PARTSLINK24_ALLOW_DIRECT=1
#
set -u

INPUT="$(cat || true)"

if [[ -z "$INPUT" ]]; then
    exit 0
fi

ROOT="${CLAUDE_PROJECT_DIR:-${GROK_PROJECT_DIR:-${CURSOR_PROJECT_DIR:-}}}"
if [[ -z "$ROOT" ]]; then
    ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
fi

COMMAND="$(
    printf '%s' "$INPUT" | php -r '
        $raw = stream_get_contents(STDIN);
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            exit(0);
        }
        $tool = (string) ($data["toolName"] ?? $data["tool_name"] ?? "");
        $input = $data["toolInput"] ?? $data["tool_input"] ?? [];
        if (! is_array($input)) {
            $input = [];
        }
        $cmd = (string) ($input["command"] ?? "");
        $shellTools = ["Bash", "bash", "run_terminal_command", "Shell", "shell"];
        if ($tool !== "" && ! in_array($tool, $shellTools, true)) {
            exit(0);
        }
        echo $cmd;
    ' 2>/dev/null || true
)"

if [[ -z "$COMMAND" ]]; then
    exit 0
fi

# Explicit bypass for emergencies (document why in the shell command comment).
if [[ "${PARTSLINK24_ALLOW_DIRECT:-}" == "1" ]]; then
    exit 0
fi

# Does this command look like it contacts PartsLink24?
# Matches host URLs, curl to partslink, artisan tinker PL24 probes, etc.
if ! printf '%s' "$COMMAND" | grep -Eiq 'partslink24\.com|pl24-appgtw|/auth/ext/api/1\.1/login|PartsLink24Client|suppliers\.partslink24'; then
    exit 0
fi

# Allow pure local/doc/test work that mentions PL24 but does not network:
# - reading files, editing, phpunit/pest with fakes, grep
if printf '%s' "$COMMAND" | grep -Eiq 'php artisan test|pest|phpunit|Http::fake|rg |grep |cat |sed |git |vendor/bin/pint|vendor/bin/phpstan|vendor/bin/rector'; then
    # Still block if the same command line also curls/wgets the host.
    if ! printf '%s' "$COMMAND" | grep -Eiq 'curl |wget |Http::(get|post|put|patch|delete)|->get\(|->post\(|file_get_contents\s*\(\s*['\''"]https?://[^'\''"]*partslink24'; then
        exit 0
    fi
fi

# chrome-pl24.sh and proxy-test are allowed (they enforce proxy themselves).
if printf '%s' "$COMMAND" | grep -Eq 'bin/chrome-pl24\.sh|bin/partslink24-proxy-test\.sh'; then
    exit 0
fi

load_proxy() {
    if [[ -n "${PARTSLINK24_PROXY:-}" ]]; then
        printf '%s' "$PARTSLINK24_PROXY"
        return 0
    fi
    local file="$ROOT/.env"
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

PROXY="$(load_proxy || true)"
if [[ -z "$PROXY" ]]; then
    printf '%s\n' '{"decision":"deny","reason":"PARTSLINK24_PROXY is empty; refuse PartsLink24 network access. Set shop proxy in .env or export PARTSLINK24_ALLOW_DIRECT=1 only if intentional."}'
    exit 2
fi

# Command must either use the proxy explicitly, use PartsLink24Client (app applies proxy),
# or run through artisan which loads config with proxy.
USES_APP_CLIENT=0
if printf '%s' "$COMMAND" | grep -Eiq 'PartsLink24Client|partsLink24\(|suppliers\.partslink24\.proxy|Http::withOptions\(\[.*proxy'; then
    USES_APP_CLIENT=1
fi
USES_CURL_PROXY=0
if printf '%s' "$COMMAND" | grep -Eiq 'curl .*(-x|--proxy) |curl .*-x |--proxy[ =]'; then
    USES_CURL_PROXY=1
fi
USES_CHROME_PROXY=0
if printf '%s' "$COMMAND" | grep -Eiq 'chrome-pl24\.sh|--proxy-server=|--proxy-auth='; then
    USES_CHROME_PROXY=1
fi

if [[ "$USES_APP_CLIENT" -eq 0 && "$USES_CURL_PROXY" -eq 0 && "$USES_CHROME_PROXY" -eq 0 ]]; then
    # Direct curl/wget/open to partslink24 without -x
    if printf '%s' "$COMMAND" | grep -Eiq 'curl |wget |open https?://[^ ]*partslink24|httpx |fetch\('; then
        printf '%s\n' '{"decision":"deny","reason":"PartsLink24 host access without proxy. Use bin/chrome-pl24.sh, curl -x \"$PARTSLINK24_PROXY\", or PartsLink24Client (config proxy)."}'
        exit 2
    fi
    # tinker / php one-liners that hit PL24 should go through the client or explicit proxy
    if printf '%s' "$COMMAND" | grep -Eiq 'partslink24\.com'; then
        printf '%s\n' '{"decision":"deny","reason":"Command mentions partslink24.com without an explicit proxy path. Use PARTSLINK24_PROXY via PartsLink24Client or curl -x."}'
        exit 2
    fi
fi

# Proxy must be healthy (fail closed).
if [[ ! -x "$ROOT/bin/partslink24-proxy-test.sh" ]]; then
    printf '%s\n' '{"decision":"deny","reason":"bin/partslink24-proxy-test.sh missing; refuse PartsLink24 access"}'
    exit 2
fi

if ! (cd "$ROOT" && PARTSLINK24_PROXY="$PROXY" "$ROOT/bin/partslink24-proxy-test.sh" --quiet); then
    printf '%s\n' '{"decision":"deny","reason":"PARTSLINK24_PROXY is down or unreachable; refuse PartsLink24 access until shop 3proxy is healthy (bin/partslink24-proxy-test.sh)."}'
    exit 2
fi

printf '%s\n' '{"decision":"allow"}'
exit 0
