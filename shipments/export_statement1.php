<?php
require_once '../config/database.php';
checkLogin();

require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

// ============================================================
// THAM SỐ LỌC
// ============================================================
$search          = isset($_GET['search'])    ? trim($_GET['search'])    : '';
$status_filter   = isset($_GET['status'])    ? $_GET['status']          : '';
$locked_filter   = isset($_GET['locked'])    ? $_GET['locked']          : '';
$customer_filter = isset($_GET['customer'])  ? intval($_GET['customer']) : 0;
$date_from       = isset($_GET['date_from']) ? trim($_GET['date_from'])  : '';
$date_to         = isset($_GET['date_to'])   ? trim($_GET['date_to'])    : '';

$conn = getDBConnection();

// ============================================================
// BUILD WHERE
// ============================================================
$where = [];
if ($search) {
    $s       = $conn->real_escape_string($search);
    $where[] = "(s.job_no LIKE '%$s%' OR s.mawb LIKE '%$s%' OR s.hawb LIKE '%$s%'
                 OR s.shipper LIKE '%$s%' OR s.cnee LIKE '%$s%'
                 OR c.short_name LIKE '%$s%')";
}
if ($status_filter)   $where[] = "s.status = '"   . $conn->real_escape_string($status_filter) . "'";
if ($locked_filter)   $where[] = "s.is_locked = '" . $conn->real_escape_string($locked_filter) . "'";
if ($customer_filter) $where[] = "s.customer_id = " . intval($customer_filter);
if ($date_from)       $where[] = "DATE(s.created_at) >= '" . $conn->real_escape_string($date_from) . "'";
if ($date_to)         $where[] = "DATE(s.created_at) <= '" . $conn->real_escape_string($date_to)   . "'";

$whereClause = count($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// ============================================================
// LẤY SHIPMENTS
// ============================================================
$sql = "SELECT s.*,
               c.company_name,        c.short_name    AS customer_short,
               c.address AS customer_address,
               c.tax_code AS customer_tax,
               c.email   AS customer_email,
               c.phone   AS customer_phone
        FROM shipments s
        LEFT JOIN customers c ON s.customer_id = c.id
        $whereClause
        ORDER BY s.invoice_date ASC, s.created_at ASC";

$result    = $conn->query($sql);
$shipments = [];
while ($row = $result->fetch_assoc()) {
    $shipments[] = $row;
}

// ============================================================
// TÍNH TIỀN SELL TỪNG LÔ HÀNG
// ============================================================
foreach ($shipments as &$ship) {
    $sid = intval($ship['id']);
    $rs  = $conn->query("
        SELECT
            COALESCE(SUM(quantity * unit_price), 0)                       AS excl,
            COALESCE(SUM(total_amount - (quantity * unit_price)), 0)      AS vat,
            COALESCE(SUM(total_amount), 0)                                AS total
        FROM shipment_sells
        WHERE shipment_id = $sid
    ");
    $d = $rs->fetch_assoc();
    $ship['s_excl']  = (float)$d['excl'];
    $ship['s_vat']   = (float)$d['vat'];
    $ship['s_total'] = (float)$d['total'];
}
unset($ship);

// Thông tin KH chính
$customer_info = null;
if ($customer_filter) {
    $stc = $conn->prepare("SELECT * FROM customers WHERE id = ?");
    $stc->bind_param("i", $customer_filter);
    $stc->execute();
    $customer_info = $stc->get_result()->fetch_assoc();
}
$conn->close();

// Grand totals
$grand_excl  = array_sum(array_column($shipments, 's_excl'));
$grand_vat   = array_sum(array_column($shipments, 's_vat'));
$grand_total = array_sum(array_column($shipments, 's_total'));

// ============================================================
// SPREADSHEET
// ============================================================
$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();
$sheet->setTitle('Statement of Account');

$sheet->getPageSetup()
    ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
    ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
    ->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(0);
$sheet->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.5)->setRight(0.5);
$sheet->getPageSetup()->setHorizontalCentered(true);
$sheet->setShowGridlines(false);

// ============================================================
// CỘT
// ============================================================
foreach ([
    'A' => 1.2, 'B' => 5.5, 'C' => 13, 'D' => 12,
    'E' => 21,  'F' => 21,  'G' => 16, 'H' => 13,
    'I' => 15,  'J' => 14,  'K' => 1.2,
] as $col => $w) {
    $sheet->getColumnDimension($col)->setWidth($w);
}

// ============================================================
// HELPERS
// ============================================================

/** Ghi giá trị thông thường (số, boolean, công thức) */
function xc($sh, $cell, $val) {
    $sh->setCellValue($cell, $val);
}

/**
 * Ghi giá trị LUÔN LÀ STRING - ngăn Excel tự convert
 * Dùng cho: số tiền đã format, HAWB, Tờ khai, Số HĐ, Số TK ngân hàng
 */
function xcs($sh, $cell, $val) {
    $sh->setCellValueExplicit($cell, (string)$val, DataType::TYPE_STRING);
}

function xs($sh, $r, $s)    { $sh->getStyle($r)->applyFromArray($s); }
function xr($sh, $n, $h)    { $sh->getRowDimension($n)->setRowHeight($h); }
function xm($sh, $r)        { $sh->mergeCells($r); }
function xfill($sh, $r, $c) {
    $sh->getStyle($r)->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $c]]
    ]);
}
function xborder($sh, $r, $c = '1B3A6B', $w = Border::BORDER_MEDIUM) {
    $sh->getStyle($r)->applyFromArray([
        'borders' => ['outline' => ['borderStyle' => $w, 'color' => ['rgb' => $c]]]
    ]);
}

/**
 * Format số kiểu Việt Nam: 1.000.000
 * Trả về string đã format (dấu . ngăn nghìn, dấu , thập phân)
 * Nếu = 0 thì trả về '-'
 */
function fmtNum($n) {
    return ($n != 0) ? number_format((float)$n, 0, ',', '.') : '-';
}

// ============================================================
// ROW COUNTER
// ============================================================
$R = 1;

// ============================================================
// ROW 1 — TOP PADDING
// ============================================================
xr($sheet, $R, 5); $R++;

// ============================================================
// ROWS 2-7 — HEADER: LOGO + CÔNG TY
// ============================================================

// Logo B2:C7
xm($sheet, "B{$R}:C7");
$logoPath = '../assets/images/logo.png';
if (file_exists($logoPath)) {
    $logo = new Drawing();
    $logo->setName('Logo')->setPath($logoPath)
         ->setCoordinates("B{$R}")
         ->setWidth(96)->setHeight(77)
         ->setOffsetX(5)->setOffsetY(3)
         ->setWorksheet($sheet);
}

// Tên công ty D2:K2
xr($sheet, $R, 32);
xm($sheet, "D{$R}:K{$R}");
xc($sheet, "D{$R}", 'LIPRO LOGISTICS CO.,LTD');
xs($sheet, "D{$R}", [
    'font'      => ['bold' => true, 'size' => 22, 'name' => 'Calibri',
                    'color' => ['rgb' => '1B3A6B']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);
$R++;

// Row 3 — Tagline
xr($sheet, $R, 14);
xm($sheet, "D{$R}:K{$R}");
xc($sheet, "D{$R}", 'FREIGHT FORWARDING & CUSTOMS CLEARANCE');
xs($sheet, "D{$R}", [
    'font'      => ['size' => 9, 'italic' => true, 'name' => 'Calibri',
                    'color' => ['rgb' => '888888']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);
$R++;

// Row 4 — blank nhỏ
xr($sheet, $R, 5); $R++;

// Row 5 — Address | Phone
xr($sheet, $R, 15);
xc($sheet, "D{$R}", 'Address:');
xs($sheet, "D{$R}", [
    'font'      => ['bold' => true, 'size' => 10, 'name' => 'Calibri'],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);
xm($sheet, "E{$R}:G{$R}");
xc($sheet, "E{$R}", 'No. 6 Lane 1002 Lang Street, Lang Ward, Hanoi City, Vietnam');
xs($sheet, "E{$R}", [
    'font'      => ['size' => 10, 'name' => 'Calibri'],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
]);
xc($sheet, "H{$R}", 'Phone:');
xs($sheet, "H{$R}", [
    'font'      => ['bold' => true, 'size' => 10, 'name' => 'Calibri'],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);
xm($sheet, "I{$R}:K{$R}");
xc($sheet, "I{$R}", '0985572699');
xs($sheet, "I{$R}", [
    'font'      => ['size' => 10, 'name' => 'Calibri'],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
]);
$R++;

// Row 6 — Email
xr($sheet, $R, 15);
xc($sheet, "H{$R}", 'Email:');
xs($sheet, "H{$R}", [
    'font'      => ['bold' => true, 'size' => 10, 'name' => 'Calibri'],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);
xm($sheet, "I{$R}:K{$R}");
xc($sheet, "I{$R}", 'lipro.logistics@gmail.com');
xs($sheet, "I{$R}", [
    'font'      => ['size' => 10, 'name' => 'Calibri',
                    'color' => ['rgb' => '0563C1'], 'underline' => true],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
]);
$R++;

// Row 7 — bottom padding logo
xr($sheet, $R, 5); $R++;

// === Đường kẻ Navy + Gold ===
xr($sheet, $R, 3);
xm($sheet, "B{$R}:K{$R}");
xfill($sheet, "B{$R}:K{$R}", '1B3A6B');
$R++;
xr($sheet, $R, 2);
xm($sheet, "B{$R}:K{$R}");
xfill($sheet, "B{$R}:K{$R}", 'F4B942');
$R++;
xr($sheet, $R, 8); $R++;

// ============================================================
// TIÊU ĐỀ
// ============================================================
xr($sheet, $R, 38);
xm($sheet, "B{$R}:K{$R}");
xc($sheet, "B{$R}", 'STATEMENT OF ACCOUNT');
xs($sheet, "B{$R}", [
    'font'      => ['bold' => true, 'size' => 20, 'name' => 'Calibri',
                    'color' => ['rgb' => '1B3A6B']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);
$R++;

// Đường kẻ dưới tiêu đề
xr($sheet, $R, 3);
xm($sheet, "B{$R}:K{$R}");
xfill($sheet, "B{$R}:K{$R}", '1B3A6B');
$R++;
xr($sheet, $R, 2);
xm($sheet, "B{$R}:K{$R}");
xfill($sheet, "B{$R}:K{$R}", 'C00000');
$R++;
xr($sheet, $R, 12); $R++;

// ============================================================
// THÔNG TIN KHÁCH HÀNG
// ============================================================
$cus_name    = $customer_info['company_name'] ?? ($shipments[0]['company_name']    ?? '');
$cus_tax     = $customer_info['tax_code']     ?? ($shipments[0]['customer_tax']     ?? '');
$cus_address = $customer_info['address']      ?? ($shipments[0]['customer_address'] ?? '');

$billRows = [
    ['Bill To:',  strtoupper($cus_name), 11, true,  18],
    ['Tax ID:',   $cus_tax,              10, false, 16],
    ['Address:',  $cus_address,          10, false, 16],
];
foreach ($billRows as $br) {
    xr($sheet, $R, $br[4]);
    xc($sheet, "B{$R}", $br[0]);
    xs($sheet, "B{$R}", [
        'font'      => ['bold' => true, 'size' => $br[2], 'name' => 'Calibri'],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    xm($sheet, "C{$R}:K{$R}");
    // ✅ Dùng xcs() cho Tax ID để tránh scientific notation
    if ($br[0] === 'Tax ID:') {
        xcs($sheet, "C{$R}", $br[1]);
    } else {
        xc($sheet, "C{$R}", $br[1]);
    }
    xs($sheet, "C{$R}", [
        'font'      => ['bold' => $br[3], 'size' => $br[2], 'name' => 'Calibri'],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    ]);
    $R++;
}

// Period
if ($date_from || $date_to) {
    xr($sheet, $R, 14);
    xc($sheet, "B{$R}", 'Period:');
    xs($sheet, "B{$R}", [
        'font'      => ['bold' => true, 'size' => 10, 'name' => 'Calibri'],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    xm($sheet, "C{$R}:K{$R}");
    $period = ($date_from ? date('d/m/Y', strtotime($date_from)) : '...')
            . '  —  '
            . ($date_to   ? date('d/m/Y', strtotime($date_to))   : '...');
    xc($sheet, "C{$R}", $period);
    xs($sheet, "C{$R}", [
        'font'      => ['size' => 10, 'italic' => true, 'name' => 'Calibri',
                        'color' => ['rgb' => '555555']],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $R++;
}

xr($sheet, $R, 14); $R++;

// ============================================================
// BẢNG DỮ LIỆU
// ============================================================
$tableStart = $R;

// Header bảng
xr($sheet, $R, 30);
$hStyle = [
    'font'      => ['bold' => true, 'size' => 10, 'name' => 'Calibri',
                    'color' => ['rgb' => 'FFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '1B3A6B']],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                     'color'       => ['rgb' => '4472C4']]],
];
$cols = [
    'B' => ['STT',               Alignment::HORIZONTAL_CENTER],
    'C' => ['So Hoa Don',        Alignment::HORIZONTAL_CENTER],
    'D' => ['Ngay Hoa Don',      Alignment::HORIZONTAL_CENTER],
    'E' => ['HAWB',              Alignment::HORIZONTAL_CENTER],
    'F' => ['To Khai',           Alignment::HORIZONTAL_CENTER],
    'G' => ['AMOUNT EXCL VAT',   Alignment::HORIZONTAL_RIGHT],
    'H' => ['VAT',               Alignment::HORIZONTAL_RIGHT],
    'I' => ['TOTAL',             Alignment::HORIZONTAL_RIGHT],
    'J' => ['CHI HO (B2B INV.)', Alignment::HORIZONTAL_CENTER],
];
foreach ($cols as $col => [$label, $align]) {
    xc($sheet, "{$col}{$R}", $label);
    xs($sheet, "{$col}{$R}", array_merge($hStyle, [
        'alignment' => array_merge($hStyle['alignment'], ['horizontal' => $align]),
    ]));
}
$R++;

// ============================================================
// STYLE CHUNG CHO CELL DỮ LIỆU
// ============================================================
$dataStyleBase = [
    'font'      => ['size' => 10, 'name' => 'Calibri', 'color' => ['rgb' => '333333']],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR,
                                     'color'       => ['rgb' => 'BDD7EE']]],
];

// Dữ liệu
$stt = 1;
foreach ($shipments as $ship) {
    xr($sheet, $R, 20);
    $bg = ($stt % 2 === 0) ? 'EEF4FB' : 'FFFFFF';

    $inv_date = !empty($ship['invoice_date'])
                ? date('d/m/Y', strtotime($ship['invoice_date']))
                : '';

    // Cột text thông thường
    $textCells = [
        'B' => [$stt,       Alignment::HORIZONTAL_CENTER, false],
        'D' => [$inv_date,  Alignment::HORIZONTAL_CENTER, false],
        'J' => ['-',        Alignment::HORIZONTAL_CENTER, false],
    ];
    foreach ($textCells as $col => [$val, $align, $bold]) {
        xc($sheet, "{$col}{$R}", $val);
        xs($sheet, "{$col}{$R}", array_merge($dataStyleBase, [
            'font'      => array_merge($dataStyleBase['font'], [
                'bold'  => $bold,
                'color' => ['rgb' => $bold ? '1B3A6B' : '333333'],
            ]),
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
            'alignment' => array_merge($dataStyleBase['alignment'],
                           ['horizontal' => $align]),
        ]));
    }

    // ✅ Cột STRING - dùng xcs() để tránh scientific notation
    // Áp dụng cho: Số HĐ, HAWB, Tờ khai (có thể là chuỗi số dài)
    $stringCells = [
        'C' => [$ship['invoice_no']             ?? '', Alignment::HORIZONTAL_CENTER],
        'E' => [$ship['hawb']                   ?? '', Alignment::HORIZONTAL_CENTER],
        'F' => [$ship['customs_declaration_no'] ?? '', Alignment::HORIZONTAL_CENTER],
    ];
    foreach ($stringCells as $col => [$val, $align]) {
        xcs($sheet, "{$col}{$R}", $val);  // ← KEY FIX: TYPE_STRING
        xs($sheet, "{$col}{$R}", array_merge($dataStyleBase, [
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
            'alignment' => array_merge($dataStyleBase['alignment'],
                           ['horizontal' => $align]),
        ]));
    }

    // ✅ Cột SỐ TIỀN - dùng xcs() với fmtNum() để giữ dấu . ngăn nghìn
    $numCells = [
        'G' => [fmtNum($ship['s_excl']),  false],
        'H' => [fmtNum($ship['s_vat']),   false],
        'I' => [fmtNum($ship['s_total']), true],   // bold + màu xanh
    ];
    foreach ($numCells as $col => [$val, $bold]) {
        xcs($sheet, "{$col}{$R}", $val);  // ← KEY FIX: TYPE_STRING
        xs($sheet, "{$col}{$R}", array_merge($dataStyleBase, [
            'font'      => array_merge($dataStyleBase['font'], [
                'bold'  => $bold,
                'color' => ['rgb' => $bold ? '1B3A6B' : '333333'],
            ]),
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
            'alignment' => array_merge($dataStyleBase['alignment'],
                           ['horizontal' => Alignment::HORIZONTAL_RIGHT]),
        ]));
    }

    $stt++;
    $R++;
}

// ============================================================
// DÒNG TOTAL
// ============================================================
xr($sheet, $R, 24);
$totalRow = $R;
$tStyle = [
    'font'      => ['bold' => true, 'size' => 11, 'name' => 'Calibri',
                    'color' => ['rgb' => '1B3A6B']],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'D6E4F0']],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                     'color'       => ['rgb' => '4472C4']]],
];

xm($sheet, "B{$R}:F{$R}");
xc($sheet, "B{$R}", 'TOTAL');
xs($sheet, "B{$R}", array_merge($tStyle, [
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]));

// ✅ Dòng Total cũng dùng xcs() cho số tiền
foreach ([
    'G' => $grand_excl,
    'H' => $grand_vat,
    'I' => $grand_total,
] as $col => $val) {
    xcs($sheet, "{$col}{$R}", fmtNum($val));  // ← KEY FIX: TYPE_STRING
    xs($sheet, "{$col}{$R}", array_merge($tStyle, [
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT,
                        'vertical'   => Alignment::VERTICAL_CENTER],
    ]));
}

// Cột J (Chi hộ) trong total
xcs($sheet, "J{$R}", '-');
xs($sheet, "J{$R}", array_merge($tStyle, [
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]));

// Viền ngoài bảng
xborder($sheet, "B{$tableStart}:J{$totalRow}", '1B3A6B', Border::BORDER_MEDIUM);
$R++;
xr($sheet, $R, 18); $R++;

// ============================================================
// THÔNG TIN THANH TOÁN
// ============================================================
function drawPayBlock($sheet, &$R, $title, $rows) {
    // Header
    xr($sheet, $R, 22);
    xm($sheet, "B{$R}:J{$R}");
    xc($sheet, "B{$R}", $title);
    xs($sheet, "B{$R}", [
        'font'      => ['bold' => true, 'size' => 10, 'name' => 'Calibri',
                        'color' => ['rgb' => 'FFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '404040']],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'indent' => 1],
    ]);
    xborder($sheet, "B{$R}:J{$R}", '404040', Border::BORDER_MEDIUM);
    $R++;

    // Rows - ✅ Dùng xcs() cho Account No để tránh scientific notation
    foreach ($rows as [$label, $value]) {
        xr($sheet, $R, 18);
        xc($sheet, "B{$R}", $label);
        xs($sheet, "B{$R}", [
            'font'      => ['bold' => true, 'size' => 10, 'name' => 'Calibri'],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_HAIR,
                                         'color'       => ['rgb' => 'DDDDDD']]],
        ]);
        xm($sheet, "C{$R}:J{$R}");
        // Số tài khoản có thể bị convert → luôn dùng xcs()
        xcs($sheet, "C{$R}", $value);
        xs($sheet, "C{$R}", [
            'font'      => ['size' => 10, 'name' => 'Calibri'],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_HAIR,
                                         'color'       => ['rgb' => 'DDDDDD']]],
        ]);
        $R++;
    }
}

drawPayBlock($sheet, $R, 'Payment account information', [
    ['Account No:',  '9039998888'],
    ['Bank:',        'Military Commercial Joint Stock Bank (MB bank)'],
    ['Beneficiary:', 'CONG TY TNHH LIPRO LOGISTICS CO.,LTD'],
]);

xr($sheet, $R, 10); $R++;

drawPayBlock($sheet, $R, 'B2B account information', [
    ['Account No:',  '19032342305016'],
    ['Bank:',        'Ngan hang Ky thuong VN - Techcombank'],
    ['Beneficiary:', 'VU THUY LINH'],
]);

xr($sheet, $R, 10); $R++;

// ============================================================
// PRINT AREA & OUTPUT
// ============================================================
$sheet->getPageSetup()->setPrintArea("A1:K{$R}");

$cus_slug  = $customer_filter
             ? '_' . preg_replace('/[^A-Za-z0-9]/', '', strtoupper($cus_name))
             : '';
$date_slug = ($date_from || $date_to)
             ? '_' . ($date_from ? str_replace('-', '', $date_from) : 'x')
               . '_' . ($date_to ? str_replace('-', '', $date_to)   : 'x')
             : '';
$filename  = 'SOA' . $cus_slug . $date_slug . '_' . date('Ymd') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
header('Cache-Control: max-age=0');

(new Xlsx($spreadsheet))->save('php://output');
exit();
?>