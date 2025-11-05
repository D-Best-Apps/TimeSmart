#!/bin/bash
#
# Auto Clock-Out Wrapper Script
#
# This script runs the auto clock-out PHP script inside the Docker container.
# It should be scheduled via cron to run at midnight daily.
#
# Usage: /path/to/auto_clockout_wrapper.sh
# Cron: 0 0 * * * /opt/Timeclock-D-Best/deploy/scripts/auto_clockout_wrapper.sh >> /var/log/timeclock-auto-clockout.log 2>&1
#

# Configuration
CONTAINER_NAME="Timeclock-D-Best"
SCRIPT_PATH="/var/www/html/scripts/auto_clockout.php"
LOG_PREFIX="[Auto ClockOut Wrapper]"

echo "$LOG_PREFIX $(date '+%Y-%m-%d %H:%M:%S') - Starting auto clock-out process"

# Check if container is running
if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
    echo "$LOG_PREFIX ERROR: Container '$CONTAINER_NAME' is not running!" >&2
    exit 1
fi

# Execute the PHP script inside the container
docker exec "$CONTAINER_NAME" php "$SCRIPT_PATH"
EXIT_CODE=$?

if [ $EXIT_CODE -eq 0 ]; then
    echo "$LOG_PREFIX $(date '+%Y-%m-%d %H:%M:%S') - Auto clock-out completed successfully"
else
    echo "$LOG_PREFIX ERROR: Auto clock-out script exited with code $EXIT_CODE" >&2
fi

exit $EXIT_CODE
