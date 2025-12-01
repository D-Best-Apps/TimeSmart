-- Migration script to add role column to admins table
-- Run this on existing installations to add role-based access control
-- Date: 2025-12-01

-- Add role column to admins table
ALTER TABLE `admins`
ADD COLUMN `role` varchar(20) NOT NULL DEFAULT 'super_admin' AFTER `password`;

-- Update all existing admins to super_admin role
UPDATE `admins` SET `role` = 'super_admin' WHERE `role` = 'super_admin';

-- Verify the change
SELECT id, username, role FROM `admins`;
