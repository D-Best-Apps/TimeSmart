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

/** Render one badge into the rectangle (x,y,w,h) in the chosen orientation. */
function draw_badge(TCPDF $pdf, float $x, float $y, float $w, float $h, array $b, string $company, bool $showOffice, string $orient, string $uploadDir, array $barStyle): void {
    $pdf->RoundedRect($x, $y, $w, $h, 2.5, '1111', 'D', ['width' => 0.3, 'color' => [120, 120, 120]]);

    $name   = trim(($b['FirstName'] ?? '') . ' ' . ($b['LastName'] ?? ''));
    $office = trim((string) ($b['Office'] ?? ''));
    $code   = (string) ($b['BadgeID'] ?? '');
    $photo  = (string) ($b['ProfilePhoto'] ?? '');
    $hasPhoto = $photo !== '' && strpos($photo, '/') === false && strpos($photo, '\\') === false && is_file($uploadDir . $photo);
    $photoPath = $uploadDir . $photo;

    if ($orient === 'v') {
        // Portrait: photo on top, centered text, barcode along the bottom.
        $pad = 4;
        $top = $y + $pad;
        if ($hasPhoto) {
            $pw = min(30, $w - 2 * $pad);
            $pdf->Image($photoPath, $x + ($w - $pw) / 2, $top, $pw, $pw, '', '', '', true, 300, '', false, false, 0, false, false, false);
            $top += $pw + 2.5;
        }
        $pdf->SetFont('helvetica', 'B', 8);
        $pdf->SetTextColor(7, 56, 81);
        $pdf->SetXY($x + $pad, $top);
        $pdf->Cell($w - 2 * $pad, 4, $company, 0, 0, 'C');
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY($x + $pad, $top + 5);
        $pdf->Cell($w - 2 * $pad, 6, $name, 0, 0, 'C');
        if ($showOffice && $office !== '') {
            $pdf->SetFont('helvetica', '', 8);
            $pdf->SetTextColor(90, 90, 90);
            $pdf->SetXY($x + $pad, $top + 11);
            $pdf->Cell($w - 2 * $pad, 4, $office, 0, 0, 'C');
        }
        $bh = 13;
        $pdf->write1DBarcode($code, 'C128', $x + $pad, $y + $h - $bh - 3, $w - 2 * $pad, $bh, 0.4, $barStyle, 'N');
    } else {
        // Landscape: photo on the right; company, name, office and barcode left-aligned.
        $pad = 5;
        $photoW = min(24, $h - 2 * $pad);
        $textW = $w - 2 * $pad;
        if ($hasPhoto) {
            $pdf->Image($photoPath, $x + $w - $pad - $photoW, $y + ($h - $photoW) / 2, $photoW, $photoW, '', '', '', true, 300, '', false, false, 0, false, false, false);
            $textW = $w - 2 * $pad - $photoW - 3;
        }
        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetTextColor(7, 56, 81);
        $pdf->SetXY($x + $pad, $y + 4);
        $pdf->Cell($textW, 5, $company, 0, 0, 'L');
        $pdf->SetFont('helvetica', 'B', 13);
        $pdf->SetTextColor(0, 0, 0);
        $pdf->SetXY($x + $pad, $y + 12);
        $pdf->Cell($textW, 7, $name, 0, 0, 'L');
        if ($showOffice && $office !== '') {
            $pdf->SetFont('helvetica', '', 9);
            $pdf->SetTextColor(90, 90, 90);
            $pdf->SetXY($x + $pad, $y + 19.5);
            $pdf->Cell($textW, 5, $office, 0, 0, 'L');
        }
        $pdf->write1DBarcode($code, 'C128', $x + $pad, $y + $h - 18, $textW, 15, 0.4, $barStyle, 'N');
    }
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
    $ids    = array_values(array_filter(array_map('intval', (array) ($_GET['ids'] ?? []))));
    $orient = (($_GET['orient'] ?? 'h') === 'v') ? 'v' : 'h';          // h = landscape, v = portrait
    $media  = (($_GET['media'] ?? 'sheet') === 'card') ? 'card' : 'sheet'; // sheet = Letter, card = one CR80 page
    require_once './tcpdf/tcpdf.php';

    $company = getSettingValue('CompanyName', $conn);
    if ($company === null || $company === '') { $company = 'D-Best TimeSmart'; }
    $showOffice = getSettingValue('BadgeShowOffice', $conn) !== '0'; // default: show
    $uploadDir = __DIR__ . '/../uploads/';

    $barStyle = [
        'position' => '', 'align' => 'C', 'stretch' => false, 'fitwidth' => true,
        'cellfitalign' => '', 'border' => false, 'hpadding' => 'auto', 'vpadding' => 'auto',
        'fgcolor' => [0, 0, 0], 'bgcolor' => false, 'text' => true, 'font' => 'helvetica',
        'fontsize' => 8, 'stretchtext' => 4,
    ];

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

    if (empty($badges)) {
        $pdf = new TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
        $pdf->SetPrintHeader(false); $pdf->SetPrintFooter(false);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', '', 14);
        $pdf->SetXY(15, 30);
        $pdf->MultiCell(180, 10, "No badges to print.\n\nSelect at least one employee who has a Badge ID.", 0, 'L');
        $pdf->Output('badges.pdf', 'I');
        exit;
    }

    if ($media === 'card') {
        // One badge per page, sized to a standard CR80 ID card in the chosen orientation.
        $cw = $orient === 'v' ? 53.98 : 85.6;
        $ch = $orient === 'v' ? 85.6  : 53.98;
        $pdf = new TCPDF($orient === 'v' ? 'P' : 'L', 'mm', [$cw, $ch], true, 'UTF-8', false);
        $pdf->SetCreator('D-Best TimeSmart');
        $pdf->SetAuthor('D-Best Technologies');
        $pdf->SetTitle('Employee Badges');
        $pdf->SetPrintHeader(false);
        $pdf->SetPrintFooter(false);
        $pdf->SetAutoPageBreak(false);
        foreach ($badges as $b) {
            $pdf->AddPage();
            draw_badge($pdf, 0.6, 0.6, $cw - 1.2, $ch - 1.2, $b, $company, $showOffice, $orient, $uploadDir, $barStyle);
        }
        $pdf->Output('badges.pdf', 'I');
        exit;
    }

    // Sheet mode: tile multiple cards on a Letter page (cut them out afterwards).
    if ($orient === 'v') { $cardW = 54; $cardH = 86; } else { $cardW = 90; $cardH = 54; }
    $pdf = new TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);
    $pdf->SetCreator('D-Best TimeSmart');
    $pdf->SetAuthor('D-Best Technologies');
    $pdf->SetTitle('Employee Badges');
    $pdf->SetPrintHeader(false);
    $pdf->SetPrintFooter(false);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();

    $marginX = 14; $marginTop = 14; $gap = 8; $pageW = 216; $pageBottom = 279 - 14;
    $x = $marginX; $y = $marginTop;
    foreach ($badges as $b) {
        if ($y + $cardH > $pageBottom) { $pdf->AddPage(); $x = $marginX; $y = $marginTop; }
        draw_badge($pdf, $x, $y, $cardW, $cardH, $b, $company, $showOffice, $orient, $uploadDir, $barStyle);
        $x += $cardW + $gap;
        if ($x + $cardW > $pageW - $marginX) { $x = $marginX; $y += $cardH + $gap; }
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
  <div class="badge-tools" style="gap:1.5rem; background:#f8f9fb; padding:0.75rem 1rem; border-radius:8px;">
    <span><strong>Orientation:</strong>
      <label style="margin-left:0.5rem;"><input type="radio" name="orient" value="h" checked> Horizontal</label>
      <label style="margin-left:0.5rem;"><input type="radio" name="orient" value="v"> Vertical</label>
    </span>
    <span><strong>Output:</strong>
      <label style="margin-left:0.5rem;"><input type="radio" name="media" value="sheet" checked> Letter sheet (multiple, then cut)</label>
      <label style="margin-left:0.5rem;"><input type="radio" name="media" value="card"> ID-card printer (one per page, CR80)</label>
    </span>
  </div>
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
