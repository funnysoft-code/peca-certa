#!/usr/bin/env bash
#
# Stop hook — progress.md entry scaffolder.
#
# If a commit was made this session (git log HEAD ^HEAD~1 non-empty), drafts a
# Built / Next / Blocked / Decisions entry from the diff + commit message and
# prints it to stdout for human paste into docs/agent/progress.md.
#
# Never writes to progress.md directly. Human DRI accepts / edits / pastes.

set -u

REPO_ROOT="${CLAUDE_PROJECT_DIR:-${CURSOR_PROJECT_DIR:-$(pwd)}}"
cd "$REPO_ROOT" || exit 0

# Check whether a commit exists (HEAD~1 may not exist on first commit).
if ! git rev-parse --verify HEAD >/dev/null 2>&1; then
    exit 0
fi

COMMIT_HASH=$(git log --oneline -1 --format='%H' 2>/dev/null || true)
[[ -z "$COMMIT_HASH" ]] && exit 0

# Only proceed if HEAD~1 exists (i.e., this is not the very first commit).
if ! git rev-parse --verify HEAD~1 >/dev/null 2>&1; then
    exit 0
fi

COMMIT_MSG=$(git log -1 --format='%s' 2>/dev/null || true)
COMMIT_DATE=$(git log -1 --format='%as' 2>/dev/null || true)
SHORT_HASH=$(git log -1 --format='%h' 2>/dev/null || true)

# Summarise changed files (top 20).
CHANGED_FILES=$(git diff --name-only HEAD~1 HEAD 2>/dev/null | head -20 | sed 's/^/  - /' || true)

printf '## progress.md draft — paste into docs/agent/progress.md\n\n'
printf '```\n'
printf '### %s · %s\n\n' "$COMMIT_DATE" "$SHORT_HASH"
printf '**Built**\n'
printf '- %s\n' "$COMMIT_MSG"
if [[ -n "$CHANGED_FILES" ]]; then
    printf '  Changed files:\n'
    printf '%s\n' "$CHANGED_FILES"
fi
printf '\n'
printf '**Next**\n'
printf '- [ ] <!-- TODO: fill in next actions -->\n\n'
printf '**Blocked**\n'
printf '- none\n\n'
printf '**Decisions**\n'
printf '- <!-- TODO: note any significant decisions made this session -->\n'
printf '```\n\n'
printf '_Edit the draft above, then paste it at the top of docs/agent/progress.md (below the ## Format marker)._\n'

exit 0
