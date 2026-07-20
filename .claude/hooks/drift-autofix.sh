#!/usr/bin/env bash
#
# PostToolUse hook — auto-fixes generated artifacts that drift when source
# files change.
#
#   app/Http/Controllers/**  → wayfinder:generate --with-form --quiet
#   app/Data/**              → php artisan typescript:transform  (if installed)
#   app/Enums/**             → php artisan typescript:transform  (if installed)
#
# Wired in .claude/settings.json under hooks.PostToolUse (matcher: Edit|Write).
# Fixes drift at source, not at gate time.

set -u

INPUT=$(cat)

FILE_PATH=$(printf '%s' "$INPUT" | python3 -c '
import json, sys
try:
    data = json.load(sys.stdin)
except Exception:
    sys.exit(0)
# Claude Code: tool_input.file_path; Cursor: file_path / path / file
inp = data.get("tool_input") or {}
path = (
    inp.get("file_path")
    or inp.get("notebook_path")
    or data.get("file_path")
    or data.get("filePath")
    or data.get("path")
    or data.get("file")
    or ""
)
print(path)
' 2>/dev/null)

[[ -z "$FILE_PATH" ]] && exit 0

REPO_ROOT="${CLAUDE_PROJECT_DIR:-${CURSOR_PROJECT_DIR:-$(pwd)}}"
REL="${FILE_PATH#"${REPO_ROOT}"/}"

# Wayfinder drift — regenerate typed route functions when a controller changes.
case "$REL" in
    app/Http/Controllers/*)
        if [[ -f "$REPO_ROOT/artisan" ]]; then
            cd "$REPO_ROOT" && php artisan wayfinder:generate --with-form --quiet 2>/dev/null || true
        fi
        ;;
esac

# TypeScript transformer drift — regenerate generated.d.ts when DTOs/Enums change.
# Guard: only run if the command is registered (opt-in --ts-transformer module).
case "$REL" in
    app/Data/*|app/Enums/*)
        if [[ -f "$REPO_ROOT/artisan" ]]; then
            HAS_CMD=$(cd "$REPO_ROOT" && php artisan list --format=json 2>/dev/null \
                | python3 -c 'import json,sys; d=json.load(sys.stdin); print(any(c["name"]=="typescript:transform" for c in d.get("commands",[])))' 2>/dev/null || echo "False")
            if [[ "$HAS_CMD" == "True" ]]; then
                cd "$REPO_ROOT" && php artisan typescript:transform 2>/dev/null || true
            fi
        fi
        ;;
esac

exit 0
