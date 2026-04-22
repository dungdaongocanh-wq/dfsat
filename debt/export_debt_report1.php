<?php
require_once '../config/database.php';
require_once '../config/ehoadon.php';
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
// THAM SỐ LỌC (giữ nguyên từ debt.php)
// ============================================================
$search       = trim($_GET['search']       ?? '');
$search_email = trim($_GET['search_email'] ?? '');
$status_kh    = trim($_GET['status_kh']    ?? '');
$status_ncc   = trim($_GET['status_ncc']   ?? '');
$month        = trim($_GET['month']        ?? '');
$customer_id  = trim($_GET['customer_id']  ?? '');
$is_locked    = trim($_GET['is_locked']    ?? '');

$conn = getDBConnection();

// ============================================================
// BUILD WHERE
// ============================================================
$where  = ["s.deleted_at IS NULL"];
$params = [];
$types  = '';

if ($search !== '') {
    $like    = '%' . $search . '%';
    $where[] = '(s.job_no LIKE ? OR s.hawb LIKE ? OR s.mawb LIKE ? OR c.company_name LIKE ? OR s.customs_declaration_no LIKE ?)';
    $params  = array_merge($params, [$like, $like, $like, $like, $like]);
    $types  .= 'sssss';
}
if ($search_email !== '') {
    $like_email = '%' . $search_email . '%';
    $where[]    = 'c.email LIKE ?';
    $params[]   = $like_email;
    $types     .= 's';
}
if ($month !== '') {
    $where[]  = 'DATE_FORMAT(s.arrival_date, "%Y-%m") = ?';
    $params[] = $month;
    $types   .= 's';
}
if ($customer_id !== '' && intval($customer_id) > 0) {
    $where[]  = 's.customer_id = ?';
    $params[] = intval($customer_id);
    $types   .= 'i';
}
if ($is_locked !== '') {
    $where[]  = 's.is_locked = ?';
    $params[] = $is_locked;
    $types   .= 's';
}

$sql = "SELECT s.id, s.job_no, s.hawb, s.mawb, s.customs_declaration_no,
    s.arrival_date, s.invoice_no, s.invoice_date,
    c.id AS cust_id, c.company_name, c.short_name, c.email AS cust_email,
    COALESCE((SELECT SUM(sc.total_amount) FROM shipment_costs sc WHERE sc.shipment_id = s.id), 0) AS total_cost,
    COALESCE((SELECT SUM(ss.total_amount) FROM shipment_sells ss WHERE ss.shipment_id = s.id), 0) AS total_sell,
    COALESCE(s.customer_paid_amount, 0) AS customer_paid_amount,
    s.customer_paid_at,
    s.customer_paid_note,
    COALESCE(s.supplier_paid_amount, 0) AS supplier_paid_amount,
    s.supplier_paid_note
    FROM shipments s
    LEFT JOIN customers c ON s.customer_id = c.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY s.arrival_date DESC, s.id DESC";

if (!empty($params)) {
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} else {
    $rows = $conn->query($sql)->fetch_all(MYSQLI_ASSOC);
}

// Filter status sau khi lấy data
$data = [];
foreach ($rows as $row) {
    $sell       = floatval($row['total_sell']);
    $cost       = floatval($row['total_cost']);
    $kh_paid    = floatval($row['customer_paid_amount']);
    $ncc_paid   = floatval($row['supplier_paid_amount']);
    $kh_remain  = $sell - $kh_paid;
    $ncc_remain = $cost - $ncc_paid;

    if ($status_kh === 'paid'    && $kh_paid  < $sell)  continue;
    if ($status_kh === 'unpaid'  && $kh_paid  >= $sell) continue;
    if ($status_kh === 'partial' && ($kh_paid <= 0 || $kh_paid >= $sell)) continue;
    if ($status_ncc === 'paid'    && $ncc_paid  < $cost)  continue;
    if ($status_ncc === 'unpaid'  && $ncc_paid  >= $cost) continue;
    if ($status_ncc === 'partial' && ($ncc_paid <= 0 || $ncc_paid >= $cost)) continue;

    $row['total_sell']  = $sell;
    $row['total_cost']  = $cost;
    $row['kh_remain']   = $kh_remain;
    $row['ncc_remain']  = $ncc_remain;
    $data[] = $row;
}

$conn->close();

// Totals
$sum_sell      = array_sum(array_column($data, 'total_sell'));
$sum_kh_paid   = array_sum(array_column($data, 'customer_paid_amount'));
$sum_kh_remain = array_sum(array_column($data, 'kh_remain'));

// ============================================================
// HELPERS
// ============================================================
function xc($sh, $cell, $val)  { $sh->setCellValue($cell, $val); }
function xcs($sh, $cell, $val) { $sh->setCellValueExplicit($cell, (string)$val, DataType::TYPE_STRING); }
function xs($sh, $r, $s)       { $sh->getStyle($r)->applyFromArray($s); }
function xr($sh, $n, $h)       { $sh->getRowDimension($n)->setRowHeight($h); }
function xm($sh, $r)           { $sh->mergeCells($r); }
function xfill($sh, $r, $c)    {
    $sh->getStyle($r)->applyFromArray([
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $c]]
    ]);
}
function xborder($sh, $r, $c = '1B3A6B', $w = Border::BORDER_MEDIUM) {
    $sh->getStyle($r)->applyFromArray([
        'borders' => ['outline' => ['borderStyle' => $w, 'color' => ['rgb' => $c]]]
    ]);
}
function fmtNum($n) {
    return ($n != 0) ? number_format((float)$n, 0, ',', '.') : '-';
}

// ============================================================
// SPREADSHEET
// ============================================================
$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();
$sheet->setTitle('Debt Report');

$sheet->getPageSetup()
    ->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE)
    ->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4)
    ->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(0);
$sheet->getPageMargins()->setTop(0.5)->setBottom(0.5)->setLeft(0.5)->setRight(0.5);
$sheet->getPageSetup()->setHorizontalCentered(true);
$sheet->setShowGridlines(false);

// ============================================================
// ĐỘ RỘNG CỘT
// Cột: A(pad) B(STT) C(JobNo) D(KH) E(Email) F(HAWB) G(TờKhai) H(SốHĐ) I(NgàyHĐ) J(NgàyĐến) K(Sell) L(KHTraả) M(NgàyTrảKH) N(CònNợ) O(GhiChú) P(pad)
// ============================================================
$colWidths = [
    'A' => 1.2,  'B' => 5,    'C' => 13,   'D' => 22,
    'E' => 20,   'F' => 13,   'G' => 15,   'H' => 13,
    'I' => 11,   'J' => 11,   'K' => 14,   'L' => 14,
    'M' => 12,   'N' => 14,   'O' => 22,   'P' => 1.2,
];
foreach ($colWidths as $col => $w) {
    $sheet->getColumnDimension($col)->setWidth($w);
}

$R = 1;

// ============================================================
// ROW 1 — TOP PADDING
// ============================================================
xr($sheet, $R, 5); $R++;

// ============================================================
// ROWS 2-7 — HEADER: LOGO + CÔNG TY
// ============================================================
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

// Tên công ty D2:P2
xr($sheet, $R, 32);
xm($sheet, "D{$R}:P{$R}");
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
xm($sheet, "D{$R}:P{$R}");
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
xm($sheet, "E{$R}:I{$R}");
xc($sheet, "E{$R}", 'No. 6 Lane 1002 Lang Street, Lang Ward, Hanoi City, Vietnam');
xs($sheet, "E{$R}", [
    'font'      => ['size' => 10, 'name' => 'Calibri'],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
]);
xc($sheet, "J{$R}", 'Phone:');
xs($sheet, "J{$R}", [
    'font'      => ['bold' => true, 'size' => 10, 'name' => 'Calibri'],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);
xm($sheet, "K{$R}:P{$R}");
xc($sheet, "K{$R}", '0985572699');
xs($sheet, "K{$R}", [
    'font'      => ['size' => 10, 'name' => 'Calibri'],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
]);
$R++;

// Row 6 — Email
xr($sheet, $R, 15);
xc($sheet, "J{$R}", 'Email:');
xs($sheet, "J{$R}", [
    'font'      => ['bold' => true, 'size' => 10, 'name' => 'Calibri'],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);
xm($sheet, "K{$R}:P{$R}");
xc($sheet, "K{$R}", 'lipro.logistics@gmail.com');
xs($sheet, "K{$R}", [
    'font'      => ['size' => 10, 'name' => 'Calibri',
                    'color' => ['rgb' => '0563C1'], 'underline' => true],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
]);
$R++;

// Row 7 — bottom padding logo
xr($sheet, $R, 5); $R++;

// === Đường kẻ Navy + Gold ===
xr($sheet, $R, 3);
xm($sheet, "B{$R}:P{$R}");
xfill($sheet, "B{$R}:P{$R}", '1B3A6B');
$R++;
xr($sheet, $R, 2);
xm($sheet, "B{$R}:P{$R}");
xfill($sheet, "B{$R}:P{$R}", 'F4B942');
$R++;
xr($sheet, $R, 8); $R++;

// ============================================================
// TIÊU ĐỀ REPORT
// ============================================================
xr($sheet, $R, 38);
xm($sheet, "B{$R}:P{$R}");
xc($sheet, "B{$R}", 'DEBT REPORT');
xs($sheet, "B{$R}", [
    'font'      => ['bold' => true, 'size' => 20, 'name' => 'Calibri',
                    'color' => ['rgb' => '1B3A6B']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);
$R++;

// Đường kẻ dưới tiêu đề
xr($sheet, $R, 3);
xm($sheet, "B{$R}:P{$R}");
xfill($sheet, "B{$R}:P{$R}", '1B3A6B');
$R++;
xr($sheet, $R, 2);
xm($sheet, "B{$R}:P{$R}");
xfill($sheet, "B{$R}:P{$R}", 'C00000');
$R++;
xr($sheet, $R, 12); $R++;

// ============================================================
// THÔNG TIN LỌC / KỲ BÁO CÁO
// ============================================================
$filterDesc = [];
if ($search !== '')       $filterDesc[] = 'Tìm kiếm: ' . $search;
if ($search_email !== '') $filterDesc[] = 'Email: ' . $search_email;
if ($month !== '')        $filterDesc[] = 'Tháng: ' . date('m/Y', strtotime($month . '-01'));
if ($status_kh !== '')    $filterDesc[] = 'Công nợ KH: ' . ['unpaid'=>'Chưa thu','partial'=>'Một phần','paid'=>'Đã thu'][$status_kh];
if ($is_locked !== '')    $filterDesc[] = 'Khoá: ' . ($is_locked === 'yes' ? 'Đã khoá' : 'Chưa khoá');

if (!empty($filterDesc)) {
    xr($sheet, $R, 16);
    xc($sheet, "B{$R}", 'Bộ lọc:');
    xs($sheet, "B{$R}", [
        'font'      => ['bold' => true, 'size' => 10, 'name' => 'Calibri'],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    xm($sheet, "C{$R}:P{$R}");
    xc($sheet, "C{$R}", implode('   |   ', $filterDesc));
    xs($sheet, "C{$R}", [
        'font'      => ['size' => 10, 'italic' => true, 'name' => 'Calibri',
                        'color' => ['rgb' => '555555']],
        'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $R++;
}

// Ngày xuất + tổng số lô
xr($sheet, $R, 16);
xc($sheet, "B{$R}", 'Ngày xuất:');
xs($sheet, "B{$R}", [
    'font'      => ['bold' => true, 'size' => 10, 'name' => 'Calibri'],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
]);
xm($sheet, "C{$R}:H{$R}");
xc($sheet, "C{$R}", date('d/m/Y H:i'));
xs($sheet, "C{$R}", [
    'font'      => ['size' => 10, 'name' => 'Calibri'],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
]);
xc($sheet, "I{$R}", 'Tổng số lô:');
xs($sheet, "I{$R}", [
    'font'      => ['bold' => true, 'size' => 10, 'name' => 'Calibri'],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);
xm($sheet, "J{$R}:P{$R}");
xc($sheet, "J{$R}", count($data) . ' lô');
xs($sheet, "J{$R}", [
    'font'      => ['size' => 10, 'name' => 'Calibri', 'bold' => true,
                    'color' => ['rgb' => '1B3A6B']],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
]);
$R++;
xr($sheet, $R, 10); $R++;

// ============================================================
// HEADER BẢNG
// ============================================================
$tableStart = $R;
xr($sheet, $R, 32);

$hStyle = [
    'font'      => ['bold' => true, 'size' => 10, 'name' => 'Calibri',
                    'color' => ['rgb' => 'FFFFFF']],
    'fill'      => ['fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '1B3A6B']],
    'alignment' => ['vertical'  => Alignment::VERTICAL_CENTER,
                    'wrapText'  => true],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                     'color'       => ['rgb' => '4472C4']]],
];

// Các cột xuất: STT, Job No, Khách hàng, Email KH, HAWB, Tờ Khai, Số HĐ, Ngày HĐ, Ngày Đến, Tổng Sell, KH Đã Trả, Ngày Trả KH, KH Còn Nợ, Ghi Chú KH
$headers = [
    'B' => ['STT',           Alignment::HORIZONTAL_CENTER],
    'C' => ['Job No',        Alignment::HORIZONTAL_CENTER],
    'D' => ['Khách hàng',    Alignment::HORIZONTAL_LEFT],
    'E' => ['Email KH',      Alignment::HORIZONTAL_LEFT],
    'F' => ['HAWB',          Alignment::HORIZONTAL_CENTER],
    'G' => ['Tờ Khai',       Alignment::HORIZONTAL_CENTER],
    'H' => ['Số HĐ',         Alignment::HORIZONTAL_CENTER],
    'I' => ['Ngày HĐ',       Alignment::HORIZONTAL_CENTER],
    'J' => ['Ngày Đến',      Alignment::HORIZONTAL_CENTER],
    'K' => ['Tổng Sell',     Alignment::HORIZONTAL_RIGHT],
    'L' => ['KH Đã Trả',     Alignment::HORIZONTAL_RIGHT],
    'M' => ['Ngày Trả KH',   Alignment::HORIZONTAL_CENTER],
    'N' => ['KH Còn Nợ',     Alignment::HORIZONTAL_RIGHT],
    'O' => ['Ghi Chú KH',    Alignment::HORIZONTAL_LEFT],
];

foreach ($headers as $col => [$label, $align]) {
    xc($sheet, "{$col}{$R}", $label);
    xs($sheet, "{$col}{$R}", array_merge($hStyle, [
        'alignment' => ['horizontal' => $align,
                        'vertical'   => Alignment::VERTICAL_CENTER,
                        'wrapText'   => true],
    ]));
}
$R++;

// ============================================================
// STYLE BASE CHO DỮ LIỆU
// ============================================================
$dataStyleBase = [
    'font'      => ['size' => 10, 'name' => 'Calibri', 'color' => ['rgb' => '333333']],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_HAIR,
                                     'color'       => ['rgb' => 'BDD7EE']]],
];

// ============================================================
// DỮ LIỆU
// ============================================================
$stt = 1;
foreach ($data as $row) {
    xr($sheet, $R, 20);
    $bg         = ($stt % 2 === 0) ? 'EEF4FB' : 'FFFFFF';
    $kh_remain  = $row['kh_remain'];

    // Màu dòng nợ
    $remain_color = $kh_remain > 0 ? 'C00000' : '198754';

    $inv_date  = (!empty($row['invoice_date'])  && $row['invoice_date']  !== '0000-00-00')
                 ? date('d/m/Y', strtotime($row['invoice_date']))  : '';
    $arr_date  = (!empty($row['arrival_date'])  && $row['arrival_date']  !== '0000-00-00')
                 ? date('d/m/Y', strtotime($row['arrival_date']))  : '';
    $paid_date = (!empty($row['customer_paid_at']) && $row['customer_paid_at'] !== '0000-00-00')
                 ? date('d/m/Y', strtotime($row['customer_paid_at'])) : '';

    // Cột text thường
    $textCells = [
        'B' => [$stt,    Alignment::HORIZONTAL_CENTER, false, '333333'],
        'I' => [$inv_date, Alignment::HORIZONTAL_CENTER, false, '333333'],
        'J' => [$arr_date, Alignment::HORIZONTAL_CENTER, false, '333333'],
        'M' => [$paid_date, Alignment::HORIZONTAL_CENTER, false, '555555'],
    ];
    foreach ($textCells as $col => [$val, $align, $bold, $color]) {
        xc($sheet, "{$col}{$R}", $val);
        xs($sheet, "{$col}{$R}", array_merge($dataStyleBase, [
            'font'      => array_merge($dataStyleBase['font'],
                           ['bold' => $bold, 'color' => ['rgb' => $color]]),
            'fill'      => ['fillType' => Fill::FILL_SOLID,
                            'startColor' => ['rgb' => $bg]],
            'alignment' => array_merge($dataStyleBase['alignment'],
                           ['horizontal' => $align]),
        ]));
    }

    // Cột string (ngăn scientific notation)
    $stringCells = [
        'C' => [$row['job_no']                     ?? '', Alignment::HORIZONTAL_CENTER, true,  '1B3A6B'],
        'D' => [$row['company_name']               ?? '', Alignment::HORIZONTAL_LEFT,   false, '333333'],
        'E' => [$row['cust_email']                 ?? '', Alignment::HORIZONTAL_LEFT,   false, '555555'],
        'F' => [$row['hawb']                       ?? '', Alignment::HORIZONTAL_CENTER, false, '333333'],
        'G' => [$row['customs_declaration_no']     ?? '', Alignment::HORIZONTAL_CENTER, false, '333333'],
        'H' => [$row['invoice_no']                 ?? '', Alignment::HORIZONTAL_CENTER, false, '333333'],
        'O' => [$row['customer_paid_note']         ?? '', Alignment::HORIZONTAL_LEFT,   false, '666666'],
    ];
    foreach ($stringCells as $col => [$val, $align, $bold, $color]) {
        xcs($sheet, "{$col}{$R}", $val);
        xs($sheet, "{$col}{$R}", array_merge($dataStyleBase, [
            'font'      => array_merge($dataStyleBase['font'],
                           ['bold' => $bold, 'color' => ['rgb' => $color]]),
            'fill'      => ['fillType' => Fill::FILL_SOLID,
                            'startColor' => ['rgb' => $bg]],
            'alignment' => array_merge($dataStyleBase['alignment'],
                           ['horizontal' => $align, 'wrapText' => true]),
        ]));
    }

    // Cột số tiền
    $numCells = [
        'K' => [fmtNum($row['total_sell']),             false, '1B3A6B'],
        'L' => [fmtNum($row['customer_paid_amount']),   false, '198754'],
        'N' => [fmtNum($kh_remain),                     true,  $remain_color],
    ];
    foreach ($numCells as $col => [$val, $bold, $color]) {
        xcs($sheet, "{$col}{$R}", $val);
        xs($sheet, "{$col}{$R}", array_merge($dataStyleBase, [
            'font'      => array_merge($dataStyleBase['font'],
                           ['bold' => $bold, 'color' => ['rgb' => $color]]),
            'fill'      => ['fillType' => Fill::FILL_SOLID,
                            'startColor' => ['rgb' => $bg]],
            'alignment' => array_merge($dataStyleBase['alignment'],
                           ['horizontal' => Alignment::HORIZONTAL_RIGHT]),
        ]));
    }

    $stt++;
    $R++;
}

// ============================================================
// DÒNG TỔNG CỘNG
// ============================================================
xr($sheet, $R, 26);
$totalRow = $R;
$tStyle = [
    'font'      => ['bold' => true, 'size' => 11, 'name' => 'Calibri',
                    'color' => ['rgb' => '1B3A6B']],
    'fill'      => ['fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'D6E4F0']],
    'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                     'color'       => ['rgb' => '4472C4']]],
];

// Label
xm($sheet, "B{$R}:J{$R}");
xc($sheet, "B{$R}", 'TỔNG CỘNG  (' . count($data) . ' lô)');
xs($sheet, "B{$R}", array_merge($tStyle, [
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]));

// Tổng Sell
xcs($sheet, "K{$R}", fmtNum($sum_sell));
xs($sheet, "K{$R}", array_merge($tStyle, [
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]));

// Tổng KH đã trả
xcs($sheet, "L{$R}", fmtNum($sum_kh_paid));
xs($sheet, "L{$R}", array_merge($tStyle, [
    'font'      => array_merge($tStyle['font'], ['color' => ['rgb' => '198754']]),
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]));

// Cột M trống
xcs($sheet, "M{$R}", '');
xs($sheet, "M{$R}", $tStyle);

// Tổng còn nợ — màu đỏ nếu > 0
xcs($sheet, "N{$R}", fmtNum($sum_kh_remain));
xs($sheet, "N{$R}", array_merge($tStyle, [
    'font'      => array_merge($tStyle['font'], [
        'color' => ['rgb' => $sum_kh_remain > 0 ? 'C00000' : '198754'],
        'size'  => 12,
    ]),
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_RIGHT,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]));

// Cột O trống
xcs($sheet, "O{$R}", '');
xs($sheet, "O{$R}", $tStyle);

// Viền ngoài toàn bảng
xborder($sheet, "B{$tableStart}:O{$totalRow}", '1B3A6B', Border::BORDER_MEDIUM);
$R++;
xr($sheet, $R, 18); $R++;

// ============================================================
// FOOTER — Chữ ký + Ghi chú
// ============================================================
xr($sheet, $R, 16);
xm($sheet, "B{$R}:F{$R}");
xc($sheet, "B{$R}", 'Người lập báo cáo');
xs($sheet, "B{$R}", [
    'font'      => ['bold' => true, 'size' => 10, 'name' => 'Calibri',
                    'color' => ['rgb' => '1B3A6B']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER],
    'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_THIN,
                                 'color'       => ['rgb' => '1B3A6B']]],
]);
xm($sheet, "K{$R}:O{$R}");
xc($sheet, "K{$R}", 'Giám đốc');
xs($sheet, "K{$R}", [
    'font'      => ['bold' => true, 'size' => 10, 'name' => 'Calibri',
                    'color' => ['rgb' => '1B3A6B']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER],
    'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_THIN,
                                 'color'       => ['rgb' => '1B3A6B']]],
]);
$R++;
xr($sheet, $R, 50);
xm($sheet, "B{$R}:F{$R}");
xm($sheet, "K{$R}:O{$R}");
xs($sheet, "B{$R}:O{$R}", [
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical'   => Alignment::VERTICAL_CENTER],
    'borders'   => ['bottom' => ['borderStyle' => Border::BORDER_THIN,
                                 'color'       => ['rgb' => 'DDDDDD']]],
]);
$R++;

xr($sheet, $R, 16);
xm($sheet, "B{$R}:O{$R}");
xc($sheet, "B{$R}", '* Báo cáo được xuất tự động từ hệ thống. Số tiền đơn vị VNĐ.');
xs($sheet, "B{$R}", [
    'font'      => ['size' => 9, 'italic' => true, 'name' => 'Calibri',
                    'color' => ['rgb' => '999999']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical'   => Alignment::VERTICAL_CENTER],
]);

// ============================================================
// PRINT AREA & OUTPUT
// ============================================================
$sheet->getPageSetup()->setPrintArea("A1:P{$R}");

$date_slug = $month ? '_' . str_replace('-', '', $month) : '';
$filename  = 'DebtReport' . $date_slug . '_' . date('Ymd_His') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
header('Cache-Control: max-age=0');

(new Xlsx($spreadsheet))->save('php://output');
exit();
?>