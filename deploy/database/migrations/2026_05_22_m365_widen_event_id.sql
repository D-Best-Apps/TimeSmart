-- Migration: widen M365EventId column to fit real Graph event IDs
-- Microsoft Graph event IDs are ~150 chars; the original column was VARCHAR(128).
-- Safe to run on any install — only changes column size, no data loss.

ALTER TABLE `time_off_requests`
  MODIFY COLUMN `M365EventId` VARCHAR(255) DEFAULT NULL;
