#!/bin/bash
#
# Auto Lunch Wrapper Script
#
# Executes the auto lunch PHP script inside the Docker container.
# Pass "out" or "in" as the first argument.
#
# Usage:
#   ./auto_lunch_wrapper.sh out   (lunch clock-out)
#   ./auto_lunch_wrapper.sh in    (lunch clock-in)
#
# Cron:
#   0 11 * * 1-5  /path/to/auto_lunch_wrapper.sh out >> /var/log/timeclock-auto-lunch.log 2>&1
#   45 11 * * 1-5 /path/to/auto_lunch_wrapper.sh in  >> /var/log/timeclock-auto-lunch.log 2>&1
#

ACTION="${1:-}"
LOG_PREFIX="[Auto Lunch Wrapper]"

if [[ "$ACTION" != "out" && "$ACTION" != "in" ]]; then
    echo "$LOG_PREFIX ERROR: Must pass 'out' or 'in' as argument"
    exit 1
fi

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

if [[ -z "$CONTAINER_NAME" ]]; then
    CONTAINER_NAME=$(docker ps --format '{{.Names}}' | grep -i timeclock | head -1)
fi

if [[ -z "$CONTAINER_NAME" ]]; then
    echo "$LOG_PREFIX $(date '+%Y-%m-%d %H:%M:%S') - ERROR: Could not find TimeClock container"
    exit 1
fi

echo "$LOG_PREFIX $(date '+%Y-%m-%d %H:%M:%S') - Starting auto lunch-$ACTION process"
echo "$LOG_PREFIX Using container: $CONTAINER_NAME"

docker exec "$CONTAINER_NAME" php /var/www/html/scripts/auto_lunch.php "$ACTION"

EXIT_CODE=$?

if [[ $EXIT_CODE -eq 0 ]]; then
    echo "$LOG_PREFIX $(date '+%Y-%m-%d %H:%M:%S') - Auto lunch-$ACTION completed successfully"
else
    echo "$LOG_PREFIX $(date '+%Y-%m-%d %H:%M:%S') - Auto lunch-$ACTION failed with exit code: $EXIT_CODE"
fi

exit $EXIT_CODE
