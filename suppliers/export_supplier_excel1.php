<?php
// TẠM THỜI — XÓA SAU KHI DEBUG XONG
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once '../config/database.php';
checkLogin();

require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

$conn = getDBConnection();

// ============================================================
// PARAMS
// ============================================================
// mode = single | unassigned | all
$mode         = isset($_GET['mode'])        ? trim($_GET['mode'])               : 'single';
$supplier_id  = isset($_GET['supplier_id']) ? intval($_GET['supplier_id'])      : 0;
$month_filter = isset($_GET['month'])       ? trim($_GET['month'])              : date('Y-m');

$filter_year  = '';
$filter_month = '';
if ($month_filter) {
    $parts        = explode('-', $month_filter);
    $filter_year  = $parts[0] ?? '';
    $filter_month = $parts[1] ?? '';
}

// ============================================================
// BUILD WHERE theo mode
// ============================================================
$where = ["1=1"];

if ($filter_year && $filter_month) {
    $where[] = "YEAR(s.created_at) = "  . intval($filter_year);
    $where[] = "MONTH(s.created_at) = " . intval($filter_month);
}

switch ($mode) {
    case 'single':
        if (!$supplier_id) die('Thiếu supplier_id');
        $where[] = "sc.supplier_id = " . $supplier_id;
        break;
    case 'unassigned':
        $where[] = "sc.supplier_id IS NULL";
        break;
    case 'all':
        // Có thể thêm lọc NCC nếu $supplier_id > 0
        if ($supplier_id > 0) {
            $where[] = "sc.supplier_id = " . $supplier_id;
        } elseif ($supplier_id === -1) {
            $where[] = "sc.supplier_id IS NULL";
        }
        break;
    default:
        die('Mode không hợp lệ');
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

// ============================================================
// LẤY DỮ LIỆU
// ============================================================
$sql = "SELECT
            sc.id,
            sc.shipment_id,
            sc.quantity,
            sc.unit_price,
            sc.vat,
            sc.total_amount,
            sc.notes,
            cc.code        AS cost_code,
            cc.description AS cost_desc,
            s.job_no,
            s.hawb,
            s.mawb,
            s.customs_declaration_no,
            s.created_at   AS shipment_date,
            sup.id         AS supplier_id,
            sup.supplier_name,
            sup.short_name  AS sup_short,
            sup.bank_name,
            sup.bank_account
        FROM shipment_costs sc
        JOIN cost_codes cc      ON sc.cost_code_id = cc.id
        JOIN shipments s        ON sc.shipment_id  = s.id
        LEFT JOIN suppliers sup ON sc.supplier_id  = sup.id
        {$whereClause}
        ORDER BY
            CASE WHEN sc.supplier_id IS NULL THEN 1 ELSE 0 END ASC,
            sup.supplier_name ASC,
            s.created_at ASC,
            sc.id ASC";

$result = $conn->query($sql);
$rows   = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}

// ============================================================
// NHÓM THEO NCC
// ============================================================
$grouped = [];
foreach ($rows as $row) {
    $sid = $row['supplier_id'] ?? 'unassigned';
    if (!isset($grouped[$sid])) {
        if ($sid === 'unassigned') {
            $grouped[$sid] = [
                'info'  => [
                    'supplier_name' => 'Chưa phân nhà cung cấp',
                    'short_name'    => 'N/A',
                    'bank_name'     => null,
                    'bank_account'  => null,
                ],
                'items' => [],
                'total' => 0,
            ];
        } else {
            $grouped[$sid] = [
                'info'  => [
                    'supplier_name' => $row['supplier_name'],
                    'short_name'    => $row['sup_short'],
                    'bank_name'     => $row['bank_name'],
                    'bank_account'  => $row['bank_account'],
                ],
                'items' => [],
                'total' => 0,
            ];
        }
    }
    $grouped[$sid]['items'][] = $row;
    $grouped[$sid]['total']  += (float)$row['total_amount'];
}
$grand_total = array_sum(array_column($grouped, 'total'));
$conn->close();

if (empty($rows)) {
    die('Không có dữ liệu để xuất.');
}

// ============================================================
// TẠO SPREADSHEET
// ============================================================
$spreadsheet = new Spreadsheet();
$spreadsheet->removeSheetByIndex(0); // Xoá sheet mặc định

$periodLabel = ($filter_month && $filter_year)
    ? "Tháng {$filter_month}/{$filter_year}"
    : 'Tất cả';

$lastColLetter = 'M'; // 13 cột
$headers = [
    '#', 'Job No', 'HAWB', 'MAWB', 'Tờ khai HQ', 'Ngày lô hàng',
    'Mã CP', 'Nội dung chi phí', 'Số lượng', 'Đơn giá (VND)', 'VAT (%)', 'Thành tiền (VND)', 'Ghi chú'
];
$colWidths = [5, 14, 16, 18, 16, 13, 8, 30, 9, 16, 8, 18, 25];

// ============================================================
// TẠO 1 SHEET CHO MỖI NCC
// ============================================================
foreach ($grouped as $sid => $group) {
    $info         = $group['info'];
    $isUnassigned = ($sid === 'unassigned');

    // Tên sheet (Excel giới hạn 31 ký tự, không cho một số ký tự đặc biệt)
    $sheetTitle = $isUnassigned
        ? 'Chua phan NCC'
        : substr(preg_replace('/[\/\\\?\*\[\]:]/', '_', $info['short_name'] ?: $info['supplier_name']), 0, 31);

    $sheet = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, $sheetTitle);
    $spreadsheet->addSheet($sheet);

    // --- Độ rộng cột ---
    foreach ($colWidths as $i => $w) {
        $sheet->getColumnDimensionByColumn($i + 1)->setWidth($w);
    }

    // ---- Hàng 1: Tiêu đề hệ thống ----
    $sheet->mergeCells("A1:{$lastColLetter}1");
    $sheet->setCellValue('A1', 'FORWARDER SYSTEM – BÁO CÁO CÔNG NỢ PHẢI TRẢ NHÀ CUNG CẤP');
    $sheet->getStyle('A1')->applyFromArray([
        'font'      => ['bold' => true, 'size' => 13, 'color' => ['rgb' => 'FFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1B3A6B']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $sheet->getRowDimension(1)->setRowHeight(26);

    // ---- Hàng 2: Tên NCC ----
    $sheet->mergeCells("A2:{$lastColLetter}2");
    $nccLabel = $isUnassigned
        ? '⚠ Chưa phân nhà cung cấp'
        : 'Nhà cung cấp: ' . strtoupper($info['supplier_name']) .
          ($info['short_name'] ? ' (' . $info['short_name'] . ')' : '');
    $sheet->setCellValue('A2', $nccLabel);
    $sheet->getStyle('A2')->applyFromArray([
        'font'      => ['bold' => true, 'size' => 11,
                        'color' => ['rgb' => $isUnassigned ? '856404' : '1B3A6B']],
        'fill'      => ['fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => $isUnassigned ? 'FFF3CD' : 'DBEAFE']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);
    $sheet->getRowDimension(2)->setRowHeight(20);

    // ---- Hàng 3: Kỳ + ngày xuất ----
    $sheet->mergeCells("A3:{$lastColLetter}3");
    $sheet->setCellValue('A3', 'Kỳ: ' . $periodLabel . '      Xuất ngày: ' . date('d/m/Y H:i'));
    $sheet->getStyle('A3')->applyFromArray([
        'font'      => ['italic' => true, 'size' => 9, 'color' => ['rgb' => '555555']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);

    // ---- Hàng 4: Thông tin ngân hàng ----
    $sheet->mergeCells("A4:{$lastColLetter}4");
    $bankText = '';
    if (!$isUnassigned && ($info['bank_account'] || $info['bank_name'])) {
        $bankText = 'Chuyển khoản: STK ' . ($info['bank_account'] ?? '') .
                    ' – ' . ($info['bank_name'] ?? '') .
                    ' – ' . $info['supplier_name'];
    } elseif ($isUnassigned) {
        $bankText = 'Các khoản phí này chưa được gán nhà cung cấp. Vui lòng cập nhật.';
    }
    $sheet->setCellValue('A4', $bankText);
    $sheet->getStyle('A4')->applyFromArray([
        'font'      => ['bold' => !$isUnassigned, 'italic' => $isUnassigned, 'size' => 9,
                        'color' => ['rgb' => $isUnassigned ? '856404' : '155724']],
        'fill'      => ['fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => $isUnassigned ? 'FFF3CD' : 'D4EDDA']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);

    // ---- Hàng 5: trống ----
    $sheet->getRowDimension(5)->setRowHeight(5);

    // ---- Hàng 6: Header bảng ----
    $headerRow = 6;
    foreach ($headers as $i => $h) {
        $sheet->setCellValueByColumnAndRow($i + 1, $headerRow, $h);
    }
    $sheet->getStyle("A{$headerRow}:{$lastColLetter}{$headerRow}")->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 9],
        'fill'      => ['fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => $isUnassigned ? '6C757D' : '2E75B6']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER, 'wrapText' => true],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                         'color'       => ['rgb' => 'FFFFFF']]],
    ]);
    $sheet->getRowDimension($headerRow)->setRowHeight(20);

    // ---- Dữ liệu ----
    $dataStartRow = 7;
    foreach ($group['items'] as $i => $item) {
        $r = $dataStartRow + $i;

        $sheet->setCellValueByColumnAndRow(1,  $r, $i + 1);
        $sheet->setCellValueByColumnAndRow(2,  $r, $item['job_no']);
        $sheet->setCellValueByColumnAndRow(3,  $r, $item['hawb'] ?: '—');
        $sheet->setCellValueByColumnAndRow(4,  $r, $item['mawb'] ?: '—');
        $sheet->setCellValueByColumnAndRow(5,  $r, $item['customs_declaration_no'] ?: '—');
        $sheet->setCellValueByColumnAndRow(6,  $r, date('d/m/Y', strtotime($item['shipment_date'])));
        $sheet->setCellValueByColumnAndRow(7,  $r, $item['cost_code']);
        $sheet->setCellValueByColumnAndRow(8,  $r, $item['cost_desc']);
        $sheet->setCellValueByColumnAndRow(9,  $r, (float)$item['quantity']);
        $sheet->setCellValueByColumnAndRow(10, $r, (float)$item['unit_price']);
        $sheet->setCellValueByColumnAndRow(11, $r, (float)$item['vat']);
        $sheet->setCellValueByColumnAndRow(12, $r, (float)$item['total_amount']);
        $sheet->setCellValueByColumnAndRow(13, $r, $item['notes'] ?? '');

        // Style hàng
        $rowBg = ($i % 2 === 0) ? 'FFFFFF' : ($isUnassigned ? 'FFFBF0' : 'F2F7FC');
        $sheet->getStyle("A{$r}:{$lastColLetter}{$r}")->applyFromArray([
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $rowBg]],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                             'color'       => ['rgb' => 'DDDDDD']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
            'font'      => ['size' => 9],
        ]);

        // Căn giữa một số cột
        foreach ([1, 6, 7, 9, 11] as $colIdx) {
            $sheet->getStyleByColumnAndRow($colIdx, $r)
                  ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }
        // Căn phải + format số tiền
        foreach ([10, 12] as $colIdx) {
            $sheet->getStyleByColumnAndRow($colIdx, $r)
                  ->getNumberFormat()->setFormatCode('#,##0');
            $sheet->getStyleByColumnAndRow($colIdx, $r)
                  ->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        $sheet->getStyleByColumnAndRow(12, $r)->getFont()->setBold(true);
        $sheet->getStyleByColumnAndRow(12, $r)->getFont()->getColor()->setRGB('C0392B');
    }

    // ---- Hàng tổng NCC ----
    $totalRow = $dataStartRow + count($group['items']);
    $sheet->mergeCells("A{$totalRow}:K{$totalRow}");
    $sheet->setCellValue("A{$totalRow}", 'TỔNG PHẢI TRẢ — ' . strtoupper($info['supplier_name']));
    $sheet->setCellValueByColumnAndRow(12, $totalRow, $group['total']);
    $sheet->setCellValueByColumnAndRow(13, $totalRow, count($group['items']) . ' khoản phí');
    $sheet->getStyleByColumnAndRow(12, $totalRow)->getNumberFormat()->setFormatCode('#,##0');
    $sheet->getStyleByColumnAndRow(12, $totalRow)->getAlignment()
          ->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    $sheet->getStyle("A{$totalRow}:{$lastColLetter}{$totalRow}")->applyFromArray([
        'font'      => ['bold' => true, 'size' => 10, 'color' => ['rgb' => 'FFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => $isUnassigned ? '6C757D' : 'DC3545']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_MEDIUM]],
    ]);
    $sheet->getRowDimension($totalRow)->setRowHeight(20);

    // Freeze + AutoFilter
    $sheet->freezePane("A{$dataStartRow}");
    $sheet->setAutoFilter("A{$headerRow}:{$lastColLetter}" . ($totalRow - 1));
}

// ============================================================
// SHEET TỔNG HỢP (nếu mode = all và có nhiều NCC)
// ============================================================
if ($mode === 'all' && count($grouped) > 1) {
    $summary = new \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet($spreadsheet, 'Tổng hợp');
    $spreadsheet->addSheet($summary, 0); // Để đầu tiên

    // Tiêu đề
    $summary->mergeCells('A1:E1');
    $summary->setCellValue('A1', 'TỔNG HỢP CÔNG NỢ PHẢI TRẢ — ' . strtoupper($periodLabel));
    $summary->getStyle('A1')->applyFromArray([
        'font'      => ['bold' => true, 'size' => 13, 'color' => ['rgb' => 'FFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1B3A6B']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
    ]);
    $summary->getRowDimension(1)->setRowHeight(26);

    $summary->mergeCells('A2:E2');
    $summary->setCellValue('A2', 'Xuất ngày: ' . date('d/m/Y H:i'));
    $summary->getStyle('A2')->applyFromArray([
        'font'      => ['italic' => true, 'size' => 9, 'color' => ['rgb' => '555555']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
    ]);

    // Header
    $summaryHeaders = ['#', 'Nhà cung cấp', 'Tên viết tắt', 'Số khoản phí', 'Tổng phải trả (VND)'];
    $summaryWidths  = [5, 40, 15, 15, 22];
    foreach ($summaryHeaders as $i => $h) {
        $summary->setCellValueByColumnAndRow($i + 1, 3, $h);
        $summary->getColumnDimensionByColumn($i + 1)->setWidth($summaryWidths[$i]);
    }
    $summary->getStyle('A3:E3')->applyFromArray([
        'font'      => ['bold' => true, 'color' => ['rgb' => 'FFFFFF'], 'size' => 10],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '2E75B6']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                         'color'       => ['rgb' => 'FFFFFF']]],
    ]);
    $summary->getRowDimension(3)->setRowHeight(18);

    // Dữ liệu tổng hợp
    $r = 4;
    $idx = 1;
    foreach ($grouped as $sid => $group) {
        $isU = ($sid === 'unassigned');
        $bg  = $isU ? 'FFF3CD' : (($idx % 2 === 0) ? 'F2F7FC' : 'FFFFFF');

        $summary->setCellValueByColumnAndRow(1, $r, $idx);
        $summary->setCellValueByColumnAndRow(2, $r, $group['info']['supplier_name']);
        $summary->setCellValueByColumnAndRow(3, $r, $group['info']['short_name']);
        $summary->setCellValueByColumnAndRow(4, $r, count($group['items']));
        $summary->setCellValueByColumnAndRow(5, $r, $group['total']);

        $summary->getStyle("A{$r}:E{$r}")->applyFromArray([
            'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $bg]],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN,
                                           'color'       => ['rgb' => 'DDDDDD']]],
            'font'    => ['size' => 9, 'italic' => $isU],
        ]);
        $summary->getStyleByColumnAndRow(1, $r)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $summary->getStyleByColumnAndRow(4, $r)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $summary->getStyleByColumnAndRow(5, $r)->getNumberFormat()->setFormatCode('#,##0');
        $summary->getStyleByColumnAndRow(5, $r)->getAlignment()
                ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $summary->getStyleByColumnAndRow(5, $r)->getFont()->setBold(true);
        if (!$isU) {
            $summary->getStyleByColumnAndRow(5, $r)->getFont()->getColor()->setRGB('C0392B');
        }
        $r++;
        $idx++;
    }

    // Hàng tổng
    $summary->setCellValue("A{$r}", 'TỔNG CỘNG');
    $summary->setCellValueByColumnAndRow(4, $r, count($rows));
    $summary->setCellValueByColumnAndRow(5, $r, $grand_total);
    $summary->mergeCells("A{$r}:C{$r}");
    $summary->getStyle("A{$r}:E{$r}")->applyFromArray([
        'font'      => ['bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
        'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'DC3545']],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER,
                        'vertical'   => Alignment::VERTICAL_CENTER],
        'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_MEDIUM]],
    ]);
    $summary->getStyleByColumnAndRow(5, $r)->getNumberFormat()->setFormatCode('#,##0');
    $summary->getStyleByColumnAndRow(5, $r)->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $summary->getRowDimension($r)->setRowHeight(22);

    $summary->freezePane('A4');

    // Active sheet = Tổng hợp
    $spreadsheet->setActiveSheetIndex(0);
}

// ============================================================
// TÊN FILE & XUẤT
// ============================================================
$periodSafe = $month_filter ? str_replace('-', '_', $month_filter) : 'all';

switch ($mode) {
    case 'single':
        $firstGroup = reset($grouped);
        $safeName   = preg_replace('/[^A-Za-z0-9_\-]/', '_',
                          $firstGroup['info']['short_name'] ?: $firstGroup['info']['supplier_name']);
        $fileName   = "CongNo_{$safeName}_{$periodSafe}.xlsx";
        break;
    case 'unassigned':
        $fileName = "CongNo_ChuaPhanNCC_{$periodSafe}.xlsx";
        break;
    case 'all':
    default:
        $fileName = "CongNo_TatCaNCC_{$periodSafe}.xlsx";
        break;
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $fileName . '"');
header('Cache-Control: max-age=0');
header('Pragma: public');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;