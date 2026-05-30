-- Migration: admin-only "Private Note" on timesheet-edit and time-off requests.
--
-- Private notes are NEVER shown to or emailed to employees. Visibility is gated
-- purely by role: only super_admins can view/write them (reports_only admins
-- cannot reach the approval pages, and employees have no admin access).

ALTER TABLE `pending_edits`
  ADD COLUMN IF NOT EXISTS `AdminPrivateNote` TEXT DEFAULT NULL AFTER `ReviewedBy`;

ALTER TABLE `time_off_requests`
  ADD COLUMN IF NOT EXISTS `AdminPrivateNote` VARCHAR(500) DEFAULT NULL AFTER `ReviewNote`;
