-- Migration: configurable notification recipients
-- Until now, admin notifications went to exactly one address (the mail_admin_address
-- setting), so a second admin could never be notified. These columns let each admin
-- opt in per notification type, configured in app/admin/settings.php and resolved by
-- app/functions/notify_recipients.php.
-- Safe to run on existing installs; new installs receive this via timeclock-schema.sql.

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `NotifyTimeOff` TINYINT(1) NOT NULL DEFAULT 0 AFTER `Role`,
  ADD COLUMN IF NOT EXISTS `NotifyTimesheetEdits` TINYINT(1) NOT NULL DEFAULT 0 AFTER `NotifyTimeOff`;

-- Backfill: opt every super_admin with an address on file into both notification
-- types. Without this the upgrade would silently stop all notifications.
UPDATE `users`
   SET `NotifyTimeOff` = 1, `NotifyTimesheetEdits` = 1
 WHERE `Role` = 'super_admin'
   AND `Email` IS NOT NULL AND `Email` <> '';

-- Preserve shared-mailbox installs: if mail_admin_address points somewhere that is
-- not a user account (hr@, payroll@), carry it into the extra-addresses fields so it
-- keeps receiving mail. If it matches a user, the checkbox above already covers it.
INSERT INTO `settings` (`SettingKey`, `SettingValue`)
SELECT * FROM (
    SELECT 'notify_timeoff_extra' AS `k`, `s`.`SettingValue` AS `v`
      FROM `settings` `s`
     WHERE `s`.`SettingKey` = 'mail_admin_address'
       AND COALESCE(`s`.`SettingValue`, '') <> ''
       AND NOT EXISTS (SELECT 1 FROM `users` `u` WHERE LOWER(`u`.`Email`) = LOWER(`s`.`SettingValue`))
    UNION ALL
    SELECT 'notify_timesheet_edits_extra', `s`.`SettingValue`
      FROM `settings` `s`
     WHERE `s`.`SettingKey` = 'mail_admin_address'
       AND COALESCE(`s`.`SettingValue`, '') <> ''
       AND NOT EXISTS (SELECT 1 FROM `users` `u` WHERE LOWER(`u`.`Email`) = LOWER(`s`.`SettingValue`))
) AS `seed`
ON DUPLICATE KEY UPDATE `SettingValue` = VALUES(`SettingValue`);
