#!/bin/bash
#
# D-BEST TimeSmart Update Script
# Updates a running TimeSmart installation from Git
#

set -e  # Exit on error

# git pull below can rewrite this file while bash is still reading it; run from a copy.
# (Skipped when the script arrives on a pipe, e.g. bash <(curl -s .../update.sh).)
if [ -z "${TS_UPDATE_FROM_COPY:-}" ] && [ -f "$0" ]; then
    _copy=$(mktemp)
    cp "$0" "$_copy"
    TS_UPDATE_FROM_COPY=1 exec bash "$_copy" "$@"
fi
[ -n "${TS_UPDATE_FROM_COPY:-}" ] && trap 'rm -f "$0"' EXIT

# Color codes
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}"
echo "╔═══════════════════════════════════════╗"
echo "║   D-BEST TimeSmart Update            ║"
echo "╚═══════════════════════════════════════╝"
echo -e "${NC}"

# Check if we're in a git repository
if [ ! -d ".git" ]; then
    echo -e "${RED}✗ Error: Not in a git repository${NC}"
    echo "Please run this script from your TimeSmart installation directory"
    exit 1
fi

if [ ! -f "docker-compose.yml" ]; then
    echo -e "${RED}✗ Error: docker-compose.yml not found${NC}"
    exit 1
fi

# Per-install settings live in .env. Older installs kept them in docker-compose.yml
# itself: rebuild .env from the container this directory started, then restore the
# stock docker-compose.yml so the pull below doesn't conflict with local edits.
if [ ! -f ".env" ]; then
    OLD_CONTAINER=$(docker ps -a --filter "label=com.docker.compose.project.working_dir=$(pwd)" --format '{{.Names}}' | head -1)
    if [ -z "$OLD_CONTAINER" ]; then
        OLD_CONTAINER=$(grep -m1 "container_name:" docker-compose.yml | awk '{print $2}')
    fi
    if [ -z "$OLD_CONTAINER" ] || [[ "$OLD_CONTAINER" == *'$'* ]] || ! docker container inspect "$OLD_CONTAINER" >/dev/null 2>&1; then
        echo -e "${RED}✗ Error: no .env and no existing container to read settings from.${NC}"
        echo "Create one from .env.example (cp .env.example .env) and re-run."
        exit 1
    fi
    echo -e "${YELLOW}Moving install settings from docker-compose.yml to .env...${NC}"
    ( umask 077
      {
        echo "# TimeSmart install settings for docker-compose.yml (see .env.example)"
        echo "CONTAINER_NAME='$OLD_CONTAINER'"
        docker inspect -f '{{range .Config.Env}}{{println .}}{{end}}' "$OLD_CONTAINER" \
          | grep -E '^(DB_HOST|DB_NAME|DB_USER|DB_PASS|DB_TIMEZONE|SESSION_COOKIE_SECURE)=' \
          | sed -E "s/^([A-Z_]+)=(.*)$/\1='\2'/"
      } > .env
    )
    if grep -q "^DB_PASS='.*'.*'" .env; then
        echo -e "${YELLOW}⚠ DB_PASS contains a single quote; check its quoting in .env${NC}"
    fi
    git checkout -- docker-compose.yml 2>/dev/null || true
    echo -e "${GREEN}✓ Wrote .env from container $OLD_CONTAINER${NC}"
fi

CONTAINER_NAME=$(grep -m1 '^CONTAINER_NAME=' .env | cut -d= -f2- | tr -d "'\"")

if [ -z "$CONTAINER_NAME" ]; then
    echo -e "${RED}✗ Error: CONTAINER_NAME is not set in .env${NC}"
    exit 1
fi

# Check if container is running
if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
    echo -e "${YELLOW}⚠ Warning: Container '$CONTAINER_NAME' is not running${NC}"
    read -p "Do you want to start it after update? (y/n): " start_after
else
    echo -e "${GREEN}✓ Container '$CONTAINER_NAME' is running${NC}"
    start_after="n"
fi

# Show current status
echo -e "\n${BLUE}Current Status:${NC}"
git log -1 --oneline

# Backup check
echo -e "\n${YELLOW}⚠ It's recommended to backup your database before updating${NC}"
read -p "Have you backed up your database? (y/n): " backed_up

if [ "$backed_up" != "y" ]; then
    echo -e "${YELLOW}Consider running: deploy/scripts/backup.sh${NC}"
    read -p "Continue anyway? (y/n): " continue_update
    if [ "$continue_update" != "y" ]; then
        echo "Update cancelled."
        exit 0
    fi
fi

# Pull latest changes
echo -e "\n${YELLOW}📥 Pulling latest changes from GitHub...${NC}"

# Stash any local changes
if [ -n "$(git status --porcelain)" ]; then
    echo -e "${YELLOW}⚠ You have uncommitted changes. Stashing them...${NC}"
    git stash
    STASHED=true
else
    STASHED=false
fi

# Pull from main
git pull origin main

if [ $? -ne 0 ]; then
    echo -e "${RED}✗ Failed to pull updates${NC}"
    if [ "$STASHED" = true ]; then
        echo "Restoring your changes..."
        git stash pop
    fi
    exit 1
fi

# Show what changed
echo -e "\n${GREEN}✓ Updated to:${NC}"
git log -1 --oneline

# Check if composer.json changed
if git diff HEAD@{1} --name-only | grep -q "composer.json\|composer.lock"; then
    echo -e "\n${YELLOW}📦 Composer dependencies changed, updating...${NC}"
    
    docker exec "$CONTAINER_NAME" composer install --no-dev --optimize-autoloader -d /var/www/html
    docker exec "$CONTAINER_NAME" chown -R www-data:www-data /var/www/html/vendor
    echo -e "${GREEN}✓ Composer dependencies updated${NC}"
fi

# Run any pending database migrations (idempotent; tracked in schema_migrations)
if docker ps --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
    echo -e "\n${YELLOW}\xf0\x9f\x97\x84  Applying database migrations...${NC}"
    docker cp deploy/database/migrations "$CONTAINER_NAME":/tmp/ts_migrations
    docker cp deploy/scripts/run_migrations.php "$CONTAINER_NAME":/tmp/run_migrations.php
    if docker exec "$CONTAINER_NAME" php /tmp/run_migrations.php /tmp/ts_migrations; then
        echo -e "${GREEN}\xe2\x9c\x93 Migrations up to date${NC}"
    else
        echo -e "${RED}\xe2\x9c\x97 Migration failed - see output above. Resolve before using the app.${NC}"
    fi
    docker exec "$CONTAINER_NAME" rm -rf /tmp/ts_migrations /tmp/run_migrations.php
else
    echo -e "${YELLOW}\xe2\x9a\xa0 Container not running - skipping migrations. Re-run update once it is up.${NC}"
fi

# Volume mounts mean changes are immediately reflected!
echo -e "\n${GREEN}✓ Application files updated via volume mount${NC}"

# Restart container if needed
if docker ps --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
    echo -e "\n${YELLOW}🔄 Restarting container to apply changes...${NC}"
    docker restart "$CONTAINER_NAME"
    
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✓ Container restarted successfully${NC}"
        
        # Get container IP
        sleep 2
        CONTAINER_IP=$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$CONTAINER_NAME")
        echo -e "${BLUE}Access URL:${NC} http://$CONTAINER_IP"
    else
        echo -e "${RED}✗ Failed to restart container${NC}"
        exit 1
    fi
elif [ "$start_after" = "y" ]; then
    echo -e "\n${YELLOW}🚀 Starting container...${NC}"
    docker compose up -d
    
    if [ $? -eq 0 ]; then
        echo -e "${GREEN}✓ Container started successfully${NC}"
        
        sleep 2
        CONTAINER_IP=$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$CONTAINER_NAME")
        echo -e "${BLUE}Access URL:${NC} http://$CONTAINER_IP"
    else
        echo -e "${RED}✗ Failed to start container${NC}"
        exit 1
    fi
fi

# Restore stashed changes if any
if [ "$STASHED" = true ]; then
    echo -e "\n${YELLOW}Restoring your local changes...${NC}"
    git stash pop
fi

echo -e "\n${GREEN}╔═══════════════════════════════════════╗${NC}"
echo -e "${GREEN}║       Update Complete!               ║${NC}"
echo -e "${GREEN}╚═══════════════════════════════════════╝${NC}"
echo ""
echo -e "${BLUE}Next steps:${NC}"
echo "1. Test the application at http://$CONTAINER_IP"
echo "2. Database migrations were applied automatically"
echo "3. Clear browser cache if CSS/JS doesn't update"
echo ""
