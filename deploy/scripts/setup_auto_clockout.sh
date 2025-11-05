#!/bin/bash
#
# Auto Clock-Out Setup Script
#
# This script sets up the automatic clock-out cron job that runs at midnight.
# It will configure the system to automatically clock out employees who forget
# to clock out, setting their time retroactively to 5:00 PM.
#
# Usage: sudo ./setup_auto_clockout.sh
#

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
WRAPPER_SCRIPT="$PROJECT_ROOT/deploy/scripts/auto_clockout_wrapper.sh"
LOG_FILE="/var/log/timeclock-auto-clockout.log"
CRON_SCHEDULE="0 0 * * *"  # Midnight daily

echo -e "${BLUE}╔════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║    D-BEST TimeSmart - Auto Clock-Out Setup                ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════════╝${NC}"
echo

# Check if running as root
if [[ $EUID -ne 0 ]]; then
   echo -e "${RED}Error: This script must be run as root (use sudo)${NC}"
   exit 1
fi

# Check if wrapper script exists
if [[ ! -f "$WRAPPER_SCRIPT" ]]; then
    echo -e "${RED}Error: Wrapper script not found at: $WRAPPER_SCRIPT${NC}"
    exit 1
fi

# Make wrapper script executable
chmod +x "$WRAPPER_SCRIPT"

# Check if cron job already exists
CRON_EXISTS=$(crontab -l 2>/dev/null | grep -c "auto_clockout_wrapper.sh" || true)

if [[ $CRON_EXISTS -gt 0 ]]; then
    echo -e "${YELLOW}Auto clock-out cron job is already installed!${NC}"
    echo
    echo "Current cron entry:"
    crontab -l 2>/dev/null | grep "auto_clockout_wrapper.sh"
    echo
    read -p "Do you want to reinstall/update it? (y/N): " -n 1 -r
    echo
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo -e "${BLUE}Setup cancelled. No changes made.${NC}"
        exit 0
    fi
    
    # Remove existing cron job
    crontab -l 2>/dev/null | grep -v "auto_clockout_wrapper.sh" | crontab -
    echo -e "${GREEN}✓ Removed existing cron job${NC}"
fi

# Display information
echo -e "${BLUE}Configuration:${NC}"
echo "  • Schedule: Daily at midnight (12:00 AM)"
echo "  • Clock-out time: 5:00 PM (configurable in PHP script)"
echo "  • Log file: $LOG_FILE"
echo "  • Wrapper script: $WRAPPER_SCRIPT"
echo

# Confirm installation
read -p "Do you want to install the auto clock-out cron job? (Y/n): " -n 1 -r
echo
if [[ $REPLY =~ ^[Nn]$ ]]; then
    echo -e "${BLUE}Setup cancelled. No changes made.${NC}"
    exit 0
fi

# Create log file if it doesn't exist
if [[ ! -f "$LOG_FILE" ]]; then
    touch "$LOG_FILE"
    chmod 644 "$LOG_FILE"
    echo -e "${GREEN}✓ Created log file: $LOG_FILE${NC}"
else
    echo -e "${GREEN}✓ Log file already exists: $LOG_FILE${NC}"
fi

# Add cron job
CRON_COMMAND="$CRON_SCHEDULE $WRAPPER_SCRIPT >> $LOG_FILE 2>&1"
(crontab -l 2>/dev/null; echo "$CRON_COMMAND") | crontab -

echo -e "${GREEN}✓ Installed cron job successfully!${NC}"
echo

# Test the wrapper script
echo -e "${BLUE}Running test execution...${NC}"
echo

if "$WRAPPER_SCRIPT"; then
    echo
    echo -e "${GREEN}✓ Test execution completed successfully!${NC}"
else
    echo
    echo -e "${YELLOW}⚠ Test execution had warnings (see output above)${NC}"
    echo -e "${YELLOW}  This might be normal if no employees are currently clocked in.${NC}"
fi

# Display summary
echo
echo -e "${BLUE}╔════════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║    Installation Complete!                                 ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════════╝${NC}"
echo
echo -e "${GREEN}Auto clock-out is now active!${NC}"
echo
echo "What happens next:"
echo "  • Every night at midnight, the system will check for employees"
echo "    who are still clocked in"
echo "  • They will be automatically clocked out at 5:00 PM"
echo "  • A note will be added: 'Auto-clocked out - forgot to clock out'"
echo "  • All actions are logged in the audit trail (punch_changelog)"
echo
echo "Useful commands:"
echo "  • View logs:       sudo tail -f $LOG_FILE"
echo "  • Test manually:   sudo $WRAPPER_SCRIPT"
echo "  • View cron jobs:  sudo crontab -l"
echo "  • Remove cron:     sudo crontab -e (then delete the line)"
echo
echo "Configuration:"
echo "  • To change clock-out time (default: 5 PM), edit:"
echo "    $PROJECT_ROOT/app/scripts/auto_clockout.php"
echo "    Look for: define('AUTO_CLOCKOUT_TIME', '17:00:00');"
echo
echo -e "${BLUE}For full documentation, see: docs/AUTO_CLOCKOUT.md${NC}"
echo
