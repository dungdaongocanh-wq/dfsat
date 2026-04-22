<?php
require_once '../config/database.php';
checkLogin();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id == 0) {
    header("Location: index.php");
    exit();
}

$conn = getDBConnection();

$stmt = $conn->prepare("SELECT s.*,
                        c.company_name, c.short_name AS customer_short,
                        c.address AS customer_address, c.email AS customer_email,
                        c.phone AS customer_phone, c.tax_code AS customer_tax,
                        c.contact_person AS customer_contact,
                        a.full_name AS created_by_name,
                        lb.full_name AS locked_by_name
                        FROM shipments s
                        LEFT JOIN customers c ON s.customer_id = c.id
                        LEFT JOIN accounts a ON s.created_by = a.id
                        LEFT JOIN accounts lb ON s.locked_by = lb.id
                        WHERE s.id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$shipment = $stmt->get_result()->fetch_assoc();

if (!$shipment) {
    header("Location: index.php");
    exit();
}

// Lấy nhà cung cấp
$stmt_sup = $conn->prepare("SELECT s.* FROM shipment_suppliers ss
                             JOIN suppliers s ON ss.supplier_id = s.id
                             WHERE ss.shipment_id = ?");
$stmt_sup->bind_param("i", $id);
$stmt_sup->execute();
$suppliers = $stmt_sup->get_result();

// Lấy Cost
$stmt_cost = $conn->prepare("SELECT sc.*, cc.code, cc.description,
                              s.short_name AS sup_short, s.supplier_name
                              FROM shipment_costs sc
                              JOIN cost_codes cc ON sc.cost_code_id = cc.id
                              LEFT JOIN suppliers s ON sc.supplier_id = s.id
                              WHERE sc.shipment_id = ?
                              ORDER BY sc.id");
$stmt_cost->bind_param("i", $id);
$stmt_cost->execute();
$costs = $stmt_cost->get_result();

// ✅ Lấy Sell — thêm is_pob
$stmt_sell = $conn->prepare("SELECT ss.*, cc.code, cc.description
                              FROM shipment_sells ss
                              JOIN cost_codes cc ON ss.cost_code_id = cc.id
                              WHERE ss.shipment_id = ?
                              ORDER BY ss.id");
$stmt_sell->bind_param("i", $id);
$stmt_sell->execute();
$sells = $stmt_sell->get_result();

// Tổng
$total_cost = $conn->query("SELECT COALESCE(SUM(total_amount),0) t FROM shipment_costs  WHERE shipment_id=$id")->fetch_assoc()['t'];
$total_sell = $conn->query("SELECT COALESCE(SUM(total_amount),0) t FROM shipment_sells  WHERE shipment_id=$id")->fetch_assoc()['t'];
// ✅ Tổng chi hộ + tổng xuất HĐ VAT
$total_pob  = $conn->query("SELECT COALESCE(SUM(total_amount),0) t FROM shipment_sells  WHERE shipment_id=$id AND is_pob=1")->fetch_assoc()['t'];
$total_vat_invoice = $total_sell - $total_pob;

$profit     = $total_sell - $total_cost;
$profit_pct = $total_sell > 0 ? round($profit / $total_sell * 100, 1) : 0;

$conn->close();

$statusBadge = [
    'pending'    => ['color' => 'warning',  'text' => 'Chờ xử lý',       'icon' => 'hourglass-split'],
    'in_transit' => ['color' => 'primary',  'text' => 'Đang vận chuyển', 'icon' => 'truck'],
    'arrived'    => ['color' => 'info',     'text' => 'Đã đến',          'icon' => 'geo-alt'],
    'cleared'    => ['color' => 'success',  'text' => 'Đã thông quan',   'icon' => 'check-circle'],
    'delivered'  => ['color' => 'dark',     'text' => 'Đã giao',         'icon' => 'box-seam'],
    'cancelled'  => ['color' => 'danger',   'text' => 'Đã hủy',          'icon' => 'x-circle'],
];
$badge = $statusBadge[$shipment['status']] ?? ['color' => 'secondary', 'text' => $shipment['status'], 'icon' => 'circle'];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chi tiết - <?php echo htmlspecialchars($shipment['job_no']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .section-title {
            font-size: .78rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #6c757d;
            border-bottom: 2px solid #dee2e6;
            padding-bottom: 5px;
            margin-bottom: 12px;
        }
        .info-row {
            display: flex;
            padding: 5px 0;
            border-bottom: 1px dashed #f0f0f0;
        }
        .info-label {
            min-width: 145px;
            font-size: .82rem;
            color: #6c757d;
            font-weight: 600;
        }
        .info-value {
            font-size: .88rem;
            flex: 1;
        }
        .job-no-display {
            font-size: 1.8rem;
            font-weight: 800;
            color: #0d6efd;
            letter-spacing: 1px;
        }
        .finance-card {
            border-radius: 10px;
            padding: 12px 15px;
            text-align: center;
        }
        .finance-card .amount {
            font-size: 1.2rem;
            font-weight: 700;
        }
        .table-cost thead th,
        .table-sell thead th {
            font-size: .78rem;
            padding: 6px 8px;
            white-space: nowrap;
        }
        .table-cost tbody td,
        .table-sell tbody td {
            font-size: .82rem;
            padding: 5px 8px;
            vertical-align: middle;
        }
        /* ✅ Dòng chi hộ */
        .row-pob { background: #fffbeb !important; }
        .pob-badge {
            font-size: .68rem;
            padding: 2px 6px;
            border-radius: 10px;
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fcd34d;
            white-space: nowrap;
        }
        .trucking-note {
            font-size: .75rem;
            color: #6b7280;
            font-style: italic;
        }
        @media print {
            .print-hide { display: none !important; }
            .card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
            body { font-size: 12px; }
        }
    </style>
</head>
<body class="bg-light">

    <!-- NAVBAR -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top print-hide">
        <div class="container-fluid">
            <a class="navbar-brand" href="../dashboard.php">
                <i class="bi bi-box-seam"></i> Forwarder System
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item"><a class="nav-link" href="../dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="../customers/index.php">Khách hàng</a></li>
                    <li class="nav-item"><a class="nav-link active" href="index.php">Lô hàng</a></li>
                    <li class="nav-item"><a class="nav-link" href="../suppliers/index.php">Nhà cung cấp</a></li>
                    <?php if ($_SESSION['role'] == 'admin'): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            Quản trị
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="../accounts/index.php">Tài khoản</a></li>
                            <li><a class="dropdown-item" href="../cost_codes/index.php">Mã chi phí</a></li>
                        </ul>
                    </li>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($_SESSION['full_name']); ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="../logout.php">Đăng xuất</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-3 pb-5">

        <!-- THÔNG BÁO -->
        <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show py-2 print-hide">
            <i class="bi bi-check-circle-fill"></i>
            <?php
                if ($_GET['success'] == 'updated')  echo 'Cập nhật lô hàng thành công!';
                if ($_GET['success'] == 'locked')   echo '<strong>Lô hàng đã được KHÓA thành công!</strong>';
                if ($_GET['success'] == 'unlocked') echo '<strong>Lô hàng đã được MỞ KHÓA!</strong>';
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- HEADER -->
        <div class="d-flex justify-content-between align-items-start mb-3 print-hide">
            <div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="job-no-display"><?php echo htmlspecialchars($shipment['job_no']); ?></span>
                    <span class="badge bg-<?php echo $badge['color']; ?> fs-6">
                        <i class="bi bi-<?php echo $badge['icon']; ?>"></i> <?php echo $badge['text']; ?>
                    </span>
                    <?php if ($shipment['is_locked'] == 'yes'): ?>
                        <span class="badge bg-danger fs-6"><i class="bi bi-lock-fill"></i> Đã khóa</span>
                    <?php else: ?>
                        <span class="badge bg-success fs-6"><i class="bi bi-unlock"></i> Chưa khóa</span>
                    <?php endif; ?>
                </div>
                <small class="text-muted">
                    Tạo: <?php echo date('d/m/Y H:i', strtotime($shipment['created_at'])); ?>
                    by <strong><?php echo htmlspecialchars($shipment['created_by_name']); ?></strong>
                    &nbsp;|&nbsp;
                    Cập nhật: <?php echo date('d/m/Y H:i', strtotime($shipment['updated_at'])); ?>
                </small>
            </div>

            <!-- BUTTONS -->
            <div class="d-flex gap-2 flex-wrap justify-content-end">
                <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-printer"></i> In
                </button>
                <a href="export_debit.php?id=<?php echo $id; ?>" class="btn btn-success btn-sm">
                    <i class="bi bi-file-earmark-excel"></i> Xuất Debit Note
                </a>
                <a href="export_invoice.php?id=<?php echo $id; ?>" class="btn btn-warning btn-sm">
                    <i class="bi bi-file-earmark-spreadsheet"></i> Xuất Hoá Đơn
                </a>
                <a href="vat_invoice.php?id=<?php echo $id; ?>" class="btn btn-danger btn-sm">
                    <i class="bi bi-receipt-cutoff"></i> Hóa đơn VAT
                    <?php if (!empty($shipment['vat_invoice_no'])): ?>
                        <span class="badge bg-light text-dark ms-1"><?php echo htmlspecialchars($shipment['vat_invoice_no']); ?></span>
                    <?php elseif (!empty($shipment['vat_invoice_status'])): ?>
                        <span class="badge bg-warning text-dark ms-1"><?php echo $shipment['vat_invoice_status']; ?></span>
                    <?php endif; ?>
                </a>
                <?php if ($_SESSION['role'] == 'admin'): ?>
                    <?php if ($shipment['is_locked'] == 'no'): ?>
                    <a href="lock.php?id=<?php echo $id; ?>&action=lock" class="btn btn-danger btn-sm">
                        <i class="bi bi-lock-fill"></i> Khóa lô hàng
                    </a>
                    <?php else: ?>
                    <a href="lock.php?id=<?php echo $id; ?>&action=unlock" class="btn btn-success btn-sm">
                        <i class="bi bi-unlock-fill"></i> Mở khóa
                    </a>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($shipment['is_locked'] == 'no' || $_SESSION['role'] == 'admin'): ?>
                <a href="edit.php?id=<?php echo $id; ?>" class="btn btn-warning btn-sm">
                    <i class="bi bi-pencil"></i> Sửa
                </a>
                <?php endif; ?>
                <a href="index.php" class="btn btn-primary btn-sm">
                    <i class="bi bi-arrow-left"></i> Danh sách
                </a>
                <a href="send_debit.php?id=<?php echo $id; ?>" class="btn btn-primary btn-sm">
                    <i class="bi bi-envelope-fill"></i> Gửi Debit Note
                </a>
            </div>
        </div>

        <!-- TÀI CHÍNH -->
        <div class="row g-2 mb-3 print-hide">
            <div class="col-6 col-md-3">
                <div class="finance-card bg-danger text-white">
                    <small><i class="bi bi-cash-stack"></i> Tổng Chi phí (COST)</small>
                    <div class="amount"><?php echo number_format($total_cost, 0, ',', '.'); ?></div>
                    <small>VND</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="finance-card bg-success text-white">
                    <small><i class="bi bi-currency-dollar"></i> Tổng Doanh thu (SELL)</small>
                    <div class="amount"><?php echo number_format($total_sell, 0, ',', '.'); ?></div>
                    <small>VND</small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="finance-card <?php echo $profit >= 0 ? 'bg-primary' : 'bg-warning'; ?> text-white">
                    <small><i class="bi bi-graph-up"></i> Lợi nhuận</small>
                    <div class="amount">
                        <?php echo ($profit >= 0 ? '+' : '') . number_format($profit, 0, ',', '.'); ?>
                    </div>
                    <small><?php echo $profit_pct; ?>% | <?php echo $profit >= 0 ? '✅ Lãi' : '❌ Lỗ'; ?></small>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="finance-card bg-secondary text-white">
                    <small><i class="bi bi-box-seam"></i> Hàng hóa</small>
                    <div class="amount"><?php echo number_format($shipment['packages']); ?> kiện</div>
                    <small>
                        GW: <?php echo number_format($shipment['gw'] ?? 0, 1); ?> kg
                        | CW: <?php echo number_format($shipment['cw'] ?? 0, 1); ?>
                    </small>
                </div>
            </div>
        </div>

        <div class="row g-3">

            <!-- ===================== CỘT TRÁI ===================== -->
            <div class="col-lg-8">

                <!-- THÔNG TIN VẬN ĐƠN -->
                <div class="card shadow-sm mb-3">
                    <div class="card-body">
                        <p class="section-title">
                            <i class="bi bi-file-earmark-text"></i> Thông tin vận đơn
                        </p>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-row">
                                    <span class="info-label">MAWB</span>
                                    <span class="info-value fw-bold"><?php echo htmlspecialchars($shipment['mawb'] ?? '—'); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">HAWB</span>
                                    <span class="info-value fw-bold"><?php echo htmlspecialchars($shipment['hawb'] ?? '—'); ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Số tờ khai</span>
                                    <span class="info-value"><?php echo htmlspecialchars($shipment['customs_declaration_no'] ?? '') ?: '—'; ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Ngày hàng đến</span>
                                    <span class="info-value">
                                        <?php echo !empty($shipment['arrival_date'])
                                            ? '<strong>' . date('d/m/Y', strtotime($shipment['arrival_date'])) . '</strong>'
                                            : '—'; ?>
                                    </span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-row">
                                    <span class="info-label">VSL / FLIGHT</span>
                                    <span class="info-value"><?php echo htmlspecialchars($shipment['vessel_flight'] ?? '') ?: '—'; ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">POL → POD</span>
                                    <span class="info-value">
                                        <?php if (!empty($shipment['pol']) || !empty($shipment['pod'])): ?>
                                            <strong class="text-danger"><?php echo htmlspecialchars($shipment['pol'] ?? ''); ?></strong>
                                            <i class="bi bi-arrow-right text-muted"></i>
                                            <strong class="text-success"><?php echo htmlspecialchars($shipment['pod'] ?? ''); ?></strong>
                                        <?php else: ?>—<?php endif; ?>
                                    </span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Kho hàng</span>
                                    <span class="info-value"><?php echo htmlspecialchars($shipment['warehouse'] ?? '') ?: '—'; ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Cont / Seal</span>
                                    <span class="info-value fw-bold text-primary"><?php echo htmlspecialchars($shipment['cont_seal'] ?? '') ?: '—'; ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- THÔNG TIN HÀNG HÓA -->
                <div class="card shadow-sm mb-3">
                    <div class="card-body">
                        <p class="section-title">
                            <i class="bi bi-box-seam"></i> Thông tin hàng hóa
                        </p>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-row">
                                    <span class="info-label">Shipper</span>
                                    <span class="info-value"><?php echo htmlspecialchars($shipment['shipper'] ?? '') ?: '—'; ?></span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">CNEE</span>
                                    <span class="info-value"><?php echo htmlspecialchars($shipment['cnee'] ?? '') ?: '—'; ?></span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-row">
                                    <span class="info-label">Số kiện</span>
                                    <span class="info-value"><strong><?php echo number_format($shipment['packages'] ?? 0); ?></strong> kiện</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">GW</span>
                                    <span class="info-value"><strong><?php echo number_format($shipment['gw'] ?? 0, 2); ?></strong> kg</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">CW / CBM</span>
                                    <span class="info-value"><strong><?php echo number_format($shipment['cw'] ?? 0, 2); ?></strong></span>
                                </div>
                            </div>
                        </div>
                        <?php if (!empty($shipment['notes'])): ?>
                        <div class="mt-2 p-2 bg-light rounded">
                            <small class="text-muted"><i class="bi bi-chat-left-text"></i> <strong>Ghi chú:</strong></small>
                            <p class="mb-0 small"><?php echo nl2br(htmlspecialchars($shipment['notes'])); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- COST -->
                <div class="card shadow-sm mb-3">
                    <div class="card-header bg-danger text-white py-2 d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-cash-stack"></i> Chi phí đầu vào (COST)</span>
                        <?php if ($shipment['is_locked'] == 'no' || $_SESSION['role'] == 'admin'): ?>
                        <a href="../shipment_costs/manage.php?shipment_id=<?php echo $id; ?>"
                           class="btn btn-sm btn-light print-hide">
                            <i class="bi bi-pencil"></i> Quản lý
                        </a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered table-cost mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Mã CP</th>
                                        <th>Nội dung</th>
                                        <th class="text-center">SL</th>
                                        <th class="text-end">Đơn giá</th>
                                        <th class="text-center">VAT%</th>
                                        <th class="text-end">Thành tiền</th>
                                        <th>NCC</th>
                                        <th>Ghi chú</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($costs->num_rows > 0):
                                        $i = 1;
                                        while ($c = $costs->fetch_assoc()):
                                    ?>
                                        <tr>
                                            <td class="text-muted"><?php echo $i++; ?></td>
                                            <td><span class="badge bg-danger"><?php echo htmlspecialchars($c['code']); ?></span></td>
                                            <td><?php echo htmlspecialchars($c['description']); ?></td>
                                            <td class="text-center"><?php echo number_format($c['quantity'], 2); ?></td>
                                            <td class="text-end"><?php echo number_format($c['unit_price'], 0, ',', '.'); ?></td>
                                            <td class="text-center"><?php echo $c['vat']; ?>%</td>
                                            <td class="text-end fw-bold text-danger"><?php echo number_format($c['total_amount'], 0, ',', '.'); ?></td>
                                            <td>
                                                <?php if (!empty($c['sup_short'])): ?>
                                                    <span class="badge bg-warning text-dark"><?php echo htmlspecialchars($c['sup_short']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td><small class="text-muted"><?php echo htmlspecialchars($c['notes'] ?? ''); ?></small></td>
                                        </tr>
                                    <?php endwhile; ?>
                                        <tr class="table-danger fw-bold">
                                            <td colspan="6" class="text-end">TỔNG COST:</td>
                                            <td class="text-end text-danger"><?php echo number_format($total_cost, 0, ',', '.'); ?></td>
                                            <td colspan="2"></td>
                                        </tr>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted py-3">
                                                <i class="bi bi-inbox"></i> Chưa có chi phí.
                                                <?php if ($shipment['is_locked'] == 'no' || $_SESSION['role'] == 'admin'): ?>
                                                    <a href="../shipment_costs/manage.php?shipment_id=<?php echo $id; ?>" class="print-hide">Thêm ngay</a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- ✅ SELL — CÓ CỘT CHI HỘ -->
                <div class="card shadow-sm mb-3">
                    <div class="card-header bg-success text-white py-2 d-flex justify-content-between align-items-center">
                        <span>
                            <i class="bi bi-currency-dollar"></i> Doanh thu bán ra (SELL)
                            <?php if ($total_pob > 0): ?>
                                <span class="badge ms-2" style="background:#fcd34d;color:#92400e;font-size:.72rem;">
                                    <i class="bi bi-arrow-left-right"></i>
                                    Chi hộ: <?php echo number_format($total_pob, 0, ',', '.'); ?>
                                </span>
                            <?php endif; ?>
                        </span>
                        <?php if ($shipment['is_locked'] == 'no' || $_SESSION['role'] == 'admin'): ?>
                        <a href="../shipment_sells/manage.php?shipment_id=<?php echo $id; ?>"
                           class="btn btn-sm btn-light print-hide">
                            <i class="bi bi-pencil"></i> Quản lý
                        </a>
                        <?php endif; ?>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-bordered table-sell mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Mã CP</th>
                                        <th>Nội dung</th>
                                        <th class="text-center">SL</th>
                                        <th class="text-end">Đơn giá</th>
                                        <th class="text-center">VAT%</th>
                                        <th class="text-end">Thành tiền</th>
                                        <!-- ✅ CỘT CHI HỘ -->
                                        <th class="text-center print-hide" title="Chi hộ = không xuất HĐ VAT">
                                            <i class="bi bi-arrow-left-right"></i> Chi hộ
                                        </th>
                                        <th>Ghi chú</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($sells->num_rows > 0):
                                        $i = 1;
                                        while ($s = $sells->fetch_assoc()):
                                            $is_pob      = intval($s['is_pob'] ?? 0);
                                            $is_trucking = stripos($s['code'], 'TRUCK') !== false;
                                    ?>
                                        <!-- ✅ Dòng chi hộ có nền vàng nhạt -->
                                        <tr class="<?php echo $is_pob ? 'row-pob' : ''; ?>">
                                            <td class="text-muted"><?php echo $i++; ?></td>
                                            <td>
                                                <span class="badge bg-success">
                                                    <?php echo htmlspecialchars($s['code']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php
                                                // ✅ Trucking: ghép ghi chú vào tên dịch vụ
                                                if ($is_trucking && !empty($s['notes'])) {
                                                    echo htmlspecialchars($s['description'])
                                                       . ' <span class="trucking-note">('
                                                       . htmlspecialchars($s['notes'])
                                                       . ')</span>';
                                                } else {
                                                    echo htmlspecialchars($s['description']);
                                                }
                                                ?>
                                            </td>
                                            <td class="text-center"><?php echo number_format($s['quantity'], 2); ?></td>
                                            <td class="text-end"><?php echo number_format($s['unit_price'], 0, ',', '.'); ?></td>
                                            <td class="text-center"><?php echo $s['vat']; ?>%</td>
                                            <td class="text-end fw-bold text-success">
                                                <?php echo number_format($s['total_amount'], 0, ',', '.'); ?>
                                            </td>
                                            <!-- ✅ CỘT CHI HỘ -->
                                            <td class="text-center print-hide">
                                                <?php if ($is_pob): ?>
                                                    <span class="pob-badge">
                                                        <i class="bi bi-check-circle-fill text-warning"></i> Chi hộ
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo !$is_trucking ? htmlspecialchars($s['notes'] ?? '') : ''; ?>
                                                </small>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>

                                        <!-- Tổng SELL -->
                                        <tr class="table-success fw-bold">
                                            <td colspan="6" class="text-end">TỔNG SELL:</td>
                                            <td class="text-end text-success"><?php echo number_format($total_sell, 0, ',', '.'); ?></td>
                                            <td colspan="2"></td>
                                        </tr>

                                        <?php if ($total_pob > 0): ?>
                                        <!-- ✅ Dòng chi hộ -->
                                        <tr style="background:#fffbeb;" class="print-hide">
                                            <td colspan="6" class="text-end text-muted small">
                                                <i class="bi bi-arrow-left-right text-warning"></i>
                                                Trong đó Chi hộ (không xuất HĐ VAT):
                                            </td>
                                            <td class="text-end" style="color:#92400e;">
                                                <?php echo number_format($total_pob, 0, ',', '.'); ?>
                                            </td>
                                            <td colspan="2"></td>
                                        </tr>
                                        <!-- ✅ Dòng tổng xuất HĐ VAT -->
                                        <tr style="background:#dcfce7;" class="print-hide">
                                            <td colspan="6" class="text-end fw-bold small">
                                                <i class="bi bi-receipt-cutoff text-success"></i>
                                                Tổng xuất Hoá đơn VAT:
                                            </td>
                                            <td class="text-end fw-bold text-success">
                                                <?php echo number_format($total_vat_invoice, 0, ',', '.'); ?>
                                            </td>
                                            <td colspan="2"></td>
                                        </tr>
                                        <?php endif; ?>

                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted py-3">
                                                <i class="bi bi-inbox"></i> Chưa có doanh thu.
                                                <?php if ($shipment['is_locked'] == 'no' || $_SESSION['role'] == 'admin'): ?>
                                                    <a href="../shipment_sells/manage.php?shipment_id=<?php echo $id; ?>" class="print-hide">Thêm ngay</a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- TỔNG HỢP LỢI NHUẬN -->
                <div class="card shadow-sm mb-3">
                    <div class="card-header bg-primary text-white py-2">
                        <i class="bi bi-calculator"></i> Tổng hợp lợi nhuận
                    </div>
                    <div class="card-body p-0">
                        <table class="table table-bordered mb-0">
                            <tr>
                                <td width="60%" class="fw-bold">Tổng chi phí (COST):</td>
                                <td class="text-end text-danger fw-bold"><?php echo number_format($total_cost, 0, ',', '.'); ?> VND</td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Tổng doanh thu (SELL):</td>
                                <td class="text-end text-success fw-bold"><?php echo number_format($total_sell, 0, ',', '.'); ?> VND</td>
                            </tr>
                            <?php if ($total_pob > 0): ?>
                            <tr style="background:#fffbeb;">
                                <td class="text-muted small">
                                    <i class="bi bi-arrow-left-right text-warning"></i> Trong đó Chi hộ (POB):
                                </td>
                                <td class="text-end small" style="color:#92400e;"><?php echo number_format($total_pob, 0, ',', '.'); ?> VND</td>
                            </tr>
                            <tr style="background:#dcfce7;">
                                <td class="fw-bold">
                                    <i class="bi bi-receipt-cutoff text-success"></i> Tổng xuất HĐ VAT:
                                </td>
                                <td class="text-end text-success fw-bold"><?php echo number_format($total_vat_invoice, 0, ',', '.'); ?> VND</td>
                            </tr>
                            <?php endif; ?>
                            <tr class="<?php echo $profit >= 0 ? 'table-success' : 'table-danger'; ?>">
                                <td class="fw-bold">Lợi nhuận (SELL - COST):</td>
                                <td class="text-end fw-bold <?php echo $profit >= 0 ? 'text-success' : 'text-danger'; ?>">
                                    <?php echo ($profit >= 0 ? '+' : '') . number_format($profit, 0, ',', '.'); ?> VND
                                    <span class="badge <?php echo $profit >= 0 ? 'bg-success' : 'bg-danger'; ?> ms-1">
                                        <?php echo $profit_pct; ?>%
                                    </span>
                                    <?php echo $profit >= 0 ? '✅' : '❌'; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

            </div><!-- /col-lg-8 -->

            <!-- ===================== CỘT PHẢI ===================== -->
            <div class="col-lg-4">

                <!-- KHÁCH HÀNG -->
                <div class="card shadow-sm mb-3">
                    <div class="card-header bg-info text-white py-2">
                        <i class="bi bi-people"></i> Khách hàng
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-2">
                            <span class="badge bg-info fs-5 px-3"><?php echo htmlspecialchars($shipment['customer_short'] ?? ''); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Tên công ty</span>
                            <span class="info-value fw-bold"><?php echo htmlspecialchars($shipment['company_name'] ?? ''); ?></span>
                        </div>
                        <?php if (!empty($shipment['customer_tax'])): ?>
                        <div class="info-row">
                            <span class="info-label">MST</span>
                            <span class="info-value"><?php echo htmlspecialchars($shipment['customer_tax']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($shipment['customer_address'])): ?>
                        <div class="info-row">
                            <span class="info-label"><i class="bi bi-geo-alt"></i> Địa chỉ</span>
                            <span class="info-value small"><?php echo htmlspecialchars($shipment['customer_address']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($shipment['customer_phone'])): ?>
                        <div class="info-row">
                            <span class="info-label"><i class="bi bi-telephone"></i> Điện thoại</span>
                            <span class="info-value"><?php echo htmlspecialchars($shipment['customer_phone']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($shipment['customer_email'])): ?>
                        <div class="info-row">
                            <span class="info-label"><i class="bi bi-envelope"></i> Email</span>
                            <span class="info-value small"><?php echo htmlspecialchars($shipment['customer_email']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($shipment['customer_contact'])): ?>
                        <div class="info-row">
                            <span class="info-label"><i class="bi bi-person"></i> Người LH</span>
                            <span class="info-value"><?php echo htmlspecialchars($shipment['customer_contact']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- NHÀ CUNG CẤP -->
                <div class="card shadow-sm mb-3">
                    <div class="card-header bg-warning text-dark py-2">
                        <i class="bi bi-truck"></i> Nhà cung cấp
                    </div>
                    <div class="card-body">
                        <?php if ($suppliers->num_rows > 0):
                            while ($s = $suppliers->fetch_assoc()):
                        ?>
                            <div class="mb-2 pb-2 border-bottom">
                                <span class="badge bg-warning text-dark mb-1"><?php echo htmlspecialchars($s['short_name']); ?></span>
                                <div class="info-row">
                                    <span class="info-label">Tên NCC</span>
                                    <span class="info-value small fw-bold"><?php echo htmlspecialchars($s['supplier_name']); ?></span>
                                </div>
                                <?php if (!empty($s['phone'])): ?>
                                <div class="info-row">
                                    <span class="info-label"><i class="bi bi-telephone"></i> SĐT</span>
                                    <span class="info-value small"><?php echo htmlspecialchars($s['phone']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($s['bank_name'])): ?>
                                <div class="info-row">
                                    <span class="info-label"><i class="bi bi-bank"></i> Ngân hàng</span>
                                    <span class="info-value small"><?php echo htmlspecialchars($s['bank_name']); ?></span>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($s['bank_account'])): ?>
                                <div class="info-row">
                                    <span class="info-label"><i class="bi bi-credit-card"></i> Số TK</span>
                                    <span class="info-value small fw-bold"><?php echo htmlspecialchars($s['bank_account']); ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php endwhile; ?>
                        <?php else: ?>
                            <p class="text-muted text-center small py-2"><i class="bi bi-info-circle"></i> Chưa có nhà cung cấp</p>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- THÔNG TIN KHÓA -->
                <?php if ($shipment['is_locked'] == 'yes'): ?>
                <div class="card shadow-sm mb-3 border-danger">
                    <div class="card-header bg-danger text-white py-2">
                        <i class="bi bi-lock-fill"></i> Thông tin Khóa
                    </div>
                    <div class="card-body">
                        <div class="info-row">
                            <span class="info-label">Số hóa đơn</span>
                            <span class="info-value fw-bold text-danger fs-5"><?php echo htmlspecialchars($shipment['invoice_no'] ?? ''); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Ngày xuất HĐ</span>
                            <span class="info-value">
                                <?php echo !empty($shipment['invoice_date']) ? date('d/m/Y', strtotime($shipment['invoice_date'])) : '—'; ?>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Thời gian khóa</span>
                            <span class="info-value small">
                                <?php echo !empty($shipment['locked_at']) ? date('d/m/Y H:i', strtotime($shipment['locked_at'])) : '—'; ?>
                            </span>
                        </div>
                        <?php if (!empty($shipment['locked_by_name'])): ?>
                        <div class="info-row">
                            <span class="info-label">Người khóa</span>
                            <span class="info-value small"><?php echo htmlspecialchars($shipment['locked_by_name']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($_SESSION['role'] == 'admin'): ?>
                        <div class="mt-2 print-hide">
                            <a href="lock.php?id=<?php echo $id; ?>&action=unlock" class="btn btn-success btn-sm w-100">
                                <i class="bi bi-unlock-fill"></i> Mở khóa lô hàng này
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- THÔNG TIN HỆ THỐNG -->
                <div class="card shadow-sm mb-3">
                    <div class="card-header bg-secondary text-white py-2">
                        <i class="bi bi-info-circle"></i> Thông tin hệ thống
                    </div>
                    <div class="card-body">
                        <div class="info-row">
                            <span class="info-label">Người tạo</span>
                            <span class="info-value"><?php echo htmlspecialchars($shipment['created_by_name'] ?? ''); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Ngày tạo</span>
                            <span class="info-value"><?php echo date('d/m/Y H:i', strtotime($shipment['created_at'])); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Cập nhật lúc</span>
                            <span class="info-value"><?php echo date('d/m/Y H:i', strtotime($shipment['updated_at'])); ?></span>
                        </div>
                    </div>
                </div>

                <!-- QUICK ACTIONS -->
                <div class="card shadow-sm print-hide">
                    <div class="card-header bg-light py-2">
                        <i class="bi bi-lightning"></i> Thao tác nhanh
                    </div>
                    <div class="card-body p-2">
                        <div class="d-grid gap-2">
                            <a href="export_debit.php?id=<?php echo $id; ?>" class="btn btn-success btn-sm">
                                <i class="bi bi-file-earmark-excel"></i> Xuất Debit Note
                            </a>
                           
                            <a href="vat_invoice.php?id=<?php echo $id; ?>" class="btn btn-outline-danger btn-sm">
                                <i class="bi bi-receipt-cutoff"></i> Hóa đơn VAT
                            </a>
                            <?php if ($shipment['is_locked'] == 'no' || $_SESSION['role'] == 'admin'): ?>
                            <a href="edit.php?id=<?php echo $id; ?>" class="btn btn-outline-warning btn-sm">
                                <i class="bi bi-pencil"></i> Sửa lô hàng
                            </a>
                            <?php endif; ?>
                            
                           
                            <?php if ($_SESSION['role'] == 'admin'): ?>
                                <?php if ($shipment['is_locked'] == 'no'): ?>
                                <a href="lock.php?id=<?php echo $id; ?>&action=lock" class="btn btn-danger btn-sm">
                                    <i class="bi bi-lock-fill"></i> Khóa lô hàng
                                </a>
                                <?php else: ?>
                                <a href="lock.php?id=<?php echo $id; ?>&action=unlock" class="btn btn-success btn-sm">
                                    <i class="bi bi-unlock-fill"></i> Mở khóa lô hàng
                                </a>
                                <?php endif; ?>
                                <a href="delete.php?id=<?php echo $id; ?>"
                                   class="btn btn-outline-danger btn-sm"
                                   onclick="return confirm('Xóa lô hàng <?php echo htmlspecialchars($shipment['job_no']); ?>?')">
                                    <i class="bi bi-trash"></i> Xóa lô hàng
                                </a>
                            <?php endif; ?>
                            <a href="send_debit.php?id=<?php echo $id; ?>" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-envelope-fill"></i> Gửi Debit Note qua Email
                            </a>
                            <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
                                <i class="bi bi-printer"></i> In trang này
                            </button>
                        </div>
                    </div>
                </div>

            </div><!-- /col-lg-4 -->
        </div><!-- /row -->
    </div><!-- /container -->

    <footer class="bg-white text-center py-2 border-top print-hide">
        <small class="text-muted">&copy; <?php echo date('Y'); ?> Forwarder System</small>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>