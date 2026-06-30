#!/usr/bin/env bash
#
# bin/worktree-create — provision an isolated workspace for a git worktree.
#
# Cross-platform:
#   macOS  → provisions a Laravel Herd site (herd link --secure --isolate) +
#             cloned Postgres DB + migrate + bun build.
#   Linux  → per-worktree .env + cloned Postgres DB + migrate + bun build;
#             run via `composer dev` (artisan serve).
#
# Identity is derived from the current git worktree basename. Override by
# exporting WORKSPACE_NAME before invoking.
#
# Usage:
#   git worktree add .claude/worktrees/<slug> -b <slug>
#   cd .claude/worktrees/<slug>
#   ./bin/worktree-create.sh
#
#   # Or from any directory:
#   WORKSPACE_NAME=<slug> bash bin/worktree-create.sh

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
WORKSPACE_NAME="${WORKSPACE_NAME:-}"

if [[ -z "$WORKSPACE_NAME" ]]; then
    WORKSPACE_NAME=$(basename "$(git rev-parse --show-toplevel 2>/dev/null)" 2>/dev/null || true)
    if [[ -z "$WORKSPACE_NAME" ]]; then
        echo -e "${RED}Error: not inside a git worktree; export WORKSPACE_NAME or run from a worktree.${NC}" >&2
        exit 1
    fi
fi

ROOT_PATH=$(git worktree list --porcelain 2>/dev/null | awk '/^worktree /{print $2; exit}')
if [[ -z "$ROOT_PATH" ]]; then
    echo -e "${RED}Error: cannot derive main worktree path from git.${NC}" >&2
    exit 1
fi

# Normalise slug: lowercase, spaces→dashes, take rightmost "/" segment,
# strip any "<appname>-" prefix, collapse repeated dashes, trim edges.
APP_SLUG=$(basename "$ROOT_PATH" | tr '[:upper:]' '[:lower:]' | tr ' ' '-' | tr '_' '-')
WORKSPACE_SLUG=$(echo "$WORKSPACE_NAME" | tr '[:upper:]' '[:lower:]' | tr ' ' '-')
WORKSPACE_SLUG="${WORKSPACE_SLUG##*/}"
WORKSPACE_SLUG="${WORKSPACE_SLUG#"${APP_SLUG}-"}"
WORKSPACE_SLUG=$(echo "$WORKSPACE_SLUG" | tr -s '-')
WORKSPACE_SLUG="${WORKSPACE_SLUG#-}"
WORKSPACE_SLUG="${WORKSPACE_SLUG%-}"

if [[ -z "$WORKSPACE_SLUG" || "$WORKSPACE_SLUG" == "$APP_SLUG" ]]; then
    echo -e "${RED}Error: workspace slug resolves to empty/main repo; export WORKSPACE_NAME=<slug>.${NC}" >&2
    exit 1
fi

SITE_NAME="${APP_SLUG}-${WORKSPACE_SLUG}"
# Convert to valid identifier for DB name (hyphens → underscores)
APP_IDENT=$(echo "$APP_SLUG" | tr '-' '_')
WORKSPACE_IDENT=$(echo "$WORKSPACE_SLUG" | tr '-' '_')
DB_NAME="${APP_IDENT}_${WORKSPACE_IDENT}"
SOURCE_DB="${APP_IDENT}"

if [[ "$DB_NAME" == "$SOURCE_DB" ]]; then
    echo -e "${RED}Error: workspace DB resolves to source DB '${SOURCE_DB}'; rename the workspace.${NC}" >&2
    exit 1
fi

# Idempotent: skip if already provisioned
if [[ -f .env ]]; then
    echo -e "${YELLOW}Workspace already provisioned (.env exists in $(pwd)); skipping.${NC}"
    exit 0
fi

# Detect PHP version from composer.json
PHP_VERSION=$(grep '"php"' "$ROOT_PATH/composer.json" | grep -oE '[0-9]+\.[0-9]+' | head -1)
if [[ -z "$PHP_VERSION" ]]; then
    echo -e "${RED}Error: could not detect PHP version from composer.json${NC}" >&2
    exit 1
fi

echo -e "${CYAN}Setting up workspace: ${WORKSPACE_SLUG}${NC}"
if $IS_MACOS; then
    echo -e "  Site:     https://${SITE_NAME}.test"
else
    echo -e "  Mode:     Linux / artisan serve"
fi
echo -e "  Database: ${DB_NAME}"
echo -e "  PHP:      ${PHP_VERSION}"
echo ""

# --------------------------------------------------------------------------- #
# 1. Configure environment
# --------------------------------------------------------------------------- #
echo -e "${YELLOW}Configuring environment...${NC}"

if [[ ! -f "$ROOT_PATH/.env" ]]; then
    echo -e "${RED}Error: no .env found at ${ROOT_PATH}/.env${NC}" >&2
    exit 1
fi

cp "$ROOT_PATH/.env" .env

DB_USER=$(grep -E '^DB_USERNAME=' "$ROOT_PATH/.env" | cut -d= -f2 | tr -d '"')
DB_USER="${DB_USER:-postgres}"

if $IS_MACOS; then
    # macOS: sed -i '' (BSD sed)
    sed -i '' "s|^APP_URL=.*|APP_URL=https://${SITE_NAME}.test|" .env
    sed -i '' "s|^SESSION_DOMAIN=.*|SESSION_DOMAIN=${SITE_NAME}.test|" .env
    sed -i '' "s|^DB_DATABASE=.*|DB_DATABASE=${DB_NAME}|" .env
    sed -i '' "s|^CACHE_PREFIX=.*|CACHE_PREFIX=${DB_NAME}_cache|" .env
else
    # Linux: sed -i (GNU sed)
    sed -i "s|^APP_URL=.*|APP_URL=http://localhost:8000|" .env
    sed -i "s|^SESSION_DOMAIN=.*|SESSION_DOMAIN=localhost|" .env
    sed -i "s|^DB_DATABASE=.*|DB_DATABASE=${DB_NAME}|" .env
    sed -i "s|^CACHE_PREFIX=.*|CACHE_PREFIX=${DB_NAME}_cache|" .env
fi

# Append workspace isolation block (idempotent)
if ! grep -q '# --- Workspace isolation ---' .env; then
    cat >> .env << EOF

# --- Workspace isolation ---
REDIS_PREFIX=${DB_NAME}_database_
HORIZON_PREFIX=${DB_NAME}_horizon:
EOF
fi

# --------------------------------------------------------------------------- #
# 2. Install dependencies
# --------------------------------------------------------------------------- #
echo -e "${YELLOW}Installing Composer dependencies...${NC}"
composer install --no-interaction --ansi

echo -e "${YELLOW}Installing bun dependencies...${NC}"
bun install

# --------------------------------------------------------------------------- #
# 3. Postgres setup
# --------------------------------------------------------------------------- #
echo -e "${YELLOW}Setting up database...${NC}"

for bin in psql pg_dump createdb dropdb; do
    command -v "$bin" >/dev/null 2>&1 || {
        echo -e "${RED}Error: missing binary '${bin}' (install postgres client tools).${NC}" >&2
        exit 1
    }
done

psql -U "$DB_USER" -d postgres -tAc 'select 1' >/dev/null 2>&1 || {
    echo -e "${RED}Error: cannot reach postgres as user '${DB_USER}'.${NC}" >&2
    exit 1
}

psql -U "$DB_USER" -d postgres -tAc "select 1 from pg_database where datname = '$SOURCE_DB'" | grep -q 1 || {
    echo -e "${RED}Error: source DB '${SOURCE_DB}' does not exist.${NC}" >&2
    exit 1
}

if psql -U "$DB_USER" -lqt | cut -d '|' -f 1 | grep -qw "$DB_NAME"; then
    echo -e "  Database ${DB_NAME} already exists, skipping creation."
else
    if createdb -U "$DB_USER" -T "$SOURCE_DB" "$DB_NAME" 2>/dev/null; then
        echo -e "  ${GREEN}Created ${DB_NAME} from ${SOURCE_DB} template.${NC}"
    else
        echo -e "  Template copy failed (active connections?), using dump/restore..."
        createdb -U "$DB_USER" "$DB_NAME" 2>/dev/null || true
        pg_dump -U "$DB_USER" --no-owner --no-privileges "$SOURCE_DB" \
            | psql -U "$DB_USER" --quiet --set ON_ERROR_STOP=1 -d "$DB_NAME" >/dev/null
        echo -e "  ${GREEN}Created ${DB_NAME} via dump/restore.${NC}"
    fi
fi

# --------------------------------------------------------------------------- #
# 4. macOS: link Herd site
# --------------------------------------------------------------------------- #
if $IS_MACOS; then
    echo -e "${YELLOW}Configuring Laravel Herd site...${NC}"
    herd link "$SITE_NAME" --secure --isolate="$PHP_VERSION"
    echo -e "  ${GREEN}Linked https://${SITE_NAME}.test${NC}"
fi

# --------------------------------------------------------------------------- #
# 5. Run migrations
# --------------------------------------------------------------------------- #
echo -e "${YELLOW}Running migrations...${NC}"
php artisan migrate --force --no-interaction --ansi

# --------------------------------------------------------------------------- #
# 6. Clear caches
# --------------------------------------------------------------------------- #
php artisan optimize:clear --ansi

# --------------------------------------------------------------------------- #
# 7. Build frontend
# --------------------------------------------------------------------------- #
echo -e "${YELLOW}Building frontend assets...${NC}"
bun run build

# --------------------------------------------------------------------------- #
# 8. Linux: write a dev launcher
# --------------------------------------------------------------------------- #
if ! $IS_MACOS; then
    LAUNCHER="${ROOT_PATH}/bin/dev-${WORKSPACE_SLUG}.sh"
    cat > "$LAUNCHER" << LAUNCHER_EOF
#!/usr/bin/env bash
# Auto-generated dev launcher for worktree: ${WORKSPACE_SLUG}
# Run from: .claude/worktrees/${WORKSPACE_SLUG}
cd "$(pwd)"
composer dev
LAUNCHER_EOF
    chmod +x "$LAUNCHER"
    echo -e "  ${GREEN}Dev launcher written: ${LAUNCHER}${NC}"
fi

# --------------------------------------------------------------------------- #
# Done
# --------------------------------------------------------------------------- #
echo ""
echo -e "${GREEN}Workspace setup complete!${NC}"
if $IS_MACOS; then
    echo -e "  URL:      https://${SITE_NAME}.test"
else
    echo -e "  Run:      bash ${ROOT_PATH}/bin/dev-${WORKSPACE_SLUG}.sh"
fi
echo -e "  Database: ${DB_NAME}"

exit 0
