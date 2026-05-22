-- Migration: amendment flow for approved time-off requests
-- Employees can submit edits to Approved requests; admin reviews and approves
-- the amendment, which updates the original row and replaces the M365 event.
-- The Pending edit case writes directly to the existing row and needs no
-- schema changes; this migration supports the Approved-amendment audit trail.

ALTER TABLE `time_off_requests`
  ADD COLUMN IF NOT EXISTS `Reason` VARCHAR(500) DEFAULT NULL AFTER `Notes`,
  ADD COLUMN IF NOT EXISTS `AmendsRequestID` INT DEFAULT NULL AFTER `ReviewNote`;

ALTER TABLE `time_off_requests`
  ADD INDEX IF NOT EXISTS `idx_tor_amends` (`AmendsRequestID`);
