<?php
// admin/badges.php
// Selection page + printable Code 128 badge sheet. Pick one / some / all employees;
// each badge shows the configured company name, optional photo, name, optional office,
// and a Code 128 barcode of the BadgeID.
session_start();
require_once '../auth/db.php';
require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('manage_users');
require_once __DIR__ . '/../functions/settings_helper.php';
date_default_timezone_set('America/Chicago');

const BADGE_MIN_LEN = 6;

/** Generate a unique random 8-digit Badge ID. */
function gen_badge_id(mysqli $conn): string {
    do {
        $cand = (string) random_int(10000000, 99999999);
        $s = $conn->prepare("SELECT 1 FROM users WHERE BadgeID = ? LIMIT 1");
        $s->bind_param("s", $cand);
        $s->execute();
        $s->store_result();
        $exists = $s->num_rows > 0;
        $s->close();
    } while ($exists);
    return $cand;
}

// --- Action: fill in any missing / too-short Badge IDs with random 8-digit values ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'gen_missing') {
    $res = $conn->query("SELECT ID FROM users WHERE BadgeID IS NULL OR BadgeID = '' OR CHAR_LENGTH(BadgeID) < " . BADGE_MIN_LEN);
    $count = 0;
    while ($r = $res->fetch_assoc()) {
        $bid = gen_badge_id($conn);
        $u = $conn->prepare("UPDATE users SET BadgeID = ? WHERE ID = ?");
        $u->bind_param("si", $bid, $r['ID']);
        $u->execute();
        $u->close();
        $count++;
    }
    header("Location: badges.php?filled=" . $count);
    exit;
}

// --- Action: save badge options (company name + office toggle) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_opts') {
    $opts = [
        'CompanyName'     => trim($_POST['CompanyName'] ?? ''),
        'BadgeShowOffice' => !empty($_POST['BadgeShowOffice']) ? '1' : '0',
    ];
    foreach ($opts as $k => $v) {
        $s = $conn->prepare("INSERT INTO settings (SettingKey, SettingValue) VALUES (?, ?) ON DUPLICATE KEY UPDATE SettingValue = ?");
        $s->bind_param("sss", $k, $v, $v);
        $s->execute();
        $s->close();
    }
    header("Location: badges.php?saved=1");
    exit;
}

// --- PDF output: print the selected badges ---
if (isset($_GET['print'])) {
    $ids = array_values(array_filter(array_map('intval', (array) ($_GET['ids'] ?? []))));
    require_once './tcpdf/tcpdf.php';

    $company = getSettingValue('CompanyName', $conn);
    if ($company === null || $company === '') { $company = 'D-Best TimeSmart'; }
    $showOffice = getSettingValue('BadgeShowOffice', $conn) !== '0'; // default: show

    $badges = [];
    if (!empty($ids)) {
        $place = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $stmt = $conn->prepare("SELECT FirstName, LastName, Office, BadgeID, ProfilePhoto
                                FROM users
                                WHERE ID IN ($place) AND BadgeID IS NOT NULL AND BadgeID <> ''
                                ORDER BY LastName, FirstName");
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $r = $stmt->get_result();
        while ($row = $r->fetch_assoc()) { $badges[] = $row; }
        $stmt->close();
    }

    $pdf = new TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
    $pdf->SetCreator('D-Best TimeSmart');
    $pdf->SetAuthor('D-Best Technologies');
    $pdf->SetTitle('Employee Badges');
    $pdf->SetPrintHeader(false);
    $pdf->SetPrintFooter(false);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();

    if (empty($badges)) {
        $pdf->SetFont('helvetica', '', 14);
        $pdf->SetXY(15, 30);
        $pdf->MultiCell(180, 10, "No badges to print.\n\nSelect at least one employee who has a Badge ID.", 0, 'L');
        $pdf->Output('badges.pdf', 'I');
        exit;
    }

    $cardW = 90; $cardH = 52; $gapX = 10; $gapY = 8;
    $cols = 2; $marginX = 15; $marginTop = 15; $pageBottom = 279 - 12;
    $uploadDir = __DIR__ . '/../uploads/';

    $barStyle = [
        'position' => '', 'align' => 'C', 'stretch' => false, 'fitwidth' => true,
        'cellfitalign' => '', 'border' => false, 'hpadding' => 'auto', 'vpadding' => 'auto',
        'fgcolor' => [0, 0, 0], 'bgcolor' => false, 'text' => true, 'font' => 'helvetica',
        'fontsize' => 9, 'stretchtext' => 4,
    ];

    $x = $marginX; $y = $marginTop; $col = 0;
    foreach ($badges as $b) {
        if ($y + $cardH > $pageBottom) { $pdf->AddPage(); $x = $marginX; $y = $marginTop; $col = 0; }

        $name   = trim(($b['FirstName'] ?? '') . ' ' . ($b['LastName'] ?? ''));
        $office = trim((string) ($b['Office'] ?? ''));
        $code   = (string) $b['BadgeID'];

        $pdf->RoundedRect($x, $y, $cardW, $cardH, 3, '1111', 'D', ['width' => 0.3, 'color' => [120, 120, 120]]);

        // Optional photo on the RIGHT. Only embed an existing, real file under uploads/.
        $photo = (string) ($b['ProfilePhoto'] ?? '');
        $hasPhoto = false;
        $photoW = 24;
        if ($photo !== '' && strpos($photo, '/') === false && strpos($photo, '\\') === false) {
            $photoPath = $uploadDir . $photo;
            if (is_file($photoPath)) {
                $hasPhoto = true;
                $pdf->Image($photoPath, $x + $cardW - 5 - $photoW, $y + 13, $photoW, $photoW, '', '', '', true, 300, '', false, false, 0, false, false, false);
            }
        }
        // Left-hand column shared by company, name, office and barcode.
        $textW = $hasPhoto ? ($cardW - 10 - $photoW - 3) : ($cardW - 10);

        // Company name (top, left)
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColor(7, 56, 81);
        $pdf->SetXY($x + 5, $y + 4);
        $pdf->Cell($textW, 5, $company, 0, 0, 'L');

        // Name (left)
        $pdf->SetFont('helvetica', 'B', 13);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY($x + 5, $y + 12);
        $pdf->Cell($textW, 7, $name, 0, 0, 'L');

        // Office (left, optional)
        if ($showOffice && $office !== '') {
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetTextColor(90, 90, 90);
            $pdf->SetXY($x + 5, $y + 19.5);
            $pdf->Cell($textW, 5, $office, 0, 0, 'L');
        }

        // Barcode (left, in line with the text column)
        $pdf->write1DBarcode($code, 'C128', $x + 5, $y + 30, $textW, 15, 0.4, $barStyle, 'N');

        $col++;
        if ($col >= $cols) { $col = 0; $x = $marginX; $y += $cardH + $gapY; }
        else { $x += $cardW + $gapX; }
    }

    $pdf->Output('badges.pdf', 'I');
    exit;
}

// --- Selection page ---
$users = [];
$res = $conn->query("SELECT ID, FirstName, LastName, Office, BadgeID, ProfilePhoto FROM users ORDER BY LastName, FirstName");
while ($row = $res->fetch_assoc()) { $users[] = $row; }
$filled = isset($_GET['filled']) ? (int) $_GET['filled'] : null;
$saved  = isset($_GET['saved']);

$companyName = getSettingValue('CompanyName', $conn);
if ($companyName === null || $companyName === '') { $companyName = 'D-Best TimeSmart'; }
$showOffice = getSettingValue('BadgeShowOffice', $conn) !== '0';

$pageTitle = "Print Badges";
require_once 'header.php';
?>
<style>
  .badge-tools { display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap; margin:0 0 1rem; }
  .badge-table { width:100%; border-collapse:collapse; }
  .badge-table th, .badge-table td { padding:8px 10px; border-bottom:1px solid #eee; text-align:left; }
  .badge-table th { background:#f0f2f5; }
  .badge-missing { color:#c0392b; font-weight:600; }
  .badge-btn { padding:0.6rem 1.2rem; background:#0078D7; color:#fff; border:none; border-radius:8px; cursor:pointer; text-decoration:none; display:inline-block; }
  .badge-btn.secondary { background:#6c757d; }
</style>

<h2>Print Badges</h2>
<?php if ($filled !== null): ?>
  <p style="background:#d4edda; color:#1d7a36; padding:0.7rem 1rem; border-radius:8px;">
    Generated <?= $filled ?> Badge ID<?= $filled === 1 ? '' : 's' ?>.
  </p>
<?php endif; ?>
<?php if ($saved): ?>
  <p style="background:#d4edda; color:#1d7a36; padding:0.7rem 1rem; border-radius:8px;">Badge options saved.</p>
<?php endif; ?>

<form method="post" style="margin:0 0 1.25rem; padding:1rem 1.2rem; background:#f8f9fb; border-radius:8px;">
  <input type="hidden" name="action" value="save_opts">
  <div style="display:flex; gap:1.25rem; flex-wrap:wrap; align-items:flex-end;">
    <label>Company name <small>(top of each badge)</small>
      <input type="text" name="CompanyName" maxlength="60" value="<?= htmlspecialchars($companyName) ?>"
             style="display:block; margin-top:0.3rem; padding:0.6rem; border:1px solid #ccc; border-radius:6px; min-width:260px;">
    </label>
    <label style="display:flex; align-items:center; gap:0.4rem;">
      <input type="checkbox" name="BadgeShowOffice" value="1" <?= $showOffice ? 'checked' : '' ?>> Include office on badges
    </label>
    <button type="submit" class="badge-btn secondary">Save options</button>
  </div>
</form>

<p style="color:#555;">Tick the employees to print, then <strong>Print Selected</strong>. Badges open as a printable PDF.
   Employees without a Badge ID are skipped — use <strong>Generate missing Badge IDs</strong> to fill them with random 8-digit codes.</p>

<div class="badge-tools">
  <form method="post" onsubmit="return confirm('Assign a random 8-digit Badge ID to everyone who is missing one (or has a too-short one)?');" style="margin:0;">
    <input type="hidden" name="action" value="gen_missing">
    <button type="submit" class="badge-btn secondary">🎲 Generate missing Badge IDs</button>
  </form>
</div>

<form method="get" action="badges.php" target="_blank">
  <input type="hidden" name="print" value="1">
  <div class="badge-tools">
    <label><input type="checkbox" id="selAll" onclick="document.querySelectorAll('.badge-cb').forEach(c=>c.checked=this.checked)"> Select all</label>
    <button type="submit" class="badge-btn">🪪 Print Selected (PDF)</button>
  </div>
  <table class="badge-table">
    <thead>
      <tr><th></th><th>Name</th><th>Office</th><th>Badge ID</th><th>Photo</th></tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): ?>
        <?php
          $hasBadge = !empty($u['BadgeID']) && strlen($u['BadgeID']) >= BADGE_MIN_LEN;
          $hasPhoto = !empty($u['ProfilePhoto']) && is_file(__DIR__ . '/../uploads/' . $u['ProfilePhoto']);
        ?>
        <tr>
          <td><input type="checkbox" class="badge-cb" name="ids[]" value="<?= (int) $u['ID'] ?>" <?= $hasBadge ? '' : 'disabled title="No Badge ID"' ?>></td>
          <td><?= htmlspecialchars(($u['FirstName'] ?? '') . ' ' . ($u['LastName'] ?? '')) ?></td>
          <td><?= htmlspecialchars($u['Office'] ?? '') ?></td>
          <td><?= $hasBadge ? htmlspecialchars($u['BadgeID']) : '<span class="badge-missing">— none —</span>' ?></td>
          <td><?= $hasPhoto ? '✅' : '—' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</form>

<?php require_once 'footer.php'; ?>
