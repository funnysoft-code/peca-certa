#!/usr/bin/env bash
#
# SessionStart hook — surfaces docs/agent/progress.md head + recent commits +
# working-tree status. Output is appended to the session context per the
# Claude Code hooks contract.
#
# Wired in .claude/settings.json under hooks.SessionStart.

set -u

REPO_ROOT="${CLAUDE_PROJECT_DIR:-$(pwd)}"
cd "$REPO_ROOT" || exit 0

PROGRESS="$REPO_ROOT/docs/agent/progress.md"

printf '## Session priming\n\n'

if [[ -f "$PROGRESS" ]]; then
    printf '### docs/agent/progress.md (latest entries)\n\n'
    # Print everything below the "## Format" marker if it exists, capped to
    # 80 lines. Falls back to the head of the file when the marker is absent.
    awk '/^## Format/{found=1; next} found' "$PROGRESS" \
        | sed '/^$/N;/^\n$/D' \
        | head -n 80
    printf '\n'
else
    printf '_progress.md missing — create docs/agent/progress.md before next commit._\n\n'
fi

printf '### git log --oneline -20\n\n'
git log --oneline -20 2>/dev/null || printf '_no git history available_\n'
printf '\n'

if git diff --quiet HEAD 2>/dev/null && git diff --cached --quiet 2>/dev/null; then
    printf '### Working tree: clean\n\n'
else
    CHANGED=$(git status --porcelain 2>/dev/null | wc -l | tr -d ' ')
    printf '### Working tree: %s changed file(s)\n\n' "$CHANGED"
    git status --short 2>/dev/null | head -n 20
    printf '\n'
fi

printf "_Read .claude/CLAUDE.md before any code change. Run /qg before claiming done._\n"

exit 0
