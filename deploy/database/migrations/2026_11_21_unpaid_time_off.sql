-- Migration: admins can record time off and absences for an employee (admin/record_time_off.php).
--   time_off_requests.Category gains 'Unpaid': an excused, unpaid absence. It pays 0 hours,
--   but the day counts as accounted for (no no-show flag on the Home board) and the
--   reason is kept in Notes.
-- Safe to run on existing installs; new installs receive this via timeclock-schema.sql.

ALTER TABLE `time_off_requests`
  MODIFY COLUMN `Category` enum('Sick','PTO','Unpaid') NOT NULL;
