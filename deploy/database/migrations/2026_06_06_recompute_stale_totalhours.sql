-- Repair stored TotalHours that drifted from the punch times. Root cause:
-- approved pending_edits applied a new time (process_edits.php) without
-- recomputing hours, leaving stale values. Worked hours were ALWAYS derived
-- from times (no manual-override path exists), so recomputing every currently
-- non-NULL complete row from times repairs the stale ones and is a no-op on the
-- already-correct ones. The `TotalHours IS NOT NULL` guard preserves rows we
-- deliberately left NULL (open-lunch needs-entry like #4003, and garbage rows).
-- Idempotent; safe no-op on fresh installs.

-- 1. Audit: log rows whose stored value actually changes (>0.005h)
INSERT INTO punch_changelog (EmployeeID, Date, ChangedBy, FieldChanged, OldValue, NewValue, Reason)
SELECT EmployeeID, Date, 'SYSTEM-migration', 'TotalHours',
       CAST(TotalHours AS CHAR),
       CAST(ROUND(GREATEST(
           (TIME_TO_SEC(TimeOut) - TIME_TO_SEC(TimeIN)
            - IF(LunchStart IS NOT NULL AND LunchEnd IS NOT NULL AND TIME_TO_SEC(LunchEnd) > TIME_TO_SEC(LunchStart),
                 TIME_TO_SEC(LunchEnd) - TIME_TO_SEC(LunchStart), 0)
           ) / 3600, 0), 2) AS CHAR),
       'Recompute stale hours (approved time edit applied without hours recompute)'
FROM timepunches
WHERE TotalHours IS NOT NULL AND TimeIN IS NOT NULL AND TimeOut IS NOT NULL AND TimeOut >= TimeIN
  AND ABS(TotalHours - ROUND(GREATEST(
        (TIME_TO_SEC(TimeOut) - TIME_TO_SEC(TimeIN)
         - IF(LunchStart IS NOT NULL AND LunchEnd IS NOT NULL AND TIME_TO_SEC(LunchEnd) > TIME_TO_SEC(LunchStart),
              TIME_TO_SEC(LunchEnd) - TIME_TO_SEC(LunchStart), 0)
        ) / 3600, 0), 2)) > 0.005;

-- 2. Same-day rows: recompute from times
UPDATE timepunches
SET TotalHours = ROUND(GREATEST(
    (TIME_TO_SEC(TimeOut) - TIME_TO_SEC(TimeIN)
     - IF(LunchStart IS NOT NULL AND LunchEnd IS NOT NULL AND TIME_TO_SEC(LunchEnd) > TIME_TO_SEC(LunchStart),
          TIME_TO_SEC(LunchEnd) - TIME_TO_SEC(LunchStart), 0)
    ) / 3600, 0), 2)
WHERE TotalHours IS NOT NULL
  AND TimeIN IS NOT NULL AND TimeOut IS NOT NULL AND TimeOut >= TimeIN;

-- 3. Overnight rows (capped at 16h): recompute with +24h roll-over
UPDATE timepunches
SET TotalHours = ROUND(GREATEST(
    (TIME_TO_SEC(TimeOut) + 86400 - TIME_TO_SEC(TimeIN)
     - IF(LunchStart IS NOT NULL AND LunchEnd IS NOT NULL AND TIME_TO_SEC(LunchEnd) > TIME_TO_SEC(LunchStart),
          TIME_TO_SEC(LunchEnd) - TIME_TO_SEC(LunchStart), 0)
    ) / 3600, 0), 2)
WHERE TotalHours IS NOT NULL
  AND TimeIN IS NOT NULL AND TimeOut IS NOT NULL AND TimeOut < TimeIN
  AND (TIME_TO_SEC(TimeOut) + 86400 - TIME_TO_SEC(TimeIN)) <= 16 * 3600;
