<?php
/**
 * Permission Check Function
 * Checks if the current admin has permission to access a specific feature
 *
 * Roles:
 * - super_admin: Full access to all features
 * - reports_only: Can view and export reports only
 */

/**
 * Check if current admin has the required permission
 *
 * @param string $required_permission The permission required (e.g., 'edit_timesheets', 'manage_users')
 * @return bool True if admin has permission, false otherwise
 */
function checkPermission($required_permission) {
    // Must be logged in as admin
    if (!isset($_SESSION['admin']) || !isset($_SESSION['admin_role'])) {
        return false;
    }

    $role = $_SESSION['admin_role'];

    // Super admins have access to everything
    if ($role === 'super_admin') {
        return true;
    }

    // Reports-only admins can only access report viewing/exporting
    if ($role === 'reports_only') {
        $allowed_permissions = [
            'view_reports',
            'export_reports',
            'view_dashboard'
        ];
        return in_array($required_permission, $allowed_permissions);
    }

    // Unknown role, deny access
    return false;
}

/**
 * Require permission or redirect to dashboard with error
 *
 * @param string $required_permission The permission required
 * @param string $redirect_url Optional custom redirect URL (default: dashboard.php)
 */
function requirePermission($required_permission, $redirect_url = 'dashboard.php') {
    if (!checkPermission($required_permission)) {
        $error_message = urlencode('You do not have permission to access this page.');
        header("Location: {$redirect_url}?error=" . $error_message);
        exit;
    }
}

/**
 * Get the current admin's role
 *
 * @return string|null The role or null if not logged in
 */
function getAdminRole() {
    return $_SESSION['admin_role'] ?? null;
}

/**
 * Check if current admin is a super admin
 *
 * @return bool True if super admin
 */
function isSuperAdmin() {
    return getAdminRole() === 'super_admin';
}

/**
 * Check if current admin is a reports-only admin
 *
 * @return bool True if reports-only admin
 */
function isReportsOnly() {
    return getAdminRole() === 'reports_only';
}
