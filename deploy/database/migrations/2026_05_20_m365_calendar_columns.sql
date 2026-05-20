-- Migration: M365 calendar sync state on time_off_requests
-- Adds columns used by app/admin/process_time_off.php and app/functions/m365_calendar.php
-- to record whether an approved request was pushed to the shared M365 PTO calendar.
-- Idempotent guards are not native to MariaDB ALTER, so this is safe to run once.

ALTER TABLE `time_off_requests`
  ADD COLUMN IF NOT EXISTS `M365EventId` VARCHAR(128) DEFAULT NULL AFTER `ReviewNote`,
  ADD COLUMN IF NOT EXISTS `M365SyncStatus` VARCHAR(255) DEFAULT NULL AFTER `M365EventId`,
  ADD COLUMN IF NOT EXISTS `M365SyncAt` DATETIME DEFAULT NULL AFTER `M365SyncStatus`;
