-- Backfill stored TotalHours for historical rows that were never persisted
-- (pre-Sept-2025 the app computed hours on the fly and left the column NULL).
-- Formula is byte-for-byte identical to the canonical PHP calculateTotalHours()
-- (verified against all complete same-day rows). Only touches rows with both
-- punches present; incomplete rows stay NULL ("open/unknown"). Idempotent: the
-- TotalHours IS NULL predicate makes re-runs no-ops. Safe no-op on fresh installs.

-- Same-day shifts
UPDATE timepunches
SET TotalHours = ROUND(GREATEST(
    (TIME_TO_SEC(TimeOut) - TIME_TO_SEC(TimeIN)
     - IF(LunchStart IS NOT NULL AND LunchEnd IS NOT NULL AND TIME_TO_SEC(LunchEnd) > TIME_TO_SEC(LunchStart),
          TIME_TO_SEC(LunchEnd) - TIME_TO_SEC(LunchStart), 0)
    ) / 3600, 0), 2)
WHERE TotalHours IS NULL
  AND TimeIN IS NOT NULL AND TimeOut IS NOT NULL
  AND TimeOut >= TimeIN;

-- Overnight shifts (clock-out earlier than clock-in), capped at 16h to skip garbage
UPDATE timepunches
SET TotalHours = ROUND(GREATEST(
    (TIME_TO_SEC(TimeOut) + 86400 - TIME_TO_SEC(TimeIN)
     - IF(LunchStart IS NOT NULL AND LunchEnd IS NOT NULL AND TIME_TO_SEC(LunchEnd) > TIME_TO_SEC(LunchStart),
          TIME_TO_SEC(LunchEnd) - TIME_TO_SEC(LunchStart), 0)
    ) / 3600, 0), 2)
WHERE TotalHours IS NULL
  AND TimeIN IS NOT NULL AND TimeOut IS NOT NULL
  AND TimeOut < TimeIN
  AND (TIME_TO_SEC(TimeOut) + 86400 - TIME_TO_SEC(TimeIN)) <= 16 * 3600;
