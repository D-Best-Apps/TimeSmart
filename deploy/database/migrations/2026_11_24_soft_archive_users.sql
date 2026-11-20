-- Migration: archiving an employee keeps their history.
-- Archive used to copy the user row into `user-archive` and DELETE the employee's punches,
-- login log, pending edits and punch changelog, then the user row (which cascaded to their
-- time off and holiday-pay adjustments), so past periods' hours changed. Archived employees
-- now stay in `users` with ArchivedAt set: they can't sign in or punch and drop out of
-- active lists, while reports for dates they worked still show them.
--   users.ArchivedAt       when the employee was archived; NULL = active
--   users.ArchivedBadgeID  badge ID and PIN held while archived, so the unique BadgeID/PIN
--   users.ArchivedPIN      can be reused for someone else; put back on unarchive if free
-- Rows archived the old way move back into `users` as archived (their punches and time off
-- were already deleted and can't be recovered). The old `user-archive` table is left as it
-- was and is no longer used; drop it by hand once you're happy.
-- Safe to run on existing installs; new installs receive this via timeclock-schema.sql.

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `ArchivedAt` datetime DEFAULT NULL AFTER `HolidayHours`,
  ADD COLUMN IF NOT EXISTS `ArchivedBadgeID` varchar(50) DEFAULT NULL AFTER `ArchivedAt`,
  ADD COLUMN IF NOT EXISTS `ArchivedPIN` varchar(6) DEFAULT NULL AFTER `ArchivedBadgeID`;
ALTER TABLE `users`
  ADD KEY IF NOT EXISTS `ArchivedAt` (`ArchivedAt`);

-- Old archive rows whose ID is still free keep their ID
INSERT INTO `users` (ID, LastName, Email, FirstName, Pass, ProfilePhoto, ClockStatus, Office,
                     TwoFASecret, TwoFAEnabled, RecoveryCodeHash, AdminOverride2FA, JobTitle,
                     PhoneNumber, ThemePref, TwoFARecoveryCode, LockOut,
                     ArchivedAt, ArchivedBadgeID, ArchivedPIN)
SELECT a.ID, a.LastName, a.Email, a.FirstName, a.Pass, a.ProfilePhoto, 'Out', a.Office,
       a.TwoFASecret, a.TwoFAEnabled, a.RecoveryCodeHash, a.AdminOverride2FA, a.JobTitle,
       a.PhoneNumber, a.ThemePref, a.TwoFARecoveryCode, a.LockOut,
       a.ArchivedAt, NULLIF(a.BadgeID, ''), NULLIF(a.PIN, '')
  FROM `user-archive` a
 WHERE NOT EXISTS (SELECT 1 FROM `users` u WHERE u.ID = a.ID);

-- An ID given to someone else since (AUTO_INCREMENT can drop back after a server restart):
-- the archived employee gets a new ID (they have no punches left to point at the old one)
INSERT INTO `users` (LastName, Email, FirstName, Pass, ProfilePhoto, ClockStatus, Office,
                     TwoFASecret, TwoFAEnabled, RecoveryCodeHash, AdminOverride2FA, JobTitle,
                     PhoneNumber, ThemePref, TwoFARecoveryCode, LockOut,
                     ArchivedAt, ArchivedBadgeID, ArchivedPIN)
SELECT a.LastName, a.Email, a.FirstName, a.Pass, a.ProfilePhoto, 'Out', a.Office,
       a.TwoFASecret, a.TwoFAEnabled, a.RecoveryCodeHash, a.AdminOverride2FA, a.JobTitle,
       a.PhoneNumber, a.ThemePref, a.TwoFARecoveryCode, a.LockOut,
       a.ArchivedAt, NULLIF(a.BadgeID, ''), NULLIF(a.PIN, '')
  FROM `user-archive` a
 WHERE NOT EXISTS (SELECT 1 FROM `users` u
                    WHERE u.FirstName = a.FirstName AND u.LastName = a.LastName
                      AND u.ArchivedAt = a.ArchivedAt);
