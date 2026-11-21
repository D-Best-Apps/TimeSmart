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

/**
 * Adds an "Employee #" column beside the name column of a spreadsheet export.
 *
 * The export builds its sheets as before and only notes its header rows and which
 * employee each data row belongs to; apply() then inserts the column, shifting every
 * later column (with its styles, merges and widths) one place right. The new cells take
 * the name column's style, so header colours carry over. When no employee in the file
 * has a number the column is left out. Numbers are written as text: 00123 stays 00123.
 */
final class EmployeeNumberColumn
{
    private array $nums;
    /** spl_object_id => ['sheet' => Worksheet, 'headers' => int[], 'rows' => [row => employee ID]] */
    private array $sheets = [];

    public function __construct(mysqli $conn)
    {
        $this->nums = employeeExportNumbers($conn);
    }

    public function header(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $row): void
    {
        $this->track($sheet);
        $this->sheets[spl_object_id($sheet)]['headers'][] = $row;
    }

    public function row(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet, int $row, int $employeeId): void
    {
        $this->track($sheet);
        $this->sheets[spl_object_id($sheet)]['rows'][$row] = $employeeId;
    }

    private function track(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet): void
    {
        $this->sheets[spl_object_id($sheet)] ??= ['sheet' => $sheet, 'headers' => [], 'rows' => []];
    }

    /** Insert the column right after $nameColumn (e.g. 'A') on every sheet noted. */
    public function apply(string $nameColumn): void
    {
        $any = false;
        foreach ($this->sheets as $s) {
            foreach ($s['rows'] as $emp) {
                if (($this->nums[$emp] ?? '') !== '') { $any = true; break 2; }
            }
        }
        if (!$any) {
            return;
        }
        $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::class;
        $col   = $coord::stringFromColumnIndex($coord::columnIndexFromString($nameColumn) + 1);
        foreach ($this->sheets as $s) {
            $sheet = $s['sheet'];
            $sheet->insertNewColumnBefore($col, 1);
            foreach ($s['headers'] as $r) {
                $sheet->setCellValue("{$col}{$r}", 'Employee #');
            }
            foreach ($s['rows'] as $r => $emp) {
                $sheet->setCellValueExplicit("{$col}{$r}", $this->nums[$emp] ?? '',
                    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
    }
}
