#!/usr/bin/env bash
#
# Stop hook — CLAUDE.md coverage checker.
#
# 1. Lists directories touched this session (via git status + diff).
# 2. Flags any directory that is missing a CLAUDE.md / AGENTS.md pair.
# 3. Prints a proposed-update REMINDER (does NOT auto-write anything).
# 4. Reminds the user to re-run `php artisan boost:update` for the
#    Boost-generated root CLAUDE.md block.
#
# Output is surfaced in the Stop turn. Human DRI accepts / rejects every
# suggested change — this hook never edits files.

set -u

REPO_ROOT="${CLAUDE_PROJECT_DIR:-$(pwd)}"
cd "$REPO_ROOT" || exit 0

# Collect unique directories that changed (staged + unstaged + untracked).
CHANGED_DIRS=$(git status --porcelain 2>/dev/null \
    | awk '{print $NF}' \
    | xargs -I{} dirname {} 2>/dev/null \
    | sort -u \
    | grep -v '^\.' \
    | grep -v '^$' \
    || true)

if [[ -z "$CHANGED_DIRS" ]]; then
    exit 0
fi

printf '## CLAUDE.md coverage check\n\n'

MISSING=()
while IFS= read -r dir; do
    [[ -z "$dir" ]] && continue
    [[ ! -d "$REPO_ROOT/$dir" ]] && continue
    if [[ ! -f "$REPO_ROOT/$dir/CLAUDE.md" ]]; then
        MISSING+=("$dir")
    fi
done <<< "$CHANGED_DIRS"

if [[ ${#MISSING[@]} -gt 0 ]]; then
    printf '### Directories missing CLAUDE.md (consider adding)\n\n'
    for d in "${MISSING[@]}"; do
        printf '  - %s\n' "$d"
    done
    printf '\n'
    printf '_Add a CLAUDE.md with ## Conventions / ## Patterns / ## Anti-Patterns sections._\n'
    printf "_Then add an AGENTS.md symlink: ln -sf CLAUDE.md AGENTS.md\n\n"
else
    printf '_All changed directories have CLAUDE.md coverage._\n\n'
fi

printf '### Reminder\n\n'
printf '_Review the hand-written .claude/CLAUDE.md for any sections that need updating_\n'
printf '_while context is fresh (Safety Rails, Distributed Context, Workflows)._\n\n'
printf "_If .ai/guidelines/*.blade.php changed, re-run: php artisan boost:update\n"
printf '_to regenerate the root CLAUDE.md Boost block._\n'

exit 0
