-- Migration: unify admin + employee logins under the users table.
-- Adds users.Role and migrates the admins table contents into matching users
-- rows (matched by first name → users.FirstName, case-insensitive).
-- The admin's password / 2FA settings win for matched rows since that's what
-- they actively use on the admin portal today. Unmatched admins are left in
-- the admins table for now (we keep it as a safety net for this cycle).

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `Role`
    ENUM('employee','reports_only','super_admin') NOT NULL DEFAULT 'employee'
    AFTER `LockOut`;

-- Copy each admin row onto the matching user row.
-- Match heuristic: admins.username (case-insensitive) = users.FirstName,
-- OR users.FirstName starts with admins.username, OR admins.username starts with users.FirstName.
-- Adjust manually if your naming differs from this assumption.
UPDATE `users` u
JOIN `admins` a
  ON (LOWER(a.username) = LOWER(u.FirstName)
   OR LOWER(u.FirstName) LIKE CONCAT(LOWER(a.username), '%')
   OR LOWER(a.username) LIKE CONCAT(LOWER(u.FirstName), '%'))
SET
  u.Role              = a.role,
  u.Pass              = a.password,
  u.TwoFASecret       = COALESCE(a.TwoFASecret, u.TwoFASecret),
  u.TwoFAEnabled      = a.TwoFAEnabled,
  u.TwoFARecoveryCode = COALESCE(a.TwoFARecoveryCode, u.TwoFARecoveryCode);

-- Audit: show the result so it can be eyeballed
SELECT u.ID, u.FirstName, u.LastName, u.Role, u.TwoFAEnabled
  FROM users u
 WHERE u.Role <> 'employee'
 ORDER BY u.LastName, u.FirstName;
