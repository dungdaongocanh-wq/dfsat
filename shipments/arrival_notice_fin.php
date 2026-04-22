<?php
/**
 * shipments/arrival_notice.php
 * - Cost Code: dropdown từ arrival_cost_codes, OTHER = nhập tay diễn giải
 * - Dấu . = nghìn, dấu , = thập phân (chuẩn VN)
 * - Nút Xuất Excel → download_arrival.php
 * - Nút Gửi Email  → send_arrival.php
 */

require_once '../config/database.php';
checkLogin();

function h($s): string {
    return htmlspecialchars((string)($s ?? ''), ENT_QUOTES, 'UTF-8');
}

function parseNumber(string $input): float {
    $input = trim($input);
    if ($input === '') return 0.0;
    $hasDot   = strpos($input, '.') !== false;
    $hasComma = strpos($input, ',') !== false;
    if ($hasDot && $hasComma) {
        if (strpos($input, '.') < strpos($input, ',')) {
            $input = str_replace('.', '', $input);
            $input = str_replace(',', '.', $input);
        } else {
            $input = str_replace(',', '', $input);
        }
        return floatval($input);
    }
    if ($hasDot) {
        $parts = explode('.', $input);
        if (count($parts) === 2 && strlen($parts[1]) === 3 && ctype_digit($parts[1]))
            return floatval(str_replace('.', '', $input));
        return floatval($input);
    }
    if ($hasComma) {
        $parts = explode(',', $input);
        if (count($parts) === 2 && strlen($parts[1]) === 3 && ctype_digit($parts[1]))
            return floatval(str_replace(',', '', $input));
        return floatval(str_replace(',', '.', $input));
    }
    return floatval($input);
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: index.php'); exit; }

$conn = getDBConnection();

// Load shipment + customer
$stmt = $conn->prepare(
    "SELECT s.*, c.company_name AS customer_name, c.address AS customer_address,
            c.tax_code AS customer_tax, c.email AS customer_email
     FROM shipments s LEFT JOIN customers c ON c.id = s.customer_id WHERE s.id = ?"
);
$stmt->bind_param('i', $id);
$stmt->execute();
$shipment = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$shipment) { $conn->close(); header('Location: index.php'); exit; }

$messages = [];
$errors   = [];

// ------------------------------------------------------------------
// POST: Save
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {

    $usdRate = parseNumber((string)($_POST['an_exchange_usd'] ?? '0'));
    $eurRate = parseNumber((string)($_POST['an_exchange_eur'] ?? '0'));

    if ($usdRate > 0 && $usdRate < 100)
        $errors[] = 'Tỷ giá USD có vẻ sai (' . number_format($usdRate, 2, ',', '.') . '). Vui lòng nhập đầy đủ, ví dụ: 25.000';
    if ($eurRate > 0 && $eurRate < 100)
        $errors[] = 'Tỷ giá EUR có vẻ sai (' . number_format($eurRate, 2, ',', '.') . '). Vui lòng nhập đầy đủ, ví dụ: 27.000';

    if (empty($errors)) {
        $conn->prepare("UPDATE shipments SET an_exchange_usd=?, an_exchange_eur=? WHERE id=?")
             ->bind_param('ddi', $usdRate, $eurRate, $id);
        $upd = $conn->prepare("UPDATE shipments SET an_exchange_usd=?, an_exchange_eur=? WHERE id=?");
        $upd->bind_param('ddi', $usdRate, $eurRate, $id);
        $upd->execute(); $upd->close();

        $del = $conn->prepare("DELETE FROM arrival_notice_charges WHERE shipment_id=?");
        $del->bind_param('i', $id); $del->execute(); $del->close();

        $groups     = $_POST['charge_group'] ?? [];
        $costCodes  = $_POST['cost_code']    ?? [];
        $descs      = $_POST['description']  ?? [];
        $currencies = $_POST['currency']     ?? [];
        $unitPrices = $_POST['unit_price']   ?? [];
        $quantities = $_POST['quantity']     ?? [];
        $vats       = $_POST['vat']          ?? [];

        $ins = $conn->prepare(
            "INSERT INTO arrival_notice_charges
             (shipment_id,charge_group,cost_code,description,currency,
              unit_price,quantity,amount,exchange_rate,amount_vnd,vat,total_vnd,sort_order)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
        );

        $foreignTotalVnd = 0.0;
        $localTotalVnd   = 0.0;

        foreach ($groups as $i => $group) {
            $group     = in_array($group, ['foreign','local'], true) ? $group : 'local';
            $costCode  = strtoupper(trim($costCodes[$i] ?? ''));
            $desc      = trim($descs[$i] ?? '');
            $currency  = in_array($currencies[$i] ?? '', ['USD','EUR','VND'], true) ? $currencies[$i] : 'USD';
            $unitPrice = parseNumber((string)($unitPrices[$i] ?? '0'));
            $quantity  = parseNumber((string)($quantities[$i] ?? '1'));
            $vat       = parseNumber((string)($vats[$i] ?? '0'));
            $amount    = $unitPrice * $quantity;
            $exRate    = $currency === 'USD' ? $usdRate : ($currency === 'EUR' ? $eurRate : 1.0);
            $amountVnd = $currency === 'VND' ? $amount : $amount * $exRate;
            $totalVnd  = $amountVnd * (1 + $vat / 100);
            $sortOrder = $i;

            $ins->bind_param('issssdddddddi',
                $id, $group, $costCode, $desc, $currency,
                $unitPrice, $quantity, $amount, $exRate, $amountVnd, $vat, $totalVnd, $sortOrder
            );
            $ins->execute();

            if ($group === 'foreign') $foreignTotalVnd += $totalVnd;
            else $localTotalVnd += $totalVnd;
        }
        $ins->close();

      

        // Reload shipment
        $s2 = $conn->prepare(
            "SELECT s.*, c.company_name AS customer_name, c.address AS customer_address,
                    c.tax_code AS customer_tax, c.email AS customer_email
             FROM shipments s LEFT JOIN customers c ON c.id=s.customer_id WHERE s.id=?"
        );
        $s2->bind_param('i', $id); $s2->execute();
        $shipment = $s2->get_result()->fetch_assoc(); $s2->close();

        $messages[] = 'Đã lưu Arrival Notice thành công. Tỷ giá: USD = '
            . number_format($usdRate, 0, ',', '.') . ' | EUR = ' . number_format($eurRate, 0, ',', '.');
    }
}

// ------------------------------------------------------------------
// Load charges
// ------------------------------------------------------------------
$chargeStmt = $conn->prepare(
    "SELECT * FROM arrival_notice_charges WHERE shipment_id=? ORDER BY charge_group, sort_order"
);
$chargeStmt->bind_param('i', $id);
$chargeStmt->execute();
$allCharges = $chargeStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$chargeStmt->close();

// Load danh sách mã Arrival Notice
$arrivalCodes = $conn->query(
    "SELECT code, description, default_currency, default_unit_price
     FROM arrival_cost_codes WHERE status='active' ORDER BY code ASC"
)->fetch_all(MYSQLI_ASSOC);

$conn->close();

$foreignCharges    = array_values(array_filter($allCharges, fn($r) => $r['charge_group'] === 'foreign'));
$localCharges      = array_values(array_filter($allCharges, fn($r) => $r['charge_group'] === 'local'));
$foreignTotalVnd   = array_sum(array_column($foreignCharges, 'total_vnd'));
$localTotalVnd     = array_sum(array_column($localCharges,   'total_vnd'));
$grandTotal        = $foreignTotalVnd + $localTotalVnd;
$currentUsdRate    = floatval($shipment['an_exchange_usd'] ?? 25000);
$currentEurRate    = floatval($shipment['an_exchange_eur'] ?? 27000);
$arrivalCodeList   = array_column($arrivalCodes, 'code');

// Helper: render ô Cost Code (dropdown + ô nhập tay nếu OTHER)
function renderCostCodeCell(array $arrivalCodes, array $arrivalCodeList, string $selectedCode = ''): string {
    $isOther = $selectedCode !== '' && !in_array($selectedCode, $arrivalCodeList);
    $opts    = '<option value="">-- Chọn mã --</option>';
    foreach ($arrivalCodes as $ac) {
        $sel   = (!$isOther && $ac['code'] === $selectedCode) ? 'selected' : '';
        $opts .= sprintf(
            '<option value="%s" data-desc="%s" data-curr="%s" data-price="%s" %s>%s – %s</option>',
            h($ac['code']),
            h($ac['description']),
            h($ac['default_currency']),
            floatval($ac['default_unit_price']),
            $sel,
            h($ac['code']),
            h($ac['description'])
        );
    }
    $selOther = $isOther ? 'selected' : '';
    $opts    .= '<option value="OTHER" ' . $selOther . '>✏️ OTHER (nhập tay)</option>';

    $customVal  = $isOther ? h($selectedCode) : '';
    $customShow = $isOther ? '' : 'display:none';

    return '
    <div style="min-width:180px">
        <select name="cost_code_sel[]" class="form-select form-select-sm cost-code-sel mb-1">' . $opts . '</select>
        <input type="text"
               name="cost_code[]"
               class="form-control form-control-sm cost-code-custom"
               placeholder="Nhập mã tay..."
               value="' . $customVal . '"
               style="' . $customShow . '">
    </div>';
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Giấy Báo Hàng Đến – <?php echo h($shipment['job_no'] ?? $id); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { font-size: 0.875rem; }
        .table-charges th { white-space: nowrap; }
        .section-title { background:#0d6efd; color:#fff; padding:6px 12px; border-radius:4px; font-weight:600; }
        .grand-total   { font-size:1.1rem; font-weight:700; color:#dc3545; }
        .rate-hint     { font-size:.78rem; color:#6c757d; }
        .cost-code-sel { font-size:.8rem; }
    </style>
</head>
<body>
<div class="container-fluid py-3">

    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="index.php">Lô hàng</a></li>
            <li class="breadcrumb-item"><a href="view.php?id=<?php echo $id; ?>"><?php echo h($shipment['job_no'] ?? $id); ?></a></li>
            <li class="breadcrumb-item active">Giấy Báo Hàng Đến</li>
        </ol>
    </nav>

    <?php foreach ($messages as $msg): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle-fill"></i> <?php echo h($msg); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endforeach; ?>
    <?php foreach ($errors as $err): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle-fill"></i> <?php echo h($err); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endforeach; ?>

    <!-- Header -->
    <div class="row mb-3">
        <div class="col-md-6">
            <h5 class="mb-0 fw-bold">CÔNG TY TNHH LIPRO LOGISTICS</h5>
            <small class="text-muted">
                Địa chỉ: No. 6 Lane 1002 Lang Street, Lang Ha Ward, Hanoi<br>
                Email: lipro.logistics@gmail.com | Tel: (+84) 366 666 322
            </small>
        </div>
        <div class="col-md-6 text-md-end">
            <strong>Kính gửi:</strong> <?php echo h($shipment['customer_name'] ?? ''); ?><br>
            <small class="text-muted">
                Địa chỉ: <?php echo h($shipment['customer_address'] ?? ''); ?><br>
                MST: <?php echo h($shipment['customer_tax'] ?? ''); ?>
            </small>
        </div>
    </div>

    <h4 class="text-center fw-bold mb-3">GIẤY BÁO HÀNG ĐẾN / ARRIVAL NOTICE</h4>

    <form method="post" action="arrival_notice.php?id=<?php echo $id; ?>" id="mainForm">
        <input type="hidden" name="action" value="save">

        <!-- Thông tin lô hàng + tỷ giá -->
        <div class="card mb-3">
            <div class="card-header fw-semibold">Thông tin lô hàng</div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label">Shipper</label>
                        <input type="text" class="form-control form-control-sm" value="<?php echo h($shipment['shipper'] ?? ''); ?>" disabled>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">POL</label>
                        <input type="text" class="form-control form-control-sm" value="<?php echo h($shipment['pol'] ?? ''); ?>" disabled>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">POD</label>
                        <input type="text" class="form-control form-control-sm" value="<?php echo h($shipment['pod'] ?? ''); ?>" disabled>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Tàu / Chuyến bay</label>
                        <input type="text" class="form-control form-control-sm" value="<?php echo h($shipment['vessel_flight'] ?? ''); ?>" disabled>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">MAWB</label>
                        <input type="text" class="form-control form-control-sm" value="<?php echo h($shipment['mawb'] ?? ''); ?>" disabled>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">HAWB</label>
                        <input type="text" class="form-control form-control-sm" value="<?php echo h($shipment['hawb'] ?? ''); ?>" disabled>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold text-primary">Tỷ giá USD → VND</label>
                        <input type="text" name="an_exchange_usd" id="usdRate"
                               class="form-control form-control-sm"
                               value="<?php echo $currentUsdRate > 0 ? number_format($currentUsdRate, 0, ',', '.') : '25.000'; ?>"
                               placeholder="Ví dụ: 25.000" autocomplete="off">
                        <div class="rate-hint">Nhập: <kbd>25.000</kbd> hoặc <kbd>25000</kbd></div>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label fw-semibold text-primary">Tỷ giá EUR → VND</label>
                        <input type="text" name="an_exchange_eur" id="eurRate"
                               class="form-control form-control-sm"
                               value="<?php echo $currentEurRate > 0 ? number_format($currentEurRate, 0, ',', '.') : '27.000'; ?>"
                               placeholder="Ví dụ: 27.000" autocomplete="off">
                        <div class="rate-hint">Nhập: <kbd>27.000</kbd> hoặc <kbd>27000</kbd></div>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <div class="p-2 bg-light rounded border w-100">
                            <small class="text-muted d-block">Tỷ giá đang lưu:</small>
                            <span class="fw-bold text-primary">USD = <?php echo number_format($currentUsdRate, 0, ',', '.'); ?> VND</span><br>
                            <span class="fw-bold text-info">EUR = <?php echo number_format($currentEurRate, 0, ',', '.'); ?> VND</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- PHÍ NƯỚC NGOÀI -->
        <div class="mb-3">
            <div class="section-title mb-2">3A. Phí Nước Ngoài (EXW + FREIGHT)</div>
            <div class="table-responsive">
                <table class="table table-bordered table-sm table-charges">
                    <thead class="table-primary">
                        <tr>
                            <th>Cost Code</th>
                            <th>Diễn giải</th>
                            <th>Tiền tệ</th>
                            <th>Đơn giá</th>
                            <th>SL</th>
                            <th>Thành tiền</th>
                            <th>Tỷ giá</th>
                            <th>Thành tiền (VND)</th>
                            <th>VAT (%)</th>
                            <th>Tổng VND</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="foreignBody">
                    <?php foreach ($foreignCharges as $row): ?>
                        <tr>
                            <td><?php echo renderCostCodeCell($arrivalCodes, $arrivalCodeList, $row['cost_code']); ?></td>
                            <td><input type="text" name="description[]" class="form-control form-control-sm desc-field"
                                       value="<?php echo h($row['description']); ?>" style="min-width:160px"></td>
                            <td>
                                <select name="currency[]" class="form-select form-select-sm currency-sel" style="width:75px">
                                    <?php foreach (['USD','EUR','VND'] as $c): ?>
                                    <option value="<?php echo $c; ?>" <?php echo ($row['currency'] ?? 'USD') === $c ? 'selected' : ''; ?>><?php echo $c; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="text" name="unit_price[]" class="form-control form-control-sm unit-price"
                                       value="<?php echo number_format(floatval($row['unit_price']), 2, ',', '.'); ?>" style="width:110px"></td>
                            <td><input type="text" name="quantity[]" class="form-control form-control-sm quantity"
                                       value="<?php echo number_format(floatval($row['quantity']), 2, ',', '.'); ?>" style="width:70px"></td>
                            <td><input type="text" class="form-control form-control-sm amount-field bg-light"
                                       value="<?php echo number_format(floatval($row['amount'] ?? 0), 2, ',', '.'); ?>" readonly style="width:110px"></td>
                            <td><input type="text" class="form-control form-control-sm exrate-field bg-light"
                                       value="<?php echo number_format(floatval($row['exchange_rate'] ?? 0), 0, ',', '.'); ?>" readonly style="width:100px"></td>
                            <td><input type="text" class="form-control form-control-sm amtvnd-field bg-light"
                                       value="<?php echo number_format(floatval($row['amount_vnd'] ?? 0), 0, ',', '.'); ?>" readonly style="width:120px"></td>
                            <td><input type="text" name="vat[]" class="form-control form-control-sm vat-field"
                                       value="<?php echo number_format(floatval($row['vat']), 2, ',', '.'); ?>" style="width:70px"></td>
                            <td><input type="text" class="form-control form-control-sm tvnd-field bg-light fw-bold"
                                       value="<?php echo number_format(floatval($row['total_vnd'] ?? 0), 0, ',', '.'); ?>" readonly style="width:130px"></td>
                            <td>
                                <input type="hidden" name="charge_group[]" value="foreign">
                                <button type="button" class="btn btn-danger btn-sm remove-row"><i class="bi bi-trash"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-warning fw-semibold">
                            <td colspan="9" class="text-end">TỔNG CƯỚC + PHÍ NƯỚC NGOÀI (VND)</td>
                            <td id="foreignTotalVnd"><?php echo number_format($foreignTotalVnd, 0, ',', '.'); ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <button type="button" class="btn btn-outline-primary btn-sm" onclick="addRow('foreignBody','foreign')">
                <i class="bi bi-plus-circle"></i> Thêm phí nước ngoài
            </button>
        </div>

        <!-- PHÍ TẠI VIỆT NAM -->
        <div class="mb-3">
            <div class="section-title mb-2" style="background:#198754;">3B. Phí Tại Việt Nam</div>
            <div class="table-responsive">
                <table class="table table-bordered table-sm table-charges">
                    <thead class="table-success">
                        <tr>
                            <th>Cost Code</th>
                            <th>Diễn giải</th>
                            <th>Tiền tệ</th>
                            <th>Đơn giá</th>
                            <th>SL</th>
                            <th>Thành tiền</th>
                            <th>Tỷ giá</th>
                            <th>Thành tiền (VND)</th>
                            <th>VAT (%)</th>
                            <th>Tổng VND</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="localBody">
                    <?php foreach ($localCharges as $row): ?>
                        <tr>
                            <td><?php echo renderCostCodeCell($arrivalCodes, $arrivalCodeList, $row['cost_code']); ?></td>
                            <td><input type="text" name="description[]" class="form-control form-control-sm desc-field"
                                       value="<?php echo h($row['description']); ?>" style="min-width:160px"></td>
                            <td>
                                <select name="currency[]" class="form-select form-select-sm currency-sel" style="width:75px">
                                    <?php foreach (['USD','EUR','VND'] as $c): ?>
                                    <option value="<?php echo $c; ?>" <?php echo ($row['currency'] ?? 'USD') === $c ? 'selected' : ''; ?>><?php echo $c; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="text" name="unit_price[]" class="form-control form-control-sm unit-price"
                                       value="<?php echo number_format(floatval($row['unit_price']), 2, ',', '.'); ?>" style="width:110px"></td>
                            <td><input type="text" name="quantity[]" class="form-control form-control-sm quantity"
                                       value="<?php echo number_format(floatval($row['quantity']), 2, ',', '.'); ?>" style="width:70px"></td>
                            <td><input type="text" class="form-control form-control-sm amount-field bg-light"
                                       value="<?php echo number_format(floatval($row['amount'] ?? 0), 2, ',', '.'); ?>" readonly style="width:110px"></td>
                            <td><input type="text" class="form-control form-control-sm exrate-field bg-light"
                                       value="<?php echo number_format(floatval($row['exchange_rate'] ?? 0), 0, ',', '.'); ?>" readonly style="width:100px"></td>
                            <td><input type="text" class="form-control form-control-sm amtvnd-field bg-light"
                                       value="<?php echo number_format(floatval($row['amount_vnd'] ?? 0), 0, ',', '.'); ?>" readonly style="width:120px"></td>
                            <td><input type="text" name="vat[]" class="form-control form-control-sm vat-field"
                                       value="<?php echo number_format(floatval($row['vat']), 2, ',', '.'); ?>" style="width:70px"></td>
                            <td><input type="text" class="form-control form-control-sm tvnd-field bg-light fw-bold"
                                       value="<?php echo number_format(floatval($row['total_vnd'] ?? 0), 0, ',', '.'); ?>" readonly style="width:130px"></td>
                            <td>
                                <input type="hidden" name="charge_group[]" value="local">
                                <button type="button" class="btn btn-danger btn-sm remove-row"><i class="bi bi-trash"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-warning fw-semibold">
                            <td colspan="9" class="text-end">TỔNG PHÍ TẠI VIỆT NAM (VND)</td>
                            <td id="localTotalVnd"><?php echo number_format($localTotalVnd, 0, ',', '.'); ?></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <button type="button" class="btn btn-outline-success btn-sm" onclick="addRow('localBody','local')">
                <i class="bi bi-plus-circle"></i> Thêm phí trong nước
            </button>
        </div>

        <!-- Grand total -->
        <div class="text-end mb-3">
            <span class="grand-total">TỔNG THANH TOÁN: <span id="grandTotal"><?php echo number_format($grandTotal, 0, ',', '.'); ?></span> VND</span>
        </div>

        <!-- Thông tin chuyển khoản -->
        <div class="card mb-3">
            <div class="card-header fw-semibold">Thông tin chuyển khoản</div>
            <div class="card-body">
                <table class="table table-sm table-bordered w-auto">
                    <tr><td class="fw-semibold">Số tài khoản / Account No</td><td>9039998888</td></tr>
                    <tr><td class="fw-semibold">Ngân hàng / Bank</td><td>Military Commercial Joint Stock Bank (MB Bank)</td></tr>
                    <tr><td class="fw-semibold">Người thụ hưởng / Beneficiary</td><td>CONG TY TNHH LIPRO LOGISTICS</td></tr>
                </table>
            </div>
        </div>

        <!-- Action buttons -->
        <div class="d-flex gap-2 flex-wrap mb-4">
            <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Lưu</button>
            <a href="download_arrival.php?id=<?php echo $id; ?>" class="btn btn-success" target="_blank">
                <i class="bi bi-file-earmark-excel"></i> Xuất Excel
            </a>
            <a href="send_arrival.php?id=<?php echo $id; ?>" class="btn btn-warning">
                <i class="bi bi-envelope"></i> Gửi Email
            </a>
            <a href="view.php?id=<?php echo $id; ?>" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Quay lại
            </a>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const SHIPMENT_ID = <?php echo $id; ?>;

// Danh sách mã từ PHP → JS (để build dropdown khi addRow)
const ARRIVAL_CODES = <?php echo json_encode(
    array_map(fn($ac) => [
        'code'        => $ac['code'],
        'description' => $ac['description'],
        'currency'    => $ac['default_currency'],
        'price'       => floatval($ac['default_unit_price']),
    ], $arrivalCodes),
    JSON_UNESCAPED_UNICODE
); ?>;

// ================================================================
// Parse số kiểu VN
// ================================================================
function parseVN(val) {
    val = String(val).trim().replace(/\s/g, '');
    if (!val) return 0;
    var hasDot = val.indexOf('.') !== -1, hasComma = val.indexOf(',') !== -1;
    if (hasDot && hasComma) {
        if (val.indexOf('.') < val.indexOf(',')) val = val.replace(/\./g,'').replace(',','.');
        else val = val.replace(/,/g,'');
        return parseFloat(val) || 0;
    }
    if (hasDot) {
        var p = val.split('.');
        if (p.length===2 && p[1].length===3 && /^\d+$/.test(p[1])) return parseFloat(val.replace(/\./g,''))||0;
        return parseFloat(val)||0;
    }
    if (hasComma) {
        var p2 = val.split(',');
        if (p2.length===2 && p2[1].length===3 && /^\d+$/.test(p2[1])) return parseFloat(val.replace(/,/g,''))||0;
        return parseFloat(val.replace(',','.'))||0;
    }
    return parseFloat(val)||0;
}

function fmtVN(num, dec) {
    dec = (dec === undefined) ? 0 : dec;
    return num.toLocaleString('vi-VN', {minimumFractionDigits:dec, maximumFractionDigits:dec});
}

// ================================================================
// Build HTML dropdown (dùng khi addRow)
// ================================================================
function buildCostCodeCell() {
    var opts = '<option value="">-- Chọn mã --</option>';
    ARRIVAL_CODES.forEach(function(ac) {
        opts += '<option value="' + ac.code + '"'
              + ' data-desc="'  + ac.description.replace(/"/g,'&quot;') + '"'
              + ' data-curr="'  + ac.currency + '"'
              + ' data-price="' + ac.price    + '">'
              + ac.code + ' \u2013 ' + ac.description
              + '</option>';
    });
    opts += '<option value="OTHER">\u270F\uFE0F OTHER (nh\u1eadp tay)</option>';
    return '<div style="min-width:180px">'
         + '<select name="cost_code_sel[]" class="form-select form-select-sm cost-code-sel mb-1">' + opts + '</select>'
         + '<input type="text" name="cost_code[]" class="form-control form-control-sm cost-code-custom"'
         + ' placeholder="Nh\u1eadp m\u00e3 tay..." style="display:none">'
         + '</div>';
}

// ================================================================
// Gắn sự kiện dropdown cost code
// ================================================================
function attachCostCodeSelect(tr) {
    var sel       = tr.querySelector('.cost-code-sel');
    var customInp = tr.querySelector('.cost-code-custom');
    var descInput = tr.querySelector('.desc-field');
    var currSel   = tr.querySelector('.currency-sel');
    var unitInput = tr.querySelector('.unit-price');

    if (!sel) return;

    sel.addEventListener('change', function() {
        var code = this.value;

        if (code === 'OTHER') {
            // Hiện ô nhập tay, user tự điền diễn giải
            customInp.style.display = '';
            customInp.value = '';
            // Xóa diễn giải cũ để user nhập tay
            if (descInput) descInput.value = '';
            descInput.removeAttribute('readonly');
            customInp.focus();
            return;
        }

        // Ẩn ô nhập tay, sync giá trị vào name="cost_code[]"
        customInp.style.display = 'none';
        customInp.value = code;

        if (!code) {
            if (descInput) descInput.value = '';
            return;
        }

        // Mã FREIGHT → gọi API để lấy diễn giải có POL/POD/VSL
        if (code === 'FREIGHT') {
            fetch('../api/get_arrival_cost_code.php?code=FREIGHT&shipment_id=' + SHIPMENT_ID)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data.success) {
                        if (descInput) descInput.value = data.description;
                        if (currSel && data.default_currency) currSel.value = data.default_currency;
                        if (unitInput && parseVN(unitInput.value) === 0 && data.default_unit_price > 0)
                            unitInput.value = fmtVN(parseFloat(data.default_unit_price), 2);
                    }
                    if (currSel) currSel.dispatchEvent(new Event('change'));
                })
                .catch(function() {
                    if (currSel) currSel.dispatchEvent(new Event('change'));
                });
            return;
        }

        // Các mã khác: lấy từ data-* của option
        var opt   = sel.options[sel.selectedIndex];
        var desc  = opt.getAttribute('data-desc')  || '';
        var curr  = opt.getAttribute('data-curr')  || 'USD';
        var price = parseFloat(opt.getAttribute('data-price')) || 0;

        if (descInput) descInput.value = desc;
        if (currSel)   currSel.value   = curr;
        if (price > 0 && unitInput && parseVN(unitInput.value) === 0)
            unitInput.value = fmtVN(price, 2);

        if (currSel) currSel.dispatchEvent(new Event('change'));
    });

    // Auto uppercase khi nhập OTHER tay
    if (customInp) {
        customInp.addEventListener('input', function() {
            var pos = this.selectionStart;
            this.value = this.value.toUpperCase();
            this.setSelectionRange(pos, pos);
        });
    }
}

// ================================================================
// Gắn sự kiện tính toán cho 1 row
// ================================================================
function attachRowEvents(tr) {
    var currSel   = tr.querySelector('.currency-sel');
    var unitInput = tr.querySelector('.unit-price');
    var qtyInput  = tr.querySelector('.quantity');
    var vatInput  = tr.querySelector('.vat-field');
    var amtField  = tr.querySelector('.amount-field');
    var exrField  = tr.querySelector('.exrate-field');
    var amtVnd    = tr.querySelector('.amtvnd-field');
    var tvnd      = tr.querySelector('.tvnd-field');
    var removeBtn = tr.querySelector('.remove-row');

    function getRate(cur) {
        var u = parseVN(document.getElementById('usdRate').value);
        var e = parseVN(document.getElementById('eurRate').value);
        return cur === 'USD' ? u : (cur === 'EUR' ? e : 1);
    }

    function recalc() {
        var up   = parseVN(unitInput.value);
        var qty  = parseVN(qtyInput.value);
        var vat  = parseVN(vatInput.value);
        var cur  = currSel.value;
        var rate = getRate(cur);
        var amt  = up * qty;
        var aVnd = cur === 'VND' ? amt : amt * rate;
        var tVnd = aVnd * (1 + vat / 100);
        amtField.value = fmtVN(amt, 2);
        exrField.value = fmtVN(rate, 0);
        amtVnd.value   = fmtVN(Math.round(aVnd), 0);
        tvnd.value     = fmtVN(Math.round(tVnd), 0);
        updateTotals();
    }

    if (unitInput) { unitInput.addEventListener('input', recalc); unitInput.addEventListener('change', recalc); }
    if (qtyInput)  { qtyInput.addEventListener('input',  recalc); qtyInput.addEventListener('change',  recalc); }
    if (vatInput)  { vatInput.addEventListener('input',  recalc); vatInput.addEventListener('change',  recalc); }
    if (currSel)     currSel.addEventListener('change',  recalc);

    // Gắn dropdown cost code
    attachCostCodeSelect(tr);

    if (removeBtn) {
        removeBtn.addEventListener('click', function() {
            tr.remove();
            updateTotals();
        });
    }
}

// ================================================================
// Cập nhật tổng
// ================================================================
function updateTotals() {
    var fVnd = 0, lVnd = 0;
    document.querySelectorAll('#foreignBody tr').forEach(function(tr) {
        var f = tr.querySelector('.tvnd-field');
        if (f) fVnd += parseFloat(f.value.replace(/\./g,'').replace(',','.')) || 0;
    });
    document.querySelectorAll('#localBody tr').forEach(function(tr) {
        var f = tr.querySelector('.tvnd-field');
        if (f) lVnd += parseFloat(f.value.replace(/\./g,'').replace(',','.')) || 0;
    });
    document.getElementById('foreignTotalVnd').textContent = fmtVN(Math.round(fVnd), 0);
    document.getElementById('localTotalVnd').textContent   = fmtVN(Math.round(lVnd), 0);
    document.getElementById('grandTotal').textContent      = fmtVN(Math.round(fVnd + lVnd), 0);
}

// ================================================================
// Thêm row mới
// ================================================================
function addRow(tbodyId, group) {
    var tbody = document.getElementById(tbodyId);
    var tr    = document.createElement('tr');
    tr.innerHTML =
        '<td>' + buildCostCodeCell() + '</td>' +
        '<td><input type="text" name="description[]" class="form-control form-control-sm desc-field" style="min-width:160px"></td>' +
        '<td><select name="currency[]" class="form-select form-select-sm currency-sel" style="width:75px">' +
            '<option value="USD" selected>USD</option>' +
            '<option value="EUR">EUR</option>' +
            '<option value="VND">VND</option>' +
        '</select></td>' +
        '<td><input type="text" name="unit_price[]" class="form-control form-control-sm unit-price" value="0" style="width:110px"></td>' +
        '<td><input type="text" name="quantity[]"   class="form-control form-control-sm quantity"   value="1" style="width:70px"></td>' +
        '<td><input type="text" class="form-control form-control-sm amount-field bg-light" value="0" readonly style="width:110px"></td>' +
        '<td><input type="text" class="form-control form-control-sm exrate-field bg-light" value="0" readonly style="width:100px"></td>' +
        '<td><input type="text" class="form-control form-control-sm amtvnd-field bg-light" value="0" readonly style="width:120px"></td>' +
        '<td><input type="text" name="vat[]" class="form-control form-control-sm vat-field" value="0" style="width:70px"></td>' +
        '<td><input type="text" class="form-control form-control-sm tvnd-field bg-light fw-bold" value="0" readonly style="width:130px"></td>' +
        '<td>' +
            '<input type="hidden" name="charge_group[]" value="' + group + '">' +
            '<button type="button" class="btn btn-danger btn-sm remove-row"><i class="bi bi-trash"></i></button>' +
        '</td>';
    tbody.appendChild(tr);
    attachRowEvents(tr);
    updateTotals();
}

// ================================================================
// Recompute khi đổi tỷ giá
// ================================================================
function recomputeAllRates() {
    document.querySelectorAll('#foreignBody tr, #localBody tr').forEach(function(tr) {
        var c = tr.querySelector('.currency-sel');
        if (c) c.dispatchEvent(new Event('change'));
    });
}

// ================================================================
// Sync cost_code[] trước khi submit
// ================================================================
document.getElementById('mainForm').addEventListener('submit', function() {
    document.querySelectorAll('#foreignBody tr, #localBody tr').forEach(function(tr) {
        var sel       = tr.querySelector('.cost-code-sel');
        var customInp = tr.querySelector('.cost-code-custom');
        if (!sel || !customInp) return;
        // Nếu không phải OTHER → copy giá trị dropdown vào input ẩn
        if (sel.value !== 'OTHER' && sel.value !== '') {
            customInp.value = sel.value;
        }
        // Nếu OTHER → customInp.value đã là giá trị user nhập tay
    });
});

// ================================================================
// Init
// ================================================================
document.querySelectorAll('#foreignBody tr, #localBody tr').forEach(function(tr) {
    attachRowEvents(tr);
});
document.getElementById('usdRate').addEventListener('input', recomputeAllRates);
document.getElementById('eurRate').addEventListener('input', recomputeAllRates);
updateTotals();
</script>
</body>
</html>