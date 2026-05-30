-- Migration: per-office IP allowlist.
--
-- Each office can list IPs/CIDRs that punches are allowed from. Enforced only
-- when the global "Restrict punches by office IP" setting (RestrictByIP) is on.
-- An office with a blank list is unrestricted (e.g. an "Overseas" office whose
-- staff punch from anywhere).

ALTER TABLE `Offices`
  ADD COLUMN IF NOT EXISTS `AllowedIPs` TEXT DEFAULT NULL;
