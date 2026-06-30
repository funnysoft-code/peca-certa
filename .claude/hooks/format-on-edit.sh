#!/usr/bin/env bash
#
# PostToolUse hook — auto-formats the file that was just written.
#   *.php              → pint --dirty --format=agent
#   *.ts / *.tsx / *.js → vp fmt <file>
#
# Wired in .claude/settings.json under hooks.PostToolUse (matcher: Edit|Write).
# Keeps each file known-good before the next read (~100 ms overhead).

set -u

INPUT=$(cat)

FILE_PATH=$(printf '%s' "$INPUT" | python3 -c '
import json, sys
try:
    data = json.load(sys.stdin)
except Exception:
    sys.exit(0)
inp = data.get("tool_input") or {}
path = inp.get("file_path") or inp.get("notebook_path") or ""
print(path)
' 2>/dev/null)

[[ -z "$FILE_PATH" ]] && exit 0

REPO_ROOT="${CLAUDE_PROJECT_DIR:-$(pwd)}"

case "$FILE_PATH" in
    *.php)
        if [[ -f "$REPO_ROOT/vendor/bin/pint" ]]; then
            cd "$REPO_ROOT" && vendor/bin/pint --dirty --format=agent 2>/dev/null || true
        fi
        ;;
    *.ts|*.tsx|*.js)
        if command -v vp >/dev/null 2>&1; then
            cd "$REPO_ROOT" && vp fmt "$FILE_PATH" 2>/dev/null || true
        fi
        ;;
esac

exit 0
