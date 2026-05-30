-- Migration: email-based two-factor login codes.
--
-- When users.TwoFAEnabled = 1, login emails the user a short-lived 6-digit code
-- (hashed at rest, with an expiry) that they enter on the verification page.
-- This replaces the never-finished authenticator/TOTP path. Backup recovery
-- codes (users.TwoFARecoveryCode) remain a fallback. The legacy users.TwoFASecret
-- column is no longer used.

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `EmailOTPHash` VARCHAR(255) DEFAULT NULL AFTER `TwoFASecret`;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `EmailOTPExpires` DATETIME DEFAULT NULL AFTER `EmailOTPHash`;
