<?php
// Thêm 2 dòng này để hiện lỗi chi tiết
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once '../config/database.php';
checkLogin();

if (isSupplier()) {
    header("Location: /forwarder/shipments/index.php?error=no_permission");
    exit();
}

require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) { header("Location: index.php"); exit(); }

$conn = getDBConnection();

$stmt = $conn->prepare(
    "SELECT q.*, c.company_name, c.short_name, c.tax_code, c.address, c.email, c.phone
     FROM quotations q LEFT JOIN customers c ON q.customer_id = c.id WHERE q.id = ?"
);
$stmt->bind_param("i", $id);
$stmt->execute();
$quot = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$quot) { header("Location: index.php"); exit(); }

$stmt = $conn->prepare("SELECT * FROM quotation_items WHERE quotation_id = ? ORDER BY sort_order, id");
$stmt->bind_param("i", $id);
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$conn->close();

foreach ($items as &$it) {
    $it['_amt'] = floatval($it['amount'] ?? 0);
    if ($it['_amt'] == 0)
        $it['_amt'] = floatval($it['unit_price']) * floatval($it['quantity']);
}
unset($it);

$totals = [];
foreach ($items as $it) {
    $c = $it['currency'];
    $totals[$c] = ($totals[$c] ?? 0) + $it['_amt'];
}

$pol      = $quot['pol']      ?? '';
$pod      = $quot['pod']      ?? '';
$shipper  = $quot['shipper']  ?? '';
$packages = $quot['packages'] ?? '';
$gw       = $quot['gw']       ?? '';
$cw       = $quot['cw']       ?? '';

// ── Helpers ────────────────────  ─────────────────────────────────────
function vn($v, $d = 2): string {
    $v = floatval($v);
    if ($v == 0) return '-';
    $s = number_format($v, $d, ',', '.');
    if (strpos($s, ',') !== false) {
        $s = rtrim($s, '0');
        $s = rtrim($s, ',');
    }
    return $s;
}

function vnFull($v, $d = 2): string {
    $v = floatval($v);
    return number_format($v, $d, ',', '.');
}

function S($sheet, $range, array $arr) {
    $sheet->getStyle($range)->applyFromArray($arr);
}

// ── Colors ───────────────────────────────────────────────────────────
$C_RED   = 'C00000';
$C_BLUE  = '2F5496';
$C_GRN   = '538135';
$C_DKBL  = '1F3864';
$C_GOLD  = 'F4B942';
$C_LGRAY = 'F2F2F2';
$C_WHITE = 'FFFFFF';
$C_DARK  = '404040';
$C_LBLUE = 'F0F6FC';

// ══════════════════════════════════════════════════════════════════════
$ss    = new Spreadsheet();
$sheet = $ss->getActiveSheet();
$sheet->setTitle('Quotation');
$sheet->setShowGridlines(false);

$ps = $sheet->getPageSetup();
$ps->setOrientation(PageSetup::ORIENTATION_PORTRAIT);
$ps->setPaperSize(PageSetup::PAPERSIZE_A4);
$ps->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(0);
$sheet->getPageMargins()->setTop(0.6)->setBottom(0.6)->setLeft(0.5)->setRight(0.5);

$colWidths = ['A'=>1,'B'=>28,'C'=>10,'D'=>14,'E'=>10,'F'=>16,'G'=>12,'H'=>8,'I'=>18,'J'=>18,'K'=>1];
foreach ($colWidths as $col => $w)
    $sheet->getColumnDimension($col)->setWidth($w);

$r = 1;

// ══════════════════════════════════════════════════════════════  ═══════
// SECTION 1 — HEADER CÔNG TY
// ══════════════════════════════════════════════════════════════════════
$sheet->getRowDimension($r)->setRowHeight(5); $r++;

$sheet->mergeCells("B{$r}:C" . ($r + 3));
$logo = dirname(__DIR__) . '/assets/images/logo.png';
if (file_exists($logo)) {
    $img = new Drawing();
    $img->setName('Logo')->setPath($logo)
        ->setCoordinates("B{$r}")->setOffsetX(4)->setOffsetY(4)
        ->setWidth(80)->setHeight(65)->setWorksheet($sheet);
}

$sheet->getRowDimension($r)->setRowHeight(22);
$sheet->mergeCells("D{$r}:J{$r}");
$sheet->setCellValue("D{$r}", 'LIPRO LOGISTICS CO., LTD');
S($sheet, "D{$r}", [
    'font'      => ['bold'=>true,'size'=>16,'color'=>['rgb'=>$C_RED],'name'=>'Times New Roman'],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],
]);
$r++;

$compLines = [
    'No. 6 Lane 1002 Lang Street, Lang Ha Ward, Dong Da District, Hanoi City, Vietnam',
    'Tel: (+84) 366 666 322     Email: lipro.logistics@gmail.com',
    'MST / Tax Code: 0110453612',
];
foreach ($compLines as $line) {
    $sheet->getRowDimension($r)->setRowHeight(13);
    $sheet->mergeCells("D{$r}:J{$r}");
    $sheet->setCellValue("D{$r}", $line);
    S($sheet, "D{$r}", [
        'font'      => ['size'=>8,'color'=>['rgb'=>'666666']],
        'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER],
    ]);
    $r++;
}

$sheet->getRowDimension($r)->setRowHeight(2);
$sheet->mergeCells("B{$r}:J{$r}");
S($sheet, "B{$r}:J{$r}", ['fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$C_RED]]]);
$r++;

$sheet->getRowDimension($r)->setRowHeight(2);
$sheet->mergeCells("B{$r}:J{$r}");
S($sheet, "B{$r}:J{$r}", ['fill'=>['fillType'=>Fill::FILL_SOLID,'startColor'=>['rgb'=>$C_GOLD]]]);
$r++;

// ══════════════════════════════════════════════════════════════════════
// SECTION 2 — TIÊU ĐỀ
// ══════════════════════════════════════════════════════════════════════
$sheet->getRowDimension($r)->setRowHeight(26);
$sheet->mergeCells("B{$r}:J{$r}");
$sheet->setCellValue("B{$r}", 'BÁO GIÁ / QUOTATION');
S($sheet, "B{$r}", [
    'font'      => ['bold'=>true,'size'=>15,'name'=>'Times New Roman'],
    'alignment' => ['horizontal'=>Alignment::HORIZONTAL_CENTER,'vertical'=>Alignment::VERTICAL_CENTER],
]);
$r++;

$sheet->getRowDimension($r)->setRowHeight(14);
$sheet->mergeCells("B{$r}:E{$r}");
$sheet->setCellValue("B{$r}", 'Số/No: ' . ($quot['quotation_no'] ?? ''));
S($sheet, "B{$r}", ['font'=>['bold'=>true,'size'=>9],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_LEFT,'vertical'=>Alignment::VERTICAL_CENTER]]);
$sheet->mergeCells("F{$r}:G{$r}");
$sheet->setCellValue("F{$r}", 'NGÀY / DATE:');
S($sheet, "F{$r}", ['font'=>['bold'=>true,'size'=>9],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_RIGHT,'vertical'=>Alignment::VERTICAL_CENTER]]);
$sheet->mergeCells("H{$r}:J{$r}");
$sheet->setCellValue("H{$r}", $quot['issue_date'] ? date('d/m/Y', strtotime($quot['issue_date'])) : date('d/m/Y'));
S($sheet, "H{$r}", ['font'=>['bold'=>true,'size'=>9,'color'=>['rgb'=>$C_RED]],'alignment'=>['horizontal'=>Alignment::HORIZONTAL_LEFT,'vertical'=>Alignment::VERTICAL_CENTER]]);
$r++;
$sheet->getRowDimension($r)->setRowHeight(5); $r++;

// ══════════════════════════════════════════════════════════════════════
// SECTION 3 — THÔNG TIN KHÁCH HÀNG
// ══════════════════════════════════════