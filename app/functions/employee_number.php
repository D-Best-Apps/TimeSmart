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
    try {
        $res = $conn->query("SELECT ID, EmployeeNumber, PIN FROM users");
    } catch (mysqli_sql_exception $e) {
        return [];
    }
    $out = [];
    while ($u = $res->fetch_assoc()) {
        $num = trim((string) ($u['EmployeeNumber'] ?? ''));
        $pin = trim((string) ($u['PIN'] ?? ''));
        $out[(int) $u['ID']] = ($num !== '' && $num !== $pin) ? $num : '';
    }
    return $out;
}
