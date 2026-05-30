<?php
// functions/password_policy.php
//
// Centralized password complexity policy. The active mode is controlled by the
// 'PasswordSecurityMode' row in the settings table:
//   'high' — for online installs: >= 7 chars and 3 of 4 character classes
//   'low'  — for offline / air-gapped installs: >= 2 chars, any characters
//
// Defaults to 'low' when the setting is unset so existing and air-gapped
// installs keep working without a forced migration.

require_once __DIR__ . '/settings_helper.php';

/**
 * Returns the active password security mode: 'high' or 'low'.
 */
function getPasswordMode($conn) {
    $mode = getSettingValue('PasswordSecurityMode', $conn);
    return ($mode === 'high') ? 'high' : 'low';
}

/**
 * Validates a plaintext password against the active policy.
 *
 * @return string[] Human-readable error messages. Empty array means valid.
 */
function validatePassword($password, $conn, $mode = null) {
    $mode = $mode ?? getPasswordMode($conn);
    $errors = [];

    if ($mode === 'high') {
        if (strlen($password) < 7) {
            $errors[] = "Password must be at least 7 characters.";
        }
        $classes = 0;
        if (preg_match('/[a-z]/', $password))        $classes++;
        if (preg_match('/[A-Z]/', $password))        $classes++;
        if (preg_match('/[0-9]/', $password))        $classes++;
        if (preg_match('/[^a-zA-Z0-9]/', $password)) $classes++;
        if ($classes < 3) {
            $errors[] = "Password must include at least 3 of: uppercase letter, lowercase letter, number, symbol.";
        }
    } else {
        if (strlen($password) < 2) {
            $errors[] = "Password must be at least 2 characters.";
        }
    }

    return $errors;
}

/**
 * Short, human-readable description of the current requirements, for form hints.
 */
function passwordRequirementsText($conn, $mode = null) {
    $mode = $mode ?? getPasswordMode($conn);
    if ($mode === 'high') {
        return "At least 7 characters, including 3 of: uppercase, lowercase, number, symbol.";
    }
    return "At least 2 characters.";
}
