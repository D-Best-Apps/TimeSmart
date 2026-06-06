-- Add a Source flag to pending_edits so system-generated review items
-- (auto-clockout forced-outs / incomplete punches) can be surfaced separately
-- from employee-requested time edits.
ALTER TABLE `pending_edits`
  ADD COLUMN IF NOT EXISTS `Source` VARCHAR(32) NOT NULL DEFAULT 'employee' AFTER `Reason`;
