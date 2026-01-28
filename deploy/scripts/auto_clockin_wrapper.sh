#!/bin/bash
#
# Auto Clock-In Wrapper Script
#
# Executes the auto clock-in PHP script inside the Docker container.
# This script should be called by cron at 8AM on weekdays.
#
# Usage: ./auto_clockin_wrapper.sh
# Cron:  0 8 * * 1-5 /path/to/auto_clockin_wrapper.sh >> /var/log/timeclock-auto-clockin.log 2>&1
#

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

# Try to detect container name
CONTAINER_NAME=""
for name in "Timeclock-D-Best" "timeclock" "timesmart"; do
    if docker ps --format '{{.Names}}' | grep -q "^${name}$"; then
        CONTAINER_NAME="$name"
        break
    fi
done

# If not found, try partial match
if [[ -z "$CONTAINER_NAME" ]]; then
    CONTAINER_NAME=$(docker ps --format '{{.Names}}' | grep -i timeclock | head -1)
fi

if [[ -z "$CONTAINER_NAME" ]]; then
    echo "[Auto ClockIn Wrapper] $(date '+%Y-%m-%d %H:%M:%S') - ERROR: Could not find TimeClock container"
    exit 1
fi

echo "[Auto ClockIn Wrapper] $(date '+%Y-%m-%d %H:%M:%S') - Starting auto clock-in process"
echo "[Auto ClockIn Wrapper] Using container: $CONTAINER_NAME"

# Execute the PHP script inside the container
docker exec "$CONTAINER_NAME" php /var/www/html/scripts/auto_clockin.php

EXIT_CODE=$?

if [[ $EXIT_CODE -eq 0 ]]; then
    echo "[Auto ClockIn Wrapper] $(date '+%Y-%m-%d %H:%M:%S') - Auto clock-in completed successfully"
else
    echo "[Auto ClockIn Wrapper] $(date '+%Y-%m-%d %H:%M:%S') - Auto clock-in failed with exit code: $EXIT_CODE"
fi

exit $EXIT_CODE
