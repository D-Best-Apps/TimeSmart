-- Migration: widen punch_changelog OldValue/NewValue from VARCHAR(10) to TEXT
--
-- These columns were too small for the values save_punches.php actually writes:
--   * NewValue receives the adjustment reason (e.g. "Time correction",
--     "Forgot to punch") — every reason-dropdown option is >10 chars.
--   * OldValue receives json_encode() of the whole punch row on deletions.
-- With STRICT_TRANS_TABLES + mysqli's default exception mode (PHP 8.1+), an
-- over-length insert threw "Data too long", rolling back the entire save so
-- the admin "Save Changes" appeared to do nothing (redirected to
-- ?error=exception, which the page renders with no banner).
--
-- Safe to run on any install — only widens columns, no data loss.

ALTER TABLE `punch_changelog`
  MODIFY COLUMN `OldValue` TEXT DEFAULT NULL,
  MODIFY COLUMN `NewValue` TEXT DEFAULT NULL;
