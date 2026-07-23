#!/usr/bin/env bash
#
# PreToolUse hook: block harness shell `git push` until bin/quality-gate.sh passes.
# Fail-open only when the tool is not a push (or stdin is empty).
# Explicit deny on quality-gate failure so agents cannot ship red CI.
#
set -u

INPUT="$(cat || true)"

if [[ -z "$INPUT" ]]; then
    exit 0
fi

# Extract shell command from Claude / Grok / Cursor-shaped payloads.
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
        // Only gate shell tools.
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

# Match git push (any remote/branch), including wrappers like `git push origin main`.
# Do not match `git push --dry-run` only as a hard block — still require the gate
# before a real push; dry-run is rare for agents and safe to gate too.
if ! printf '%s' "$COMMAND" | grep -Eq '(^|[[:space:];|&])git[[:space:]]+push([[:space:]]|$)'; then
    exit 0
fi

ROOT="${CLAUDE_PROJECT_DIR:-${GROK_PROJECT_DIR:-}}"
if [[ -z "$ROOT" ]]; then
    ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
fi

GATE="$ROOT/bin/quality-gate.sh"
if [[ ! -x "$GATE" ]]; then
    printf '{"decision":"deny","reason":"bin/quality-gate.sh missing or not executable; refuse git push"}\n'
    exit 2
fi

if ! (cd "$ROOT" && "$GATE"); then
    printf '{"decision":"deny","reason":"quality gate failed; run bin/quality-gate.sh, fix failures, then push"}\n'
    exit 2
fi

printf '{"decision":"allow"}\n'
exit 0
