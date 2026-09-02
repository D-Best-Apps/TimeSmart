<?php
/**
 * Punch change history — the readable end of punch_changelog.
 *
 * Eight files write to that table and, until this page existed, nothing ever read
 * it: the audit trail for payroll was write-only. This shows who changed a punch,
 * when, from what to what, and why.
 *
 * Rows are grouped into "change sets" — one save writes several rows sharing a
 * ChangeTime and ChangedBy, and reading them as one edit is far closer to what
 * actually happened than a flat list of field mutations.
 */
require_once '../auth/db.php';
session_start();

if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit;
}

require_once __DIR__ . '/../functions/check_permission.php';
require_once __DIR__ . '/../functions/hours.php';
requirePermission('edit_timesheets');

date_default_timezone_set('America/Chicago');

const HISTORY_PER_PAGE = 25; // change sets, not rows

// ---------------------------------------------------------------- filters ----
$employeeID   = isset($_GET['emp']) && $_GET['emp'] !== '' ? (int) $_GET['emp'] : null;
$changedBy    = trim($_GET['by'] ?? '');
$hideSystem   = isset($_GET['hide_system']) && $_GET['hide_system'] === '1';
$page         = max(1, (int) ($_GET['p'] ?? 1));

// Default to the last 30 days of punch dates so the page opens on something useful.
$from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$to   = $_GET['to']   ?? date('Y-m-d');
$from = date('Y-m-d', strtotime($from));
$to   = date('Y-m-d', strtotime($to));

$where  = ['cl.Date BETWEEN ? AND ?'];
$params = [$from, $to];
$types  = 'ss';

if ($employeeID !== null) {
    $where[]  = 'cl.EmployeeID = ?';
    $params[] = $employeeID;
    $types   .= 'i';
}
if ($changedBy !== '') {
    $where[]  = 'cl.ChangedBy = ?';
    $params[] = $changedBy;
    $types   .= 's';
}
if ($hideSystem) {
    $where[] = "COALESCE(cl.ChangedBy, '') <> 'SYSTEM'";
}
$whereSql = implode(' AND ', $where);

$bind = function (mysqli_stmt $stmt, string $types, array $params): void {
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
};

// ------------------------------------------------------- page of change sets ----
$countStmt = $conn->prepare("
    SELECT COUNT(*) FROM (
        SELECT 1 FROM punch_changelog cl
        WHERE {$whereSql}
        GROUP BY cl.ChangeTime, COALESCE(cl.ChangedBy, '')
    ) t
");
$bind($countStmt, $types, $params);
$countStmt->execute();
$totalSets = (int) ($countStmt->get_result()->fetch_row()[0] ?? 0);
$countStmt->close();

$totalPages = max(1, (int) ceil($totalSets / HISTORY_PER_PAGE));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * HISTORY_PER_PAGE;

$setStmt = $conn->prepare("
    SELECT cl.ChangeTime, COALESCE(cl.ChangedBy, '') AS Who
    FROM punch_changelog cl
    WHERE {$whereSql}
    GROUP BY cl.ChangeTime, COALESCE(cl.ChangedBy, '')
    ORDER BY cl.ChangeTime DESC
    LIMIT ? OFFSET ?
");
$setParams = array_merge($params, [HISTORY_PER_PAGE, $offset]);
$bind($setStmt, $types . 'ii', $setParams);
$setStmt->execute();
$setKeys = [];
$res = $setStmt->get_result();
while ($row = $res->fetch_assoc()) {
    $setKeys[] = $row;
}
$setStmt->close();

// ------------------------------------------------- rows for those change sets ----
$sets = []; // "ChangeTime|Who" => ['when','who','rows'=>[]]
if (!empty($setKeys)) {
    $pairSql    = implode(' OR ', array_fill(0, count($setKeys), "(cl.ChangeTime = ? AND COALESCE(cl.ChangedBy, '') = ?)"));
    $rowParams  = $params;
    $rowTypes   = $types;
    foreach ($setKeys as $k) {
        $rowParams[] = $k['ChangeTime'];
        $rowParams[] = $k['Who'];
        $rowTypes   .= 'ss';
        $sets[$k['ChangeTime'] . '|' . $k['Who']] = [
            'when' => $k['ChangeTime'],
            'who'  => $k['Who'],
            'rows' => [],
        ];
    }

    $rowStmt = $conn->prepare("
        SELECT cl.ID, cl.EmployeeID, cl.Date, cl.ChangeTime, COALESCE(cl.ChangedBy, '') AS Who,
               cl.FieldChanged, cl.OldValue, cl.NewValue, cl.Reason,
               CONCAT(u.FirstName, ' ', u.LastName) AS EmployeeName
        FROM punch_changelog cl
        LEFT JOIN users u ON u.ID = cl.EmployeeID
        WHERE {$whereSql} AND ({$pairSql})
        ORDER BY cl.ChangeTime DESC, cl.Date, cl.ID
    ");
    $bind($rowStmt, $rowTypes, $rowParams);
    $rowStmt->execute();
    $res = $rowStmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $sets[$row['ChangeTime'] . '|' . $row['Who']]['rows'][] = $row;
    }
    $rowStmt->close();
}

// ------------------------------------------------------------- presentation ----
$fieldLabels = [
    'TimeIN'     => 'Clock In',
    'LunchStart' => 'Lunch Out',
    'LunchEnd'   => 'Lunch In',
    'TimeOut'    => 'Clock Out',
    'TimeOUT'    => 'Clock Out',
    'Note'       => 'Note',
    'TotalHours' => 'Total Hours',
];
$timeFields = ['TimeIN', 'LunchStart', 'LunchEnd', 'TimeOut', 'TimeOUT'];

/** Render one stored OldValue/NewValue for display. */
function historyValue(?string $value, string $field, array $timeFields): string {
    if ($value === null || $value === '' || $value === 'NULL') {
        return '<span class="ph-empty">—</span>';
    }
    if (in_array($field, $timeFields, true) && preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $value)) {
        return htmlspecialchars(date('g:i a', strtotime($value)));
    }
    if ($field === 'TotalHours' && is_numeric($value)) {
        return htmlspecialchars(number_format((float) $value, 2) . ' h');
    }
    return htmlspecialchars($value);
}

/** Summarise the JSON snapshot a deletion stores in OldValue. */
function describeDeletedPunch(?string $json): string {
    $p = json_decode((string) $json, true);
    if (!is_array($p)) {
        return '<span class="ph-empty">—</span>';
    }
    $t = fn($k) => !empty($p[$k]) ? date('g:i a', strtotime($p[$k])) : null;
    $bits = [];
    if ($t('TimeIN'))  { $bits[] = 'in ' . $t('TimeIN'); }
    if ($t('LunchStart') || $t('LunchEnd')) {
        $bits[] = 'lunch ' . ($t('LunchStart') ?? '?') . '–' . ($t('LunchEnd') ?? '?');
    }
    if ($t('TimeOut') ?? $t('TimeOUT')) { $bits[] = 'out ' . ($t('TimeOut') ?? $t('TimeOUT')); }
    if (isset($p['TotalHours']) && $p['TotalHours'] !== null) {
        $bits[] = number_format((float) $p['TotalHours'], 2) . ' h';
    }
    return $bits ? htmlspecialchars(implode(', ', $bits)) : '<span class="ph-empty">empty punch</span>';
}

$employeeList = $conn->query("SELECT ID, FirstName, LastName FROM users ORDER BY LastName, FirstName");
$editorList   = $conn->query("SELECT DISTINCT ChangedBy FROM punch_changelog WHERE ChangedBy IS NOT NULL AND ChangedBy <> '' ORDER BY ChangedBy");

/** Preserve the current filters across links. */
$qs = function (array $overrides = []) use ($employeeID, $from, $to, $changedBy, $hideSystem, $page) {
    return http_build_query(array_merge([
        'emp'         => $employeeID ?? '',
        'from'        => $from,
        'to'          => $to,
        'by'          => $changedBy,
        'hide_system' => $hideSystem ? '1' : '',
        'p'           => $page,
    ], $overrides));
};

$pageTitle = "Change History";
$extraCSS  = ["../css/view_punches.css?v=2", "../css/punch_history.css?v=1"];
require_once 'header.php';
?>

<div class="vp-card">
    <div class="vp-card-header">
        <h2>Timesheet Change History</h2>
        <div class="vp-actions">
            <a href="view_punches.php<?= $employeeID ? '?emp=' . (int) $employeeID . '&from=' . urlencode(date('m/d/Y', strtotime($from))) . '&to=' . urlencode(date('m/d/Y', strtotime($to))) : '' ?>" class="btn secondary">🕒 Back to Timesheets</a>
        </div>
    </div>

    <form method="GET" class="vp-filter">
        <div class="field">
            <label for="emp">Employee</label>
            <select name="emp" id="emp">
                <option value="">All employees</option>
                <?php while ($e = $employeeList->fetch_assoc()): ?>
                    <option value="<?= (int) $e['ID'] ?>" <?= $employeeID === (int) $e['ID'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($e['LastName'] . ', ' . $e['FirstName']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="field">
            <label for="from">Punch dates from</label>
            <input type="date" name="from" id="from" value="<?= htmlspecialchars($from) ?>">
        </div>
        <div class="field">
            <label for="to">to</label>
            <input type="date" name="to" id="to" value="<?= htmlspecialchars($to) ?>">
        </div>
        <div class="field">
            <label for="by">Changed by</label>
            <select name="by" id="by">
                <option value="">Anyone</option>
                <?php while ($a = $editorList->fetch_assoc()): ?>
                    <option value="<?= htmlspecialchars($a['ChangedBy']) ?>" <?= $changedBy === $a['ChangedBy'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($a['ChangedBy']) ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="field ph-checkbox">
            <label><input type="checkbox" name="hide_system" value="1" <?= $hideSystem ? 'checked' : '' ?>> Hide automatic changes</label>
        </div>
        <div class="vp-filter-buttons">
            <button type="submit" class="btn primary">Apply</button>
            <a href="punch_history.php" class="btn secondary">Reset</a>
        </div>
    </form>
</div>

<div class="vp-card">
    <div class="vp-card-header">
        <h2><?= number_format($totalSets) ?> change<?= $totalSets === 1 ? '' : 's' ?></h2>
        <?php if ($totalPages > 1): ?>
        <div class="vp-actions ph-pager">
            <?php if ($page > 1): ?><a class="btn secondary small" href="?<?= htmlspecialchars($qs(['p' => $page - 1])) ?>">‹ Newer</a><?php endif; ?>
            <span class="ph-pageno">Page <?= $page ?> of <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?><a class="btn secondary small" href="?<?= htmlspecialchars($qs(['p' => $page + 1])) ?>">Older ›</a><?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if (empty($sets)): ?>
        <p class="vp-empty">No changes recorded for these filters.</p>
    <?php else: ?>
        <?php foreach ($sets as $set): ?>
            <?php
            if (empty($set['rows'])) { continue; }
            $who      = $set['who'] !== '' ? $set['who'] : 'Unknown';
            $isSystem = $who === 'SYSTEM';
            // The reason is recorded per row but is the same across one save.
            $reason = '';
            foreach ($set['rows'] as $r) {
                if (!empty($r['Reason'])) { $reason = $r['Reason']; break; }
            }
            $employees = array_unique(array_filter(array_column($set['rows'], 'EmployeeName')));
            ?>
            <div class="ph-set<?= $isSystem ? ' ph-set-system' : '' ?>">
                <div class="ph-set-head">
                    <span class="ph-who"><?= $isSystem ? '⚙️ Automatic' : '👤 ' . htmlspecialchars($who) ?></span>
                    <span class="ph-when"><?= htmlspecialchars(date('D M j, Y g:i a', strtotime($set['when']))) ?></span>
                    <?php if ($employees): ?><span class="ph-emp"><?= htmlspecialchars(implode(', ', $employees)) ?></span><?php endif; ?>
                    <?php if ($reason !== ''): ?><span class="ph-reason">“<?= htmlspecialchars($reason) ?>”</span><?php endif; ?>
                </div>
                <table class="ph-table">
                    <thead>
                        <tr><th>Punch date</th><th>Field</th><th>From</th><th></th><th>To</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($set['rows'] as $r):
                        $field = $r['FieldChanged'] ?? '';
                        $label = $fieldLabels[$field] ?? $field; ?>
                        <tr>
                            <td class="ph-date"><?= htmlspecialchars(date('m/d/Y', strtotime($r['Date']))) ?></td>
                            <?php if ($field === 'CREATED'): ?>
                                <td colspan="4" class="ph-created">➕ Punch created</td>
                            <?php elseif ($field === 'DELETED'): ?>
                                <td class="ph-field">🗑️ Punch deleted</td>
                                <td colspan="3" class="ph-deleted"><?= describeDeletedPunch($r['OldValue']) ?></td>
                            <?php else: ?>
                                <td class="ph-field"><?= htmlspecialchars($label) ?></td>
                                <td class="ph-old"><?= historyValue($r['OldValue'], $field, $timeFields) ?></td>
                                <td class="ph-arrow">→</td>
                                <td class="ph-new"><?= historyValue($r['NewValue'], $field, $timeFields) ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>

        <?php if ($totalPages > 1): ?>
        <div class="ph-pager ph-pager-bottom">
            <?php if ($page > 1): ?><a class="btn secondary small" href="?<?= htmlspecialchars($qs(['p' => $page - 1])) ?>">‹ Newer</a><?php endif; ?>
            <span class="ph-pageno">Page <?= $page ?> of <?= $totalPages ?></span>
            <?php if ($page < $totalPages): ?><a class="btn secondary small" href="?<?= htmlspecialchars($qs(['p' => $page + 1])) ?>">Older ›</a><?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once 'footer.php'; ?>
