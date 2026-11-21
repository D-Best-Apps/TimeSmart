<?php
/**
 * functions/employee_number.php
 *
 * The payroll employee number (users.EmployeeNumber, set on Add/Edit User) for exports.
 */

/**
 * [user ID => employee number] for spreadsheets and CSV files. Blank when none is set,
 * or when it's the same as the employee's kiosk PIN: a PIN must never end up in a
 * file that gets emailed to payroll. [] before the EmployeeNumber migration.
 */
function employeeExportNumbers(mysqli $conn): array
{
    // Archiving moves the PIN to ArchivedPIN; check that one too.
    try {
        $res = $conn->query("SELECT ID, EmployeeNumber, PIN, ArchivedPIN FROM users");
    } catch (mysqli_sql_exception $e) {
        try {
            $res = $conn->query("SELECT ID, EmployeeNumber, PIN, NULL AS ArchivedPIN FROM users");
        } catch (mysqli_sql_exception $e) {
            return [];
        }
    }
    $out = [];
    while ($u = $res->fetch_assoc()) {
        $num  = trim((string) ($u['EmployeeNumber'] ?? ''));
        $pins = [trim((string) ($u['PIN'] ?? '')), trim((string) ($u['ArchivedPIN'] ?? ''))];
        $out[(int) $u['ID']] = ($num !== '' && !in_array($num, $pins, true)) ? $num : '';
    }
    return $out;
}
