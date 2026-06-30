#!/usr/bin/env bash
#
# PreToolUse hook — blocks Edit/Write/NotebookEdit against safety-rail paths.
# Reads the tool input JSON from stdin (python3) and exits 2 to deny the call
# when the target file_path matches a rule.
#
# Wired in .claude/settings.json under hooks.PreToolUse.
# Matcher: Edit|Write|NotebookEdit

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
REL="${FILE_PATH#"${REPO_ROOT}"/}"

deny() {
    printf 'BLOCKED: safety-rail %s — %s\n' "$1" "$REL" >&2
    printf 'See .claude/CLAUDE.md "Safety Rails" for the full rule. Ask the human before touching this path.\n' >&2
    exit 2
}

case "$REL" in
    # Secrets / environment state (allow .env.example edits)
    .env|.env.*)
        [[ "$REL" == ".env.example" ]] && exit 0
        deny "env" ;;

    # Dependency locks (only tools should regenerate these)
    composer.lock)  deny "lockfile" ;;
    bun.lock)       deny "lockfile" ;;

    # Generated / vendored trees — never hand-edit
    vendor/*)           deny "vendor" ;;
    node_modules/*)     deny "node_modules" ;;
    bootstrap/cache/*)  deny "bootstrap-cache" ;;

    # IDE-helper stubs (auto-generated via `composer ide`)
    _ide_helper*.php|.phpstorm.meta.php)  deny "ide-helper" ;;

    # Production deploy script
    bin/deploy.sh)  deny "deploy-script" ;;
esac

# database/migrations/*.php — only block if already applied (Ran).
# New migration files (not yet in migrate:status) are allowed.
case "$REL" in
    database/migrations/*.php)
        BASENAME=$(basename "$REL" .php)
        if command -v php >/dev/null 2>&1 && [[ -f "$REPO_ROOT/artisan" ]]; then
            STATUS=$(cd "$REPO_ROOT" && php artisan migrate:status --no-ansi 2>/dev/null \
                | grep -F "$BASENAME" | grep -F 'Ran' || true)
            if [[ -n "$STATUS" ]]; then
                deny "applied-migration"
            fi
        fi
        ;;
esac

exit 0
