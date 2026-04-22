<?php
require_once '../config/database.php';
checkLogin();

require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id == 0) { header("Location: index.php"); exit(); }

$conn = getDBConnection();

$stmt = $conn->prepare("SELECT s.*,
                        c.company_name, c.short_name AS customer_short,
                        c.address AS customer_address,
                        c.tax_code AS customer_tax,
                        c.email AS customer_email,
                        c.phone AS customer_phone,
                        c.contact_person AS customer_contact
                        FROM shipments s
                        LEFT JOIN customers c ON s.customer_id = c.id
                        WHERE s.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$shipment = $stmt->get_result()->fetch_assoc();
if (!$shipment) { header("Location: index.php"); exit(); }

$stmt_sell = $conn->prepare("SELECT ss.*, cc.code, cc.description
                              FROM shipment_sells ss
                              JOIN cost_codes cc ON ss.cost_code_id = cc.id
                              WHERE ss.shipment_id = ? ORDER BY ss.id");
$stmt_sell->bind_param("i", $id);
$stmt_sell->execute();
$sells = $stmt_sell->get_result()->fetch_all(MYSQLI_ASSOC);

$grand_total = 0;
foreach ($sells as $s) {
    $grand_total += $s['total_amount'];
}

$conn->close();

// ============================================================
// MÀU SẮC & STYLE CONSTANTS
// ============================================================
define('C_DARK_BLUE',  '1B3A6B');
define('C_MID_BLUE',   '2E75B6');
define('C_LIGHT_BLUE', 'DEEAF1');
define('C_ACCENT',     'C00000');
define('C_GOLD',       'F4B942');
define('C_GREEN',      '375623');
define('C_GREEN_BG',   'E2EFDA');
define('C_GRAY_BG',    'F2F2F2');
define('C_BORDER',     'BDD7EE');

// Format số kiểu Việt Nam trong Excel: 1.000.000
// Dùng Excel number format: #,##0 với Excel tự dùng locale,
// nhưng để chắc chắn ta dùng format tường minh
define('NUM_FORMAT', '#,##0');   // Excel sẽ hiển thị 900000 → 900,000
// Nếu muốn dấu . là thousands: dùng string thủ công qua helper bên dưới

// ============================================================
// KHỞI TẠO SPREADSHEET
// ============================================================
$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Debit Note');

$sheet->getPageSetup()
    ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_PORTRAIT)
    ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
    ->setFitToPage(true)
    ->setFitToWidth(1)
    ->setFitToHeight(0);
$sheet->getPageMargins()->setTop(0.7)->setBottom(0.7)->setLeft(0.6)->setRight(0.6);
$sheet->getPageSetup()->setHorizontalCentered(true);
$sheet->setShowGridlines(false);

// ============================================================
// ĐỘ RỘNG CỘT
// ============================================================
$sheet->getColumnDimension('A')->setWidth(1.5);
$sheet->getColumnDimension('B')->setWidth(6);
$sheet->getColumnDimension('C')->setWidth(32);
$sheet->getColumnDimension('D')->setWidth(9);
$sheet->getColumnDimension('E')->setWidth(16);
$sheet->getColumnDimension('F')->setWidth(9);
$sheet->getColumnDimension('G')->setWidth(18);
$sheet->getColumnDimension('H')->setWidth(20);
$sheet->getColumnDimension('I')->setWidth(1.5);

// ============================================================
// HELPER FUNCTIONS
// ============================================================
function setCell($sheet, $cell, $value) {
    $sheet->setCellValue($cell, $value);
}

function styleRange($sheet, $range, $styleArr) {
    $sheet->getStyle($range)->applyFromArray($styleArr);
}

function rowH($sheet, $row, $height) {
    $sheet->getRowDimension($row)->setRowHeight($height);
}

function merge($sheet, $range) {
    $sheet->mergeCells($range);
}

function borderBox($sheet, $range, $color = '000000', $weight = Border::BORDER_THIN) {
    $sheet->getStyle($range)->applyFromArray([
        'borders' => [
            'outline' => ['borderStyle' => $weight, 'color' => ['rgb' => $color]]
        ]
    ]);
}

/**
 * Đặt giá trị số vào cell và áp dụng Excel number format
 * để hiển thị đúng dạng 1.000.000 (dấu . ngăn nghìn, dấu , thập phân)
 * Dùng format Excel: #.##0 sẽ không work vì Excel dùng , cho thousands
 * → Giải pháp: lưu số thực, dùng format "#,##0" và để Excel locale tự xử lý
 * HOẶC format thành string thủ công với number_format($v, 0, ',', '.')
 */
function setCellNum($sheet, $cell, $value, $decimals = 0) {
    // Lưu số thực vào cell
    $sheet->setCellValue($cell, floatval($value));
    // Áp dụng number format: dấu . ngăn nghìn, dấu , thập phân (VN style)
    // Excel format string: use _ for thousands sep, comma for decimal
    // Thực tế an toàn nhất: format thủ công thành string
    if ($decimals > 0) {
        $formatted = number_format(floatval($value), $decimals, ',', '.');
    } else {
        $formatted = number_format(floatval($value), 0, ',', '.');
    }
    // Ghi đè bằng string đã format
    $sheet->setCellValueExplicit($cell, $formatted,
        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
}

// ============================================================
// ROW TRACKER
// ============================================================
$r = 1;

// ============================================================
// PHẦN 1: THÔNG TIN CÔNG TY
// ============================================================

rowH($sheet, $r, 8); $r++;

// Logo (B2:C7)
rowH($sheet, $r, 18);
merge($sheet, "B{$r}:C7");
$logoPath = '../assets/images/logo.png';
if (file_exists($logoPath)) {
    $drawing = new Drawing();
    $drawing->setName('Logo');
    $drawing->setPath($logoPath);
    $drawing->setCoordinates("B{$r}");
    $drawing->setWidth(110);
    $drawing->setHeight(88);
    $drawing->setOffsetX(5);
    $drawing->setOffsetY(3);
    $drawing->setWorksheet($sheet);
}

// Tên công ty
merge($sheet, "D{$r}:H{$r}");
setCell($sheet, "D{$r}", 'LIPRO LOGISTICS CO.,LTD');
styleRange($sheet, "D{$r}", [
    'font'      => ['bold' => true, 'size' => 18, 'color' => ['rgb' => C_ACCENT], 'name' => 'Calibri'],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
rowH($sheet, $r, 28); $r++;

// Tagline
merge($sheet, "D{$r}:H{$r}");
setCell($sheet, "D{$r}", 'FREIGHT FORWARDING & CUSTOMS CLEARANCE SERVICES');
styleRange($sheet, "D{$r}", [
    'font'      => ['size' => 9, 'color' => ['rgb' => '666666'], 'italic' => true, 'name' => 'Calibri'],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
rowH($sheet, $r, 14); $r++;

// Address
merge($sheet, "D{$r}:H{$r}");
setCell($sheet, "D{$r}", 'No. 6 Lane 1002 Lang Street, Lang Ward, Hanoi, Vietnam');
styleRange($sheet, "D{$r}", [
    'font'      => ['size' => 9, 'color' => ['rgb' => '444444'], 'name' => 'Calibri'],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
rowH($sheet, $r, 14); $r++;

// Phone & Email
merge($sheet, "D{$r}:H{$r}");
setCell($sheet, "D{$r}", 'Tel: (+84) 366 666 322     Email: lipro.logistics@gmail.com');
styleRange($sheet, "D{$r}", [
    'font'      => ['size' => 9, 'color' => ['rgb' => '444444'], 'name' => 'Calibri'],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
rowH($sheet, $r, 14); $r++;

// Tax code
merge($sheet, "D{$r}:H{$r}");
setCell($sheet, "D{$r}", 'MST / Tax Code: 0110453612');
styleRange($sheet, "D{$r}", [
    'font'      => ['size' => 9, 'color' => ['rgb' => '666666'], 'name' => 'Calibri'],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
rowH($sheet, $r, 14); $r++;

// Khoảng trống cuối logo
rowH($sheet, $r, 8); $r++;

// Đường kẻ xanh đậm
merge($sheet, "B{$r}:H{$r}");
rowH($sheet, $r, 3);
styleRange($sheet, "B{$r}:H{$r}", ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_DARK_BLUE]]]);
$r++;

// Đường kẻ vàng
merge($sheet, "B{$r}:H{$r}");
rowH($sheet, $r, 2);
styleRange($sheet, "B{$r}:H{$r}", ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_GOLD]]]);
$r++;

// Tiêu đề DEBIT NOTE
rowH($sheet, $r, 36);
merge($sheet, "B{$r}:H{$r}");
setCell($sheet, "B{$r}", 'DEBIT NOTE / INVOICE');
styleRange($sheet, "B{$r}", [
    'font'      => ['bold' => true, 'size' => 20, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_DARK_BLUE]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$r++;

// Đường kẻ vàng dưới tiêu đề
merge($sheet, "B{$r}:H{$r}");
rowH($sheet, $r, 3);
styleRange($sheet, "B{$r}:H{$r}", ['fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_GOLD]]]);
$r++;

rowH($sheet, $r, 10); $r++;

// ============================================================
// PHẦN 2: THÔNG TIN LÔ HÀNG
// ============================================================

$labelStyle = [
    'font'      => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '1B3A6B'], 'name' => 'Calibri'],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_LIGHT_BLUE]],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
];
$valueStyle = [
    'font'      => ['size' => 10, 'name' => 'Calibri'],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
];

// Section header
rowH($sheet, $r, 22);
merge($sheet, "B{$r}:H{$r}");
setCell($sheet, "B{$r}", '  SHIPMENT INFORMATION  /  THONG TIN LO HANG');
styleRange($sheet, "B{$r}", [
    'font'      => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_MID_BLUE]],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
]);
$infoSectionHeaderRow = $r;
$r++;

// Bill To + Job No
rowH($sheet, $r, 20);
setCell($sheet, "B{$r}", 'BILL TO:');
styleRange($sheet, "B{$r}", $labelStyle);
merge($sheet, "C{$r}:E{$r}");
setCell($sheet, "C{$r}", strtoupper($shipment['company_name'] ?? ''));
styleRange($sheet, "C{$r}", [
    'font'      => ['bold' => true, 'size' => 11, 'color' => ['rgb' => C_ACCENT], 'name' => 'Calibri'],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
]);
setCell($sheet, "F{$r}", 'JOB NO:');
styleRange($sheet, "F{$r}", $labelStyle);
setCell($sheet, "G{$r}", $shipment['job_no'] ?? '');
styleRange($sheet, "G{$r}", [
    'font'      => ['bold' => true, 'size' => 11, 'color' => ['rgb' => '0070C0'], 'name' => 'Calibri'],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'horizontal' => Alignment::HORIZONTAL_CENTER],
]);
setCell($sheet, "H{$r}", '');
$r++;

// Tax ID + Date
rowH($sheet, $r, 18);
setCell($sheet, "B{$r}", 'TAX ID:');
styleRange($sheet, "B{$r}", $labelStyle);
merge($sheet, "C{$r}:E{$r}");
setCell($sheet, "C{$r}", $shipment['customer_tax'] ?? '');
styleRange($sheet, "C{$r}", $valueStyle);
setCell($sheet, "F{$r}", 'DATE:');
styleRange($sheet, "F{$r}", $labelStyle);
setCell($sheet, "G{$r}", date('d/m/Y'));
styleRange($sheet, "G{$r}", [
    'font'      => ['size' => 10, 'name' => 'Calibri'],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$r++;

// Address + Contact
rowH($sheet, $r, 18);
setCell($sheet, "B{$r}", 'ADDRESS:');
styleRange($sheet, "B{$r}", $labelStyle);
merge($sheet, "C{$r}:E{$r}");
setCell($sheet, "C{$r}", $shipment['customer_address'] ?? '');
styleRange($sheet, "C{$r}", $valueStyle);
setCell($sheet, "F{$r}", 'CONTACT:');
styleRange($sheet, "F{$r}", $labelStyle);
setCell($sheet, "G{$r}", $shipment['customer_contact'] ?? '');
styleRange($sheet, "G{$r}", $valueStyle);
$r++;

// Phone + Email
rowH($sheet, $r, 18);
setCell($sheet, "B{$r}", 'PHONE:');
styleRange($sheet, "B{$r}", $labelStyle);
merge($sheet, "C{$r}:E{$r}");
setCell($sheet, "C{$r}", $shipment['customer_phone'] ?? '');
styleRange($sheet, "C{$r}", $valueStyle);
setCell($sheet, "F{$r}", 'EMAIL:');
styleRange($sheet, "F{$r}", $labelStyle);
setCell($sheet, "G{$r}", $shipment['customer_email'] ?? '');
styleRange($sheet, "G{$r}", $valueStyle);
$r++;

rowH($sheet, $r, 6); $r++;

// MAWB + Vessel/Flight
rowH($sheet, $r, 18);
setCell($sheet, "B{$r}", 'MAWB / MBL:');
styleRange($sheet, "B{$r}", $labelStyle);
merge($sheet, "C{$r}:E{$r}");
setCell($sheet, "C{$r}", $shipment['mawb'] ?? '');
styleRange($sheet, "C{$r}", ['font' => ['bold' => true, 'size' => 10, 'name' => 'Calibri'], 'alignment' => ['vertical' => Alignment::VERTICAL_CENTER]]);
setCell($sheet, "F{$r}", 'VESSEL / FLIGHT:');
styleRange($sheet, "F{$r}", $labelStyle);
setCell($sheet, "G{$r}", $shipment['vessel_flight'] ?? '');
styleRange($sheet, "G{$r}", $valueStyle);
$r++;

// HAWB + POL→POD
rowH($sheet, $r, 18);
setCell($sheet, "B{$r}", 'HAWB / HBL:');
styleRange($sheet, "B{$r}", $labelStyle);
merge($sheet, "C{$r}:E{$r}");
setCell($sheet, "C{$r}", $shipment['hawb'] ?? '');
styleRange($sheet, "C{$r}", ['font' => ['bold' => true, 'size' => 10, 'name' => 'Calibri'], 'alignment' => ['vertical' => Alignment::VERTICAL_CENTER]]);
setCell($sheet, "F{$r}", 'POL -> POD:');
styleRange($sheet, "F{$r}", $labelStyle);
$polpod = trim(($shipment['pol'] ?? '') . ' -> ' . ($shipment['pod'] ?? ''), ' ->');
setCell($sheet, "G{$r}", $polpod);
styleRange($sheet, "G{$r}", $valueStyle);
$r++;

// Customs Dec + Arrival Date
rowH($sheet, $r, 18);
setCell($sheet, "B{$r}", 'CUSTOMS DEC.:');
styleRange($sheet, "B{$r}", $labelStyle);
merge($sheet, "C{$r}:E{$r}");
setCell($sheet, "C{$r}", $shipment['customs_declaration_no'] ?? '');
styleRange($sheet, "C{$r}", $valueStyle);
setCell($sheet, "F{$r}", 'ARRIVAL DATE:');
styleRange($sheet, "F{$r}", $labelStyle);
setCell($sheet, "G{$r}", !empty($shipment['arrival_date']) ? date('d/m/Y', strtotime($shipment['arrival_date'])) : '');
styleRange($sheet, "G{$r}", ['font' => ['size' => 10, 'name' => 'Calibri'], 'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]]);
$r++;

// PKG/GW/CW + Shipper
rowH($sheet, $r, 18);
setCell($sheet, "B{$r}", 'PKG / GW / CW:');
styleRange($sheet, "B{$r}", $labelStyle);
merge($sheet, "C{$r}:E{$r}");
$pkg_str = ($shipment['packages'] ?? 0) . ' PKGS  |  GW: ' . number_format($shipment['gw'] ?? 0, 2, ',', '.') . ' KGS  |  CW/CBM: ' . number_format($shipment['cw'] ?? 0, 2, ',', '.');
setCell($sheet, "C{$r}", $pkg_str);
styleRange($sheet, "C{$r}", $valueStyle);
setCell($sheet, "F{$r}", 'SHIPPER:');
styleRange($sheet, "F{$r}", $labelStyle);
setCell($sheet, "G{$r}", $shipment['shipper'] ?? '');
styleRange($sheet, "G{$r}", $valueStyle);
$r++;

// Border cho phần 2
$infoEndRow = $r - 1;
for ($row = $infoSectionHeaderRow; $row <= $infoEndRow; $row++) {
    $sheet->getStyle("B{$row}:H{$row}")->applyFromArray([
        'borders' => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'CCCCCC']]]
    ]);
}
borderBox($sheet, "B{$infoSectionHeaderRow}:H{$infoEndRow}", C_BORDER, Border::BORDER_MEDIUM);

rowH($sheet, $r, 12); $r++;

// ============================================================
// PHẦN 3: CHI TIẾT BÁN RA
// ============================================================

// Section header
rowH($sheet, $r, 22);
merge($sheet, "B{$r}:H{$r}");
setCell($sheet, "B{$r}", '  DESCRIPTION OF CHARGES  /  CHI TIET PHI DICH VU');
styleRange($sheet, "B{$r}", [
    'font'      => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_MID_BLUE]],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
]);
$r++;

// Header bảng
rowH($sheet, $r, 24);
$tableHeaders = [
    'B' => ['STT',                         Alignment::HORIZONTAL_CENTER],
    'C' => ['MO TA CHI PHI / DESCRIPTION', Alignment::HORIZONTAL_LEFT],
    'D' => ['SO LUONG',                    Alignment::HORIZONTAL_CENTER],
    'E' => ['DON GIA (VND)',               Alignment::HORIZONTAL_RIGHT],
    'F' => ['VAT %',                       Alignment::HORIZONTAL_CENTER],
    'G' => ['THANH TIEN (VND)',            Alignment::HORIZONTAL_RIGHT],
    'H' => ['GHI CHU / NOTES',             Alignment::HORIZONTAL_LEFT],
];
foreach ($tableHeaders as $col => $info) {
    setCell($sheet, "{$col}{$r}", $info[0]);
    styleRange($sheet, "{$col}{$r}", [
        'font'      => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_DARK_BLUE]],
        'alignment' => ['horizontal' => $info[1], 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '4472C4']]],
    ]);
}
$tableHeaderRow = $r;
$r++;

// Dữ liệu bảng
$dataRowStart        = $r;
$num                 = 1;
$subtotal_before_vat = 0;
$subtotal_total      = 0;

foreach ($sells as $sell) {
    rowH($sheet, $r, 20);

    $amount = $sell['unit_price'] * $sell['quantity'];
    $sum    = $sell['total_amount'];
    $subtotal_before_vat += $amount;
    $subtotal_total      += $sum;

    $rowBg = ($num % 2 === 0) ? 'EBF3FB' : 'FFFFFF';

    $commonStyle = function($align, $bold = false, $colorRgb = '333333') use ($rowBg) {
        return [
            'font'      => ['size' => 10, 'bold' => $bold, 'color' => ['rgb' => $colorRgb], 'name' => 'Calibri'],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $rowBg]],
            'alignment' => ['horizontal' => $align, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'BDD7EE']]],
        ];
    };

    // STT
    setCell($sheet, "B{$r}", $num);
    styleRange($sheet, "B{$r}", $commonStyle(Alignment::HORIZONTAL_CENTER));

    // Description
    setCell($sheet, "C{$r}", $sell['description']);
    styleRange($sheet, "C{$r}", $commonStyle(Alignment::HORIZONTAL_LEFT));

    // Số lượng - format số thập phân VN
    $sheet->setCellValueExplicit("D{$r}",
        number_format($sell['quantity'], 2, ',', '.'),
        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    styleRange($sheet, "D{$r}", $commonStyle(Alignment::HORIZONTAL_CENTER));

    // Đơn giá - format VN
    $sheet->setCellValueExplicit("E{$r}",
        number_format($sell['unit_price'], 0, ',', '.'),
        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    styleRange($sheet, "E{$r}", $commonStyle(Alignment::HORIZONTAL_RIGHT));

    // VAT %
    setCell($sheet, "F{$r}", number_format($sell['vat'], 2, ',', '.') . '%');
    styleRange($sheet, "F{$r}", $commonStyle(Alignment::HORIZONTAL_CENTER));

    // Thành tiền - format VN, in đậm xanh
    $sheet->setCellValueExplicit("G{$r}",
        number_format($sum, 0, ',', '.'),
        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    styleRange($sheet, "G{$r}", $commonStyle(Alignment::HORIZONTAL_RIGHT, true, C_GREEN));

    // Ghi chú
    setCell($sheet, "H{$r}", $sell['notes'] ?? '');
    styleRange($sheet, "H{$r}", $commonStyle(Alignment::HORIZONTAL_LEFT));

    $num++;
    $r++;
}

// Nếu không có dữ liệu
if (empty($sells)) {
    rowH($sheet, $r, 20);
    merge($sheet, "B{$r}:H{$r}");
    setCell($sheet, "B{$r}", 'Chua co du lieu / No data available');
    styleRange($sheet, "B{$r}", [
        'font'      => ['size' => 10, 'italic' => true, 'color' => ['rgb' => '999999'], 'name' => 'Calibri'],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F9F9F9']],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'CCCCCC']]],
    ]);
    $r++;
}

$dataRowEnd = $r - 1;
borderBox($sheet, "B{$tableHeaderRow}:H{$dataRowEnd}", C_DARK_BLUE, Border::BORDER_MEDIUM);

// -------------------------------------------------------
// Dòng Sub-total (trước VAT)
// -------------------------------------------------------
rowH($sheet, $r, 18);
merge($sheet, "B{$r}:F{$r}");
setCell($sheet, "B{$r}", 'Tong truoc VAT / Sub-total (before VAT):');
styleRange($sheet, "B{$r}", [
    'font'      => ['bold' => true, 'size' => 10, 'color' => ['rgb' => '555555'], 'name' => 'Calibri'],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_GRAY_BG]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'CCCCCC']]],
]);
$sheet->setCellValueExplicit("G{$r}",
    number_format($subtotal_before_vat, 0, ',', '.'),
    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
styleRange($sheet, "G{$r}", [
    'font'      => ['bold' => true, 'size' => 10, 'name' => 'Calibri'],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_GRAY_BG]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'CCCCCC']]],
]);
setCell($sheet, "H{$r}", '');
styleRange($sheet, "H{$r}", [
    'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_GRAY_BG]],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'CCCCCC']]],
]);
$r++;

// -------------------------------------------------------
// Dòng TỔNG CỘNG (ĐÃ BAO GỒM VAT) - GRAND TOTAL
// -------------------------------------------------------
rowH($sheet, $r, 28);
merge($sheet, "B{$r}:F{$r}");
setCell($sheet, "B{$r}", 'TONG CONG (DA BAO GOM VAT) / GRAND TOTAL (VAT INCLUDED):');
styleRange($sheet, "B{$r}", [
    'font'      => ['bold' => true, 'size' => 12, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_DARK_BLUE]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
    'borders'   => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => C_DARK_BLUE]]],
]);
$sheet->setCellValueExplicit("G{$r}",
    number_format($subtotal_total, 0, ',', '.') . ' VND',
    \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
styleRange($sheet, "G{$r}", [
    'font'      => ['bold' => true, 'size' => 13, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_DARK_BLUE]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT, 'vertical' => Alignment::VERTICAL_CENTER],
    'borders'   => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => C_DARK_BLUE]]],
]);
setCell($sheet, "H{$r}", '');
styleRange($sheet, "H{$r}", [
    'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_DARK_BLUE]],
    'borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['rgb' => C_DARK_BLUE]]],
]);
$r++;

rowH($sheet, $r, 12); $r++;

// ============================================================
// PHẦN 4: THÔNG TIN CHUYỂN KHOẢN
// ============================================================

// Section header
rowH($sheet, $r, 22);
merge($sheet, "B{$r}:H{$r}");
setCell($sheet, "B{$r}", '  PAYMENT INFORMATION  /  THONG TIN THANH TOAN');
styleRange($sheet, "B{$r}", [
    'font'      => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_MID_BLUE]],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
]);
borderBox($sheet, "B{$r}:H{$r}", C_MID_BLUE);
$r++;

// Terms
rowH($sheet, $r, 18);
merge($sheet, "B{$r}:H{$r}");
setCell($sheet, "B{$r}", '  Terms: Payment due within 30 days from invoice date. / Thanh toan trong vong 30 ngay ke tu ngay xuat hoa don.');
styleRange($sheet, "B{$r}", [
    'font'      => ['size' => 9, 'italic' => true, 'color' => ['rgb' => '555555'], 'name' => 'Calibri'],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FFF9E6']],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'DDDDDD']]],
]);
$r++;

rowH($sheet, $r, 6); $r++;

// Tiêu đề 2 block TK
$bankStartRow = $r;
rowH($sheet, $r, 20);
merge($sheet, "B{$r}:D{$r}");
setCell($sheet, "B{$r}", 'TAI KHOAN THANH TOAN DICH VU');
styleRange($sheet, "B{$r}", [
    'font'      => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1B5E20']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
merge($sheet, "F{$r}:H{$r}");
setCell($sheet, "F{$r}", 'TAI KHOAN THANH TOAN CHI HO (POB)');
styleRange($sheet, "F{$r}", [
    'font'      => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1A237E']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
setCell($sheet, "E{$r}", '');
$r++;

// Helper vẽ dòng tài khoản
function bankRow($sheet, &$r, $label, $valueLeft, $valueRight) {
    rowH($sheet, $r, 18);
    setCell($sheet, "B{$r}", $label);
    $sheet->getStyle("B{$r}")->applyFromArray([
        'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '1B5E20'], 'name' => 'Calibri'],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8F5E9']],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'C8E6C9']]],
    ]);
    $sheet->mergeCells("C{$r}:D{$r}");
    setCell($sheet, "C{$r}", $valueLeft);
    $sheet->getStyle("C{$r}")->applyFromArray([
        'font'      => ['size' => 9, 'name' => 'Calibri'],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F1F8F1']],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'C8E6C9']]],
    ]);
    setCell($sheet, "E{$r}", '');
    setCell($sheet, "F{$r}", $label);
    $sheet->getStyle("F{$r}")->applyFromArray([
        'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '1A237E'], 'name' => 'Calibri'],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'E8EAF6']],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'C5CAE9']]],
    ]);
    $sheet->mergeCells("G{$r}:H{$r}");
    setCell($sheet, "G{$r}", $valueRight);
    $sheet->getStyle("G{$r}")->applyFromArray([
        'font'      => ['size' => 9, 'name' => 'Calibri'],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EEF0FB']],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR, 'color' => ['rgb' => 'C5CAE9']]],
    ]);
    $r++;
}

bankRow($sheet, $r, 'Chu TK:', 'CONG TY TNHH LIPRO LOGISTICS', 'VU THUY LINH');
bankRow($sheet, $r, 'So TK:', '9039998888', '19032342305016');
bankRow($sheet, $r, 'Ngan hang:', 'MB Bank (Quan doi)', 'Techcombank');
bankRow($sheet, $r, 'Chi nhanh:', 'Ha Noi', 'Ha Noi');
bankRow($sheet, $r, 'Noi dung CK:',
    'LIPRO - ' . ($shipment['job_no'] ?? '') . ' - ' . ($shipment['hawb'] ?? ''),
    'POB - ' . ($shipment['job_no'] ?? ''));

borderBox($sheet, "B{$bankStartRow}:D" . ($r - 1), '1B5E20', Border::BORDER_MEDIUM);
borderBox($sheet, "F{$bankStartRow}:H" . ($r - 1), '1A237E', Border::BORDER_MEDIUM);

rowH($sheet, $r, 10); $r++;

// ============================================================
// FOOTER - CHỮ KÝ
// ============================================================
rowH($sheet, $r, 16);
merge($sheet, "B{$r}:D{$r}");
setCell($sheet, "B{$r}", 'Nguoi lap / Prepared by:');
styleRange($sheet, "B{$r}", [
    'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '555555'], 'name' => 'Calibri'],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
merge($sheet, "F{$r}:H{$r}");
setCell($sheet, "F{$r}", 'Xac nhan / Confirmed by:');
styleRange($sheet, "F{$r}", [
    'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '555555'], 'name' => 'Calibri'],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
]);
$r++;

for ($i = 0; $i < 3; $i++) { rowH($sheet, $r, 16); $r++; }

rowH($sheet, $r, 16);
merge($sheet, "B{$r}:D{$r}");
setCell($sheet, "B{$r}", 'LIPRO LOGISTICS CO.,LTD');
styleRange($sheet, "B{$r}", [
    'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => C_ACCENT], 'name' => 'Calibri'],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'borders'   => ['top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '999999']]],
]);
merge($sheet, "F{$r}:H{$r}");
setCell($sheet, "F{$r}", strtoupper($shipment['company_name'] ?? ''));
styleRange($sheet, "F{$r}", [
    'font'      => ['bold' => true, 'size' => 9, 'color' => ['rgb' => '333333'], 'name' => 'Calibri'],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    'borders'   => ['top' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '999999']]],
]);
$r++;

rowH($sheet, $r, 8); $r++;

// Footer cuối
merge($sheet, "B{$r}:H{$r}");
rowH($sheet, $r, 14);
setCell($sheet, "B{$r}", 'Thank you for your business! — Cam on Quy khach da su dung dich vu cua chung toi.');
styleRange($sheet, "B{$r}", [
    'font'      => ['size' => 9, 'italic' => true, 'color' => ['rgb' => 'FFFFFF'], 'name' => 'Calibri'],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => C_DARK_BLUE]],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
]);
$r++;

// ============================================================
// PRINT AREA & OUTPUT
// ============================================================
$sheet->getPageSetup()->setPrintArea("A1:I{$r}");

$filename = 'DebitNote_' . preg_replace('/[^A-Za-z0-9_]/', '_', $shipment['job_no'])
          . '_' . date('Ymd') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: max-age=0');

(new Xlsx($spreadsheet))->save('php://output');
exit();
?>