-- Repair rows corrupted by a missed lunch-end punch: the interactive clock-out
-- used to backfill LunchEnd = TimeOut, turning an unfinished lunch into an
-- implausible multi-hour "lunch" that halved worked hours (e.g. Jacob 2026-05-29:
-- lunch 11:09->16:40 = 5.5h, Total 4.34h vs ~8.8h real).
-- Scope: LunchEnd = TimeOut AND implied lunch > 2h. Null the bogus lunch-end +
-- hours and queue a review item so a human enters the real lunch window.
-- Idempotent (after repair, LunchEnd is NULL so nothing re-matches); no-op on fresh.

-- 1. Audit log
INSERT INTO punch_changelog (EmployeeID, Date, ChangedBy, FieldChanged, OldValue, NewValue, Reason)
SELECT EmployeeID, Date, 'SYSTEM-migration', 'LunchEnd', CAST(LunchEnd AS CHAR), 'NULL',
       'Missed lunch-end backfilled to clock-out; cleared for manual time entry'
FROM timepunches
WHERE LunchStart IS NOT NULL AND LunchEnd IS NOT NULL AND TimeOut IS NOT NULL
  AND LunchEnd = TimeOut AND TIME_TO_SEC(TIMEDIFF(LunchEnd, LunchStart)) > 2 * 3600;

-- 2. Queue a review item for each (while LunchEnd = TimeOut still identifies them)
INSERT INTO pending_edits (EmployeeID, Date, TimeOut, Note, Reason, Source, Status, SubmittedAt)
SELECT EmployeeID, Date, TimeOut,
       'Missed lunch-end punch (lunch was backfilled to clock-out) — needs time entry',
       'Missed lunch-end — needs time entry', 'incomplete_lunch', 'Pending', NOW()
FROM timepunches
WHERE LunchStart IS NOT NULL AND LunchEnd IS NOT NULL AND TimeOut IS NOT NULL
  AND LunchEnd = TimeOut AND TIME_TO_SEC(TIMEDIFF(LunchEnd, LunchStart)) > 2 * 3600;

-- 3. Clear the bogus lunch-end + hours (row becomes an incomplete punch needing entry)
UPDATE timepunches
SET LunchEnd = NULL, TotalHours = NULL
WHERE LunchStart IS NOT NULL AND LunchEnd IS NOT NULL AND TimeOut IS NOT NULL
  AND LunchEnd = TimeOut AND TIME_TO_SEC(TIMEDIFF(LunchEnd, LunchStart)) > 2 * 3600;
