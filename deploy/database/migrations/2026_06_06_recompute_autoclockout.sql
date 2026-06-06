-- Fix TotalHours corrupted by the old auto_clockout datetime bug, which stored
-- 0.00 on forced-out rows even though real hours were worked. Scope is precise:
-- Note LIKE '%Auto-clocked out%' AND TotalHours = 0 (never touches manually
-- corrected rows, which have non-zero stored values). Idempotent + no-op on fresh.

-- 1. Audit log for the computable rows (capture old value 0 before recompute)
INSERT INTO punch_changelog (EmployeeID, Date, ChangedBy, FieldChanged, OldValue, NewValue, Reason)
SELECT EmployeeID, Date, 'SYSTEM-migration', 'TotalHours', '0.00',
       CAST(ROUND(GREATEST(
           (TIME_TO_SEC(TimeOut) - TIME_TO_SEC(TimeIN)
            - IF(LunchStart IS NOT NULL AND LunchEnd IS NOT NULL AND TIME_TO_SEC(LunchEnd) > TIME_TO_SEC(LunchStart),
                 TIME_TO_SEC(LunchEnd) - TIME_TO_SEC(LunchStart), 0)
           ) / 3600, 0), 2) AS CHAR),
       'Recompute corrupted auto-clockout hours (datetime bug fix)'
FROM timepunches
WHERE Note LIKE '%Auto-clocked out%' AND TotalHours = 0
  AND TimeIN IS NOT NULL AND TimeOut IS NOT NULL
  AND NOT (LunchStart IS NOT NULL AND LunchEnd IS NULL);

-- 2. Recompute the computable corrupted rows (same-day; forced-out is always 17:00 > clock-in)
UPDATE timepunches
SET TotalHours = ROUND(GREATEST(
    (TIME_TO_SEC(TimeOut) - TIME_TO_SEC(TimeIN)
     - IF(LunchStart IS NOT NULL AND LunchEnd IS NOT NULL AND TIME_TO_SEC(LunchEnd) > TIME_TO_SEC(LunchStart),
          TIME_TO_SEC(LunchEnd) - TIME_TO_SEC(LunchStart), 0)
    ) / 3600, 0), 2)
WHERE Note LIKE '%Auto-clocked out%' AND TotalHours = 0
  AND TimeIN IS NOT NULL AND TimeOut IS NOT NULL
  AND NOT (LunchStart IS NOT NULL AND LunchEnd IS NULL);

-- 3. Incomplete (open-lunch) corrupted rows: flag for manual time entry, never guess.
--    Queue a review item first (while TotalHours still = 0 identifies them)...
INSERT INTO pending_edits (EmployeeID, Date, TimeOut, Note, Reason, Source, Status, SubmittedAt)
SELECT EmployeeID, Date, TimeOut,
       'Incomplete punch (open lunch) auto-closed at 5:00 PM — needs time entry',
       'Incomplete punch — needs time entry', 'auto_clockout', 'Pending', NOW()
FROM timepunches
WHERE Note LIKE '%Auto-clocked out%' AND TotalHours = 0
  AND TimeIN IS NOT NULL AND TimeOut IS NOT NULL
  AND LunchStart IS NOT NULL AND LunchEnd IS NULL;

-- 4. ...then null out their hours (unknown until a human fills in the missing lunch end)
UPDATE timepunches
SET TotalHours = NULL
WHERE Note LIKE '%Auto-clocked out%' AND TotalHours = 0
  AND TimeIN IS NOT NULL AND TimeOut IS NOT NULL
  AND LunchStart IS NOT NULL AND LunchEnd IS NULL;
