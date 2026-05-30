<?php
// admin/badges.php
// Printable employee badge sheet — Code 128 barcodes of each user's BadgeID,
// scannable at the kiosk or the main-screen badge box.
session_start();
require_once '../auth/db.php';
require_once './tcpdf/tcpdf.php';

if (!isset($_SESSION['admin'])) {
    header("Location: ../user/login.php?admin=1");
    exit;
}

require_once __DIR__ . '/../functions/check_permission.php';
requirePermission('manage_users');

date_default_timezone_set('America/Chicago');

// Optional single-badge mode: ?emp=ID
$single = isset($_GET['emp']) && is_numeric($_GET['emp']) ? (int) $_GET['emp'] : 0;

if ($single) {
    $stmt = $conn->prepare("SELECT FirstName, LastName, Office, BadgeID FROM users WHERE ID = ? AND BadgeID IS NOT NULL AND BadgeID <> ''");
    $stmt->bind_param("i", $single);
} else {
    $stmt = $conn->prepare("SELECT FirstName, LastName, Office, BadgeID FROM users WHERE BadgeID IS NOT NULL AND BadgeID <> '' ORDER BY LastName, FirstName");
}
$stmt->execute();
$res = $stmt->get_result();
$badges = [];
while ($row = $res->fetch_assoc()) {
    $badges[] = $row;
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
    $pdf->MultiCell(180, 10, "No employees have a Badge ID yet.\n\nSet a Badge ID for an employee on the Users page, then print badges here.", 0, 'L');
    $pdf->Output('badges.pdf', 'I');
    exit;
}

// Card grid layout (credit-card-ish), 2 across
$cardW = 90; $cardH = 52;
$gapX  = 10; $gapY  = 8;
$cols  = 2;
$marginX = 15; $marginTop = 15;
$pageBottom = 279 - 12; // LETTER height minus bottom margin

$barStyle = [
    'position'     => '',
    'align'        => 'C',
    'stretch'      => false,
    'fitwidth'     => true,
    'cellfitalign' => '',
    'border'       => false,
    'hpadding'     => 'auto',
    'vpadding'     => 'auto',
    'fgcolor'      => [0, 0, 0],
    'bgcolor'      => false,
    'text'         => true,
    'font'         => 'helvetica',
    'fontsize'     => 9,
    'stretchtext'  => 4,
];

$x = $marginX;
$y = $marginTop;
$col = 0;

foreach ($badges as $b) {
    // New page if this card would overflow the bottom
    if ($y + $cardH > $pageBottom) {
        $pdf->AddPage();
        $x = $marginX;
        $y = $marginTop;
        $col = 0;
    }

    $name   = trim(($b['FirstName'] ?? '') . ' ' . ($b['LastName'] ?? ''));
    $office = trim((string) ($b['Office'] ?? ''));
    $code   = (string) $b['BadgeID'];

    // Card border
    $pdf->RoundedRect($x, $y, $cardW, $cardH, 3, '1111', 'D', ['width' => 0.3, 'color' => [120, 120, 120]]);

    // Company label
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetTextColor(7, 56, 81);
    $pdf->SetXY($x + 5, $y + 4);
    $pdf->Cell($cardW - 10, 5, 'D-Best TimeSmart', 0, 0, 'L');

    // Employee name
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetXY($x + 5, $y + 10);
    $pdf->Cell($cardW - 10, 7, $name, 0, 0, 'L');

    // Office (optional)
    if ($office !== '') {
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetTextColor(90, 90, 90);
        $pdf->SetXY($x + 5, $y + 17);
        $pdf->Cell($cardW - 10, 5, $office, 0, 0, 'L');
    }

    // Code 128 barcode of the BadgeID
    $pdf->write1DBarcode($code, 'C128', $x + 8, $y + 26, $cardW - 16, 16, 0.4, $barStyle, 'N');

    // Advance to next cell
    $col++;
    if ($col >= $cols) {
        $col = 0;
        $x = $marginX;
        $y += $cardH + $gapY;
    } else {
        $x += $cardW + $gapX;
    }
}

$pdf->Output('badges.pdf', 'I');
