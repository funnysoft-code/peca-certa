#!/usr/bin/env bash
#
# bin/worktree-remove — tear down an isolated workspace AND its git worktree.
#
# Cross-platform:
#   macOS  → unlinks the Herd site + drops the workspace DB + removes worktree.
#   Linux  → removes the dev-<slug>.sh launcher + drops DB + removes worktree.
#
# Target identity:
#   - Default: the current git worktree (basename → slug).
#   - Override: WORKSPACE_NAME=<slug-or-branch>. Branch names like "feat/foo"
#     reduce to "foo" (rightmost "/" segment, "<app>-" prefix stripped).
#
# Usage:
#   cd .claude/worktrees/<slug>
#   ./bin/worktree-remove.sh
#
#   # Or from the main worktree:
#   WORKSPACE_NAME=<slug> bash bin/worktree-remove.sh
#
# Aborts on uncommitted changes. Override: WORKSPACE_FORCE=1.

set -euo pipefail

# --------------------------------------------------------------------------- #
# Colors
# --------------------------------------------------------------------------- #
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

# --------------------------------------------------------------------------- #
# Detect OS
# --------------------------------------------------------------------------- #
IS_MACOS=false
if [[ "$(uname -s)" == "Darwin" ]]; then
    IS_MACOS=true
fi

# --------------------------------------------------------------------------- #
# Resolve workspace identity
# --------------------------------------------------------------------------- #
WORKSPACE_NAME_INPUT="${WORKSPACE_NAME:-}"

MAIN_PATH=$(git worktree list --porcelain 2>/dev/null | awk '/^worktree /{print $2; exit}')
if [[ -z "$MAIN_PATH" ]]; then
    echo -e "${RED}Error: cannot derive main worktree path from git.${NC}" >&2
    exit 1
fi

APP_SLUG=$(basename "$MAIN_PATH" | tr '[:upper:]' '[:lower:]' | tr ' ' '-' | tr '_' '-')

_normalise_slug() {
    local raw="$1"
    local slug
    slug=$(echo "$raw" | tr '[:upper:]' '[:lower:]' | tr ' ' '-')
    slug="${slug##*/}"
    slug="${slug#"${APP_SLUG}-"}"
    slug=$(echo "$slug" | tr -s '-')
    slug="${slug#-}"
    slug="${slug%-}"
    echo "$slug"
}

if [[ -n "$WORKSPACE_NAME_INPUT" ]]; then
    WORKSPACE_SLUG=$(_normalise_slug "$WORKSPACE_NAME_INPUT")
    TARGET_PATH=$(git worktree list --porcelain 2>/dev/null \
        | awk '/^worktree /{print $2}' \
        | while read -r p; do
            [[ "$(basename "$p")" == "$WORKSPACE_SLUG" ]] && echo "$p" && break
        done || true)
    if [[ -z "$TARGET_PATH" ]]; then
        echo -e "${RED}Error: no worktree found for slug '${WORKSPACE_SLUG}'.${NC}" >&2
        exit 1
    fi
else
    TARGET_PATH=$(git rev-parse --show-toplevel 2>/dev/null || true)
    if [[ -z "$TARGET_PATH" ]]; then
        echo -e "${RED}Error: not inside a git worktree; export WORKSPACE_NAME=<slug>.${NC}" >&2
        exit 1
    fi
    WORKSPACE_SLUG=$(_normalise_slug "$(basename "$TARGET_PATH")")
fi

if [[ -z "$WORKSPACE_SLUG" || "$WORKSPACE_SLUG" == "$APP_SLUG" ]]; then
    echo -e "${RED}Error: workspace slug resolves to empty/main repo; export WORKSPACE_NAME=<slug>.${NC}" >&2
    exit 1
fi

if [[ "$TARGET_PATH" == "$MAIN_PATH" ]]; then
    echo -e "${RED}Error: refusing to remove the main worktree (${MAIN_PATH}).${NC}" >&2
    exit 1
fi

SITE_NAME="${APP_SLUG}-${WORKSPACE_SLUG}"
APP_IDENT=$(echo "$APP_SLUG" | tr '-' '_')
WORKSPACE_IDENT=$(echo "$WORKSPACE_SLUG" | tr '-' '_')
DB_NAME="${APP_IDENT}_${WORKSPACE_IDENT}"
SOURCE_DB="${APP_IDENT}"

if [[ "$DB_NAME" == "$SOURCE_DB" ]]; then
    echo -e "${RED}Error: workspace DB resolves to source DB '${SOURCE_DB}'; aborting.${NC}" >&2
    exit 1
fi

DB_USER=$(grep -E '^DB_USERNAME=' "$TARGET_PATH/.env" 2>/dev/null | cut -d= -f2 | tr -d '"' || true)
DB_USER="${DB_USER:-postgres}"

echo -e "${CYAN}Removing workspace: ${WORKSPACE_SLUG}${NC}"
if $IS_MACOS; then
    echo -e "  Site:     https://${SITE_NAME}.test"
fi
echo -e "  Database: ${DB_NAME}"
echo -e "  Path:     ${TARGET_PATH}"
echo ""

# --------------------------------------------------------------------------- #
# Guard: uncommitted changes
# --------------------------------------------------------------------------- #
DIRTY=$(git -C "$TARGET_PATH" status --porcelain 2>/dev/null || true)
if [[ -n "$DIRTY" ]] && [[ "${WORKSPACE_FORCE:-0}" != "1" ]]; then
    echo -e "${RED}Error: worktree has uncommitted changes:${NC}" >&2
    echo "$DIRTY" >&2
    echo "" >&2
    echo "Commit/stash, or re-run with WORKSPACE_FORCE=1 to discard." >&2
    exit 1
fi

# --------------------------------------------------------------------------- #
# macOS: unlink Herd site
# --------------------------------------------------------------------------- #
if $IS_MACOS; then
    echo -e "${YELLOW}Removing Herd site link...${NC}"
    herd unlink "$SITE_NAME" 2>/dev/null || true
fi

# --------------------------------------------------------------------------- #
# Linux: remove dev launcher
# --------------------------------------------------------------------------- #
if ! $IS_MACOS; then
    LAUNCHER="${MAIN_PATH}/bin/dev-${WORKSPACE_SLUG}.sh"
    if [[ -f "$LAUNCHER" ]]; then
        echo -e "${YELLOW}Removing dev launcher ${LAUNCHER}...${NC}"
        rm -f "$LAUNCHER"
    fi
fi

# --------------------------------------------------------------------------- #
# Drop workspace database
# --------------------------------------------------------------------------- #
echo -e "${YELLOW}Dropping database ${DB_NAME}...${NC}"
dropdb -U "$DB_USER" --if-exists "$DB_NAME" 2>/dev/null || true

# --------------------------------------------------------------------------- #
# Remove git worktree (must run from outside the target)
# --------------------------------------------------------------------------- #
echo -e "${YELLOW}Removing git worktree...${NC}"
cd "$MAIN_PATH"
git worktree remove --force "$TARGET_PATH"
git worktree prune

echo -e "${GREEN}Workspace removed: ${WORKSPACE_SLUG}${NC}"
exit 0
