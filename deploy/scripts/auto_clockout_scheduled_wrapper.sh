#!/bin/bash
#
# Scheduled Auto Clock-Out Wrapper Script
#
# Executes the scheduled clock-out PHP script inside the Docker container.
# This is the daily clock-out for Gareth at ~4:30-5:00 PM.
#
# Usage: ./auto_clockout_scheduled_wrapper.sh
# Cron:  30 16 * * 1-5 /path/to/auto_clockout_scheduled_wrapper.sh >> /var/log/timeclock-auto-clockout-scheduled.log 2>&1
#

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
LOG_PREFIX="[Auto ClockOut Scheduled Wrapper]"

# Try to detect container name
CONTAINER_NAME=""
for name in "Timeclock-D-Best" "timeclock" "timesmart"; do
    if docker ps --format '{{.Names}}' | grep -q "^${name}$"; then
        CONTAINER_NAME="$name"
        break
    fi
done

if [[ -z "$CONTAINER_NAME" ]]; then
    CONTAINER_NAME=$(docker ps --format '{{.Names}}' | grep -i timeclock | head -1)
fi

if [[ -z "$CONTAINER_NAME" ]]; then
    echo "$LOG_PREFIX $(date '+%Y-%m-%d %H:%M:%S') - ERROR: Could not find TimeClock container"
    exit 1
fi

echo "$LOG_PREFIX $(date '+%Y-%m-%d %H:%M:%S') - Starting scheduled clock-out process"
echo "$LOG_PREFIX Using container: $CONTAINER_NAME"

docker exec "$CONTAINER_NAME" php /var/www/html/scripts/auto_clockout_scheduled.php

EXIT_CODE=$?

if [[ $EXIT_CODE -eq 0 ]]; then
    echo "$LOG_PREFIX $(date '+%Y-%m-%d %H:%M:%S') - Scheduled clock-out completed successfully"
else
    echo "$LOG_PREFIX $(date '+%Y-%m-%d %H:%M:%S') - Scheduled clock-out failed with exit code: $EXIT_CODE"
fi

exit $EXIT_CODE
