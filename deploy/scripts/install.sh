#!/bin/bash
#
# D-BEST TimeSmart Installation Script
#
# Clones TimeSmart into ./Timeclock-<Company>, writes its .env, creates the
# database (on this host's MariaDB/MySQL, or one you point it at), builds and
# starts the container, and creates the first admin or loads demo data.
#
#   cd /opt && sudo bash <(curl -s https://raw.githubusercontent.com/D-Best-App/Timesmart/main/deploy/scripts/install.sh)
#
# The app container publishes no ports. Put a reverse proxy (Nginx Proxy
# Manager, Caddy) or a cloudflared container on the timesmart-net network in
# front of it; logins need HTTPS (secure session cookies).

set -euo pipefail

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

REPO_URL="${TIMESMART_REPO:-https://github.com/D-Best-App/Timesmart.git}"   # override to test a fork/branch
NETWORK="timesmart-net"
NETWORK_SUBNET="172.19.0.0/16"   # trusted for real-IP by deploy/docker/nginx.conf

die()  { echo -e "${RED}✗ $*${NC}" >&2; exit 1; }
ok()   { echo -e "${GREEN}✓ $*${NC}"; }
warn() { echo -e "${YELLOW}⚠ $*${NC}"; }
ask()  { local __v; read -r -p "$1" __v; echo "${__v:-$2}"; }
yes_no() { local a; a=$(ask "$1 (y/n) [${2}]: " "$2"); [ "$a" = "y" ] || [ "$a" = "Y" ]; }

echo -e "${BLUE}"
echo "╔═══════════════════════════════════════╗"
echo "║   D-BEST TimeSmart Installation       ║"
echo "╚═══════════════════════════════════════╝"
echo -e "${NC}"

# ========================
# Prerequisites
# ========================
echo -e "${YELLOW}Checking prerequisites...${NC}"
command -v docker >/dev/null || die "Docker is not installed: curl -fsSL https://get.docker.com | sh"
docker info >/dev/null 2>&1 || die "Docker is not running, or this user can't use it (try sudo)."
docker compose version >/dev/null 2>&1 || die "The Docker Compose plugin is missing (apt-get install docker-compose-plugin)."
command -v git >/dev/null || die "Git is not installed: apt-get install git"

MYSQL_BIN=""
for b in mariadb mysql; do command -v "$b" >/dev/null && { MYSQL_BIN="$b"; break; }; done
ok "Prerequisites check passed"
echo

# ========================
# Install details
# ========================
company_name=$(ask "Company name (e.g. Acme Corp): " "")
[ -n "$company_name" ] || die "Company name cannot be empty"

SAFE_NAME=$(echo "$company_name" | sed 's/[^a-zA-Z0-9]/-/g; s/--*/-/g; s/^-//; s/-$//')
INSTALL_DIR="$(pwd)/Timeclock-$SAFE_NAME"
CONTAINER_NAME="Timeclock-$SAFE_NAME"
DB_NAME="timeclock-$SAFE_NAME"

if [ -e "$INSTALL_DIR" ]; then
    die "$INSTALL_DIR already exists. Remove it first (deploy/scripts/remove.sh) or pick another name."
fi
if docker container inspect "$CONTAINER_NAME" >/dev/null 2>&1; then
    die "A container named $CONTAINER_NAME already exists."
fi

db_timezone=$(ask "Timezone [America/Chicago]: " "America/Chicago")
[ -f "/usr/share/zoneinfo/$db_timezone" ] || warn "'$db_timezone' is not in /usr/share/zoneinfo here; make sure it is a valid zone name."

echo -e "\n${BLUE}Database${NC}"
echo "  1) MariaDB/MySQL on this server: create the database and its own user now (needs root)"
echo "  2) An existing database server: enter its host and a user that can create the database"
db_mode=$(ask "Choose 1 or 2 [1]: " "1")

DB_USER="timeclock"
if [ "$db_mode" = "1" ]; then
    [ -n "$MYSQL_BIN" ] || die "No mariadb/mysql client found. Install the server first: apt-get install mariadb-server"
    [ "$(id -u)" = "0" ] || die "Option 1 needs root (to reach MariaDB as root and configure it). Re-run with sudo."
    DB_HOST="host.docker.internal"
    # One user per install: a shared user would get its password reset by the next install
    DB_USER=$(echo "tc_$SAFE_NAME" | tr '[:upper:]-' '[:lower:]_' | cut -c1-32)
    DB_PASS=$(head -c 512 /dev/urandom | tr -dc 'A-Za-z0-9' | cut -c1-28)
else
    DB_HOST=$(ask "Database host (as seen from the container) [host.docker.internal]: " "host.docker.internal")
    DB_USER=$(ask "Database user [timeclock]: " "timeclock")
    read -r -s -p "Database password: " DB_PASS; echo
    [ -n "$DB_PASS" ] || die "Database password cannot be empty"
fi
case "$DB_PASS" in *"'"*) die "The database password may not contain a single quote (').";; esac

# ========================
# Clone
# ========================
echo -e "\n${YELLOW}📥 Cloning TimeSmart...${NC}"
git clone -q "$REPO_URL" "$INSTALL_DIR"
cd "$INSTALL_DIR"
ok "Cloned into $INSTALL_DIR"

# Per-install settings: docker-compose.yml stays untouched so updates pull cleanly.
umask 077
cat > .env <<EOF
# TimeSmart install settings for docker-compose.yml (see .env.example)
CONTAINER_NAME='$CONTAINER_NAME'
DB_HOST='$DB_HOST'
DB_NAME='$DB_NAME'
DB_USER='$DB_USER'
DB_PASS='$DB_PASS'
DB_TIMEZONE='$db_timezone'
EOF
umask 022
ok "Wrote .env (readable by its owner only)"

# ========================
# Database
# ========================
schema_sql() {
    sed "s/DB_NAME_PLACEHOLDER/$DB_NAME/g" deploy/database/timeclock-schema.sql
    # The schema already includes every migration: record them as applied, or the
    # first update.sh would replay them against the current schema and fail.
    echo "USE \`$DB_NAME\`;"
    for f in deploy/database/migrations/*.sql; do
        echo "INSERT IGNORE INTO schema_migrations (filename) VALUES ('$(basename "$f")');"
    done
}

echo -e "\n${YELLOW}📦 Setting up the database...${NC}"
if [ "$db_mode" = "1" ]; then
    # Containers reach the host over the Docker bridge, so the server must not be
    # bound to 127.0.0.1 only. Listen on all interfaces and let the firewall keep
    # 3306 closed to everything except Docker networks.
    listening=$(ss -ltnH 2>/dev/null | awk '{print $4}')
    if ! grep -qE '^(0\.0\.0\.0|\*|\[::\]):3306$' <<<"$listening"; then
        warn "The database server only listens on localhost, so containers can't reach it."
        if yes_no "Make it listen for Docker containers (adds a MariaDB config file and restarts it)?" "y"; then
            conf_dir=/etc/mysql/mariadb.conf.d; [ -d "$conf_dir" ] || conf_dir=/etc/mysql/conf.d
            printf '[mysqld]\nbind-address = 0.0.0.0\n' > "$conf_dir/60-timesmart.cnf"
            systemctl restart mariadb 2>/dev/null || systemctl restart mysql
            ok "Database server now listens on all interfaces"
        else
            warn "Skipped: the app won't connect until the server listens on the Docker bridge."
        fi
    fi
    if command -v ufw >/dev/null && [[ "$(ufw status 2>/dev/null)" == *"Status: active"* ]]; then
        ufw allow from 172.16.0.0/12 to any port 3306 proto tcp >/dev/null
        ok "Firewall: 3306 open to Docker networks only"
    else
        warn "No active ufw firewall: make sure port 3306 is not reachable from the internet."
    fi
    # Named time zones for SET time_zone (harmless if already loaded)
    command -v mysql_tzinfo_to_sql >/dev/null && mysql_tzinfo_to_sql /usr/share/zoneinfo 2>/dev/null | "$MYSQL_BIN" mysql || true

    "$MYSQL_BIN" <<EOF
CREATE USER IF NOT EXISTS '$DB_USER'@'172.%' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'172.%' IDENTIFIED BY '$DB_PASS';
EOF
    schema_sql | "$MYSQL_BIN"
    "$MYSQL_BIN" -e "GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'172.%'; FLUSH PRIVILEGES;"
    ok "Database $DB_NAME created; user $DB_USER@172.% can reach it from containers"
else
    if [ -n "$MYSQL_BIN" ] && yes_no "Create database '$DB_NAME' on $DB_HOST now?" "y"; then
        host_for_client="$DB_HOST"; [ "$DB_HOST" = "host.docker.internal" ] && host_for_client="127.0.0.1"
        schema_sql | MYSQL_PWD="$DB_PASS" "$MYSQL_BIN" -h "$host_for_client" -u "$DB_USER" \
            || die "Database creation failed. Create it by hand from deploy/database/timeclock-schema.sql, then re-run from 'docker compose up -d'."
        ok "Database $DB_NAME created"
    else
        warn "Create database '$DB_NAME' yourself from deploy/database/timeclock-schema.sql before signing in."
    fi
fi

# ========================
# Container
# ========================
echo -e "\n${YELLOW}🐳 Starting the container...${NC}"
if ! docker network inspect "$NETWORK" >/dev/null 2>&1; then
    docker network create --subnet "$NETWORK_SUBNET" "$NETWORK" >/dev/null 2>&1 \
        || docker network create "$NETWORK" >/dev/null
    ok "Created Docker network $NETWORK"
fi
if ! docker image inspect dbest25/timesmart:latest >/dev/null 2>&1; then
    echo "Building the TimeSmart image (a few minutes on a small server)..."
    docker compose build -q
fi
docker compose up -d
sleep 3
docker exec "$CONTAINER_NAME" composer install --no-dev --optimize-autoloader -q -d /var/www/html
docker exec "$CONTAINER_NAME" chown -R www-data:www-data /var/www/html/vendor
ok "Container $CONTAINER_NAME is running"

# Runs a repo script with the app's DB settings (deploy/ isn't mounted in the app container)
run_repo_php() {
    # </dev/null: otherwise the container swallows the rest of the installer's input
    docker compose run --rm -T --no-deps -v "$INSTALL_DIR":/repo -w /repo "$@" </dev/null
}

# ========================
# First admin or demo data
# ========================
echo -e "\n${BLUE}Accounts${NC}"
if yes_no "Load demo data instead of a real first admin (demo employees, punches, approvals)?" "n"; then
    run_repo_php app php -d variables_order=EGPCS deploy/scripts/seed_demo.php
    ADMIN_HINT="Demo logins: see docs/DEMO.md (e.g. 'Pat Admin', password Demo123!)"
else
    echo "Create the first super admin. Users sign in with their full name, e.g. 'Jane Smith'."
    admin_first=$(ask "First name: " "")
    admin_last=$(ask "Last name: " "")
    admin_email=$(ask "Email (for sign-in codes; optional): " "")
    while :; do
        read -r -s -p "Password: " admin_pass; echo
        read -r -s -p "Repeat password: " admin_pass2; echo
        [ -n "$admin_pass" ] && [ "$admin_pass" = "$admin_pass2" ] && break
        warn "Passwords were empty or didn't match; try again."
    done
    run_repo_php -e ADMIN_FIRST="$admin_first" -e ADMIN_LAST="$admin_last" \
        -e ADMIN_EMAIL="$admin_email" -e ADMIN_PASS="$admin_pass" \
        app php -d variables_order=EGPCS deploy/scripts/create_admin.php
    unset admin_pass admin_pass2
    ADMIN_HINT="Sign in as '$admin_first $admin_last' with the password you just set"
fi

# ========================
# Scheduled jobs
# ========================
echo -e "\n${BLUE}Scheduled jobs${NC}"
if [ "$db_mode" = "1" ]; then
    # backup.sh dumps every timeclock-* database on this server, so one cron line is enough
    if crontab -l 2>/dev/null | grep -q "deploy/scripts/backup.sh"; then
        ok "Nightly database backups are already scheduled on this server (covers this install too)"
    elif yes_no "Schedule nightly database backups at 1:30 AM (last 14 kept, in /var/sql-data)?" "y"; then
        line="30 1 * * * DAILY_HOUR=1 $INSTALL_DIR/deploy/scripts/backup.sh >> /var/log/timeclock-backup.log 2>&1"
        { crontab -l 2>/dev/null || true; echo "$line"; } | crontab -
        "$INSTALL_DIR/deploy/scripts/backup.sh" >/dev/null && ok "Backups scheduled; first backup taken"
    fi
else
    warn "Backups: schedule deploy/scripts/backup.sh with DB_USER/DB_PASS/DB_HOST set (see docs/DEPLOYMENT.md)."
fi
echo "Auto clock-out closes punches left open on a past day (clock-out set to 5:00 PM,"
echo "each one queued for admin review). Skip it if you run overnight shifts."
if yes_no "Schedule auto clock-out daily at 5:15 AM?" "n"; then
    line="15 5 * * * $INSTALL_DIR/deploy/scripts/auto_clockout_wrapper.sh >> /var/log/timeclock-auto-clockout-$SAFE_NAME.log 2>&1"
    { crontab -l 2>/dev/null | grep -vF "$INSTALL_DIR/deploy/scripts/auto_clockout_wrapper.sh" || true; echo "$line"; } | crontab -
    ok "Cron job added (crontab -l to review)"
fi

# ========================
# Done
# ========================
echo -e "\n${GREEN}╔═══════════════════════════════════════╗${NC}"
echo -e "${GREEN}║      Installation Complete!           ║${NC}"
echo -e "${GREEN}╚═══════════════════════════════════════╝${NC}"
echo -e "${BLUE}Company:${NC}    $company_name"
echo -e "${BLUE}Directory:${NC}  $INSTALL_DIR"
echo -e "${BLUE}Container:${NC}  $CONTAINER_NAME  (Docker network $NETWORK, port 80, no host ports)"
echo -e "${BLUE}Database:${NC}   $DB_NAME"
echo -e "${BLUE}Accounts:${NC}   $ADMIN_HINT"
echo ""
echo -e "${GREEN}Next steps:${NC}"
echo "1. Publish it over HTTPS: point a reverse proxy or a cloudflared container on the"
echo "   $NETWORK network at http://$CONTAINER_NAME:80 (sign-in needs HTTPS)."
echo "2. Sign in at https://<your hostname>/user/login.php"
echo "3. Settings: mail server (sign-in codes, time-off notices), pay rules, shifts, offices"
echo "4. Backups stay on this server (/var/sql-data): copy them offsite too"
echo ""
echo "Update later with: cd $INSTALL_DIR && ./deploy/scripts/update.sh"
echo "Docs: docs/INSTALLATION.md"
