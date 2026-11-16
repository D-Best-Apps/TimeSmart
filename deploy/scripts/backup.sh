#!/bin/bash
#
# D-BEST TimeSmart Backup Script
# Backs up all TimeSmart databases
#
# Run manually or from cron. Each run dumps every timeclock-* database; the newest
# HOURLY_RETENTION dumps are kept, and the run during DAILY_HOUR also keeps a daily
# copy (newest DAILY_RETENTION kept). Examples:
#   0 * * * *  /path/to/backup.sh                  # hourly, daily copy at 8 PM
#   30 1 * * * DAILY_HOUR=1 /path/to/backup.sh     # nightly at 1:30 AM
#
# Credentials: run as root on the database server with DB_PASS unset and it signs in
# over the local socket (nothing stored). Otherwise set DB_USER/DB_PASS/DB_HOST below
# or in the environment.
#

set -e

# ========================
# Configuration
# ========================
# Database credentials - MODIFY THESE FOR YOUR ENVIRONMENT
DB_USER="${DB_USER:-timeclock}"
DB_PASS="${DB_PASS:-YOUR_PASSWORD_HERE}"
DB_HOST="${DB_HOST:-172.17.0.1}"

# Backup location - where to store backups
BACKUP_BASE="${BACKUP_BASE:-/var/sql-data}"

# How many hourly backups to keep
HOURLY_RETENTION=8

# Daily copies: made by the run during this hour (0-23); this many are kept.
DAILY_HOUR="${DAILY_HOUR:-20}"
DAILY_RETENTION="${DAILY_RETENTION:-14}"

# Dumps hold password hashes: keep them owner-only
umask 077

# Color codes (disabled if not in terminal)
if [ -t 1 ]; then
    RED='\033[0;31m'
    GREEN='\033[0;32m'
    YELLOW='\033[1;33m'
    BLUE='\033[0;34m'
    NC='\033[0m'
else
    RED=''
    GREEN=''
    YELLOW=''
    BLUE=''
    NC=''
fi

# ========================
# Functions
# ========================
log_info() {
    echo -e "${BLUE}[$(date +'%Y-%m-%d %H:%M:%S')]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[$(date +'%Y-%m-%d %H:%M:%S')] ✓${NC} $1"
}

log_error() {
    echo -e "${RED}[$(date +'%Y-%m-%d %H:%M:%S')] ✗${NC} $1" >&2
}

log_warning() {
    echo -e "${YELLOW}[$(date +'%Y-%m-%d %H:%M:%S')] ⚠${NC} $1"
}

# Keep the newest $2 files matching glob $1
prune() {
    local keep="$2" f
    # shellcheck disable=SC2086
    ls -1t $1 2>/dev/null | tail -n +$((keep + 1)) | while read -r f; do rm -f "$f"; done
}

# ========================
# Validation
# ========================
MYSQL_BIN=$(command -v mariadb || command -v mysql || true)
DUMP_BIN=$(command -v mariadb-dump || command -v mysqldump || true)
if [ -z "$MYSQL_BIN" ] || [ -z "$DUMP_BIN" ]; then
    log_error "mysqldump not found. Please install the MariaDB/MySQL client."
    exit 1
fi

# Socket auth as root when no password is configured; otherwise TCP with the password
# passed in MYSQL_PWD (kept out of the process list, unlike -p).
if { [ -z "$DB_PASS" ] || [ "$DB_PASS" = "YOUR_PASSWORD_HERE" ]; } && [ "$(id -u)" = "0" ] \
    && "$MYSQL_BIN" -e "SELECT 1" &> /dev/null; then
    CONN=()
    AUTH_DESC="local socket as root"
elif [ -z "$DB_PASS" ] || [ "$DB_PASS" = "YOUR_PASSWORD_HERE" ]; then
    log_error "No database password set. Run as root on the database server, or set DB_PASS (see top of script)."
    exit 1
else
    export MYSQL_PWD="$DB_PASS"
    CONN=(-h "$DB_HOST" -u "$DB_USER")
    AUTH_DESC="$DB_USER@$DB_HOST"
fi

if ! "$MYSQL_BIN" "${CONN[@]}" -e "SELECT 1" &> /dev/null; then
    log_error "Failed to connect to the database ($AUTH_DESC)"
    exit 1
fi

log_info "Starting backup process ($AUTH_DESC)..."

# ========================
# Backup Process
# ========================
DATE=$(date +"%Y-%m-%d_%H-%M-%S")
HOUR=$(date +"%H")
BACKUP_COUNT=0
ERROR_COUNT=0

# Get list of timeclock databases
DATABASES=$("$MYSQL_BIN" "${CONN[@]}" -N -e "SHOW DATABASES LIKE 'timeclock-%'")

if [ -z "$DATABASES" ]; then
    log_warning "No timeclock-* databases found"
    exit 0
fi

for DB_NAME in $DATABASES; do
    # Extract company name from database name (timeclock-CompanyName -> CompanyName)
    COMPANY_NAME="${DB_NAME#timeclock-}"

    BACKUP_DIR="$BACKUP_BASE/$COMPANY_NAME"
    DAILY_BACKUP_DIR="$BACKUP_DIR/daily-backup"
    mkdir -p "$BACKUP_DIR" "$DAILY_BACKUP_DIR"

    BACKUP_FILE="$BACKUP_DIR/${DB_NAME}-${DATE}.sql.gz"

    log_info "Backing up $DB_NAME..."

    # Write to a temp name so a failed dump never replaces or rotates out a good one
    if "$DUMP_BIN" "${CONN[@]}" --single-transaction --routines --triggers --events "$DB_NAME" \
        | gzip > "$BACKUP_FILE.tmp" && [ "${PIPESTATUS[0]}" -eq 0 ]; then
        mv "$BACKUP_FILE.tmp" "$BACKUP_FILE"

        SIZE=$(du -h "$BACKUP_FILE" | cut -f1)
        log_success "Backed up $DB_NAME → $BACKUP_FILE ($SIZE)"
        BACKUP_COUNT=$((BACKUP_COUNT + 1))

        prune "$BACKUP_DIR/${DB_NAME}-*.sql.gz" "$HOURLY_RETENTION"

        if [ "$((10#$HOUR))" -eq "$((10#$DAILY_HOUR))" ]; then
            cp "$BACKUP_FILE" "$DAILY_BACKUP_DIR/"
            prune "$DAILY_BACKUP_DIR/${DB_NAME}-*.sql.gz" "$DAILY_RETENTION"
            log_success "Daily copy kept for $COMPANY_NAME (newest $DAILY_RETENTION kept)"
        fi
    else
        rm -f "$BACKUP_FILE.tmp"
        log_error "Failed to backup $DB_NAME"
        ERROR_COUNT=$((ERROR_COUNT + 1))
    fi
done

# ========================
# Summary
# ========================
echo ""
log_info "========================================"
if [ $ERROR_COUNT -eq 0 ]; then
    log_success "Backup complete: $BACKUP_COUNT database(s) backed up"
else
    log_warning "Backup complete: $BACKUP_COUNT succeeded, $ERROR_COUNT failed"
fi
log_info "Backups stored in: $BACKUP_BASE"
log_info "========================================"

exit $ERROR_COUNT
