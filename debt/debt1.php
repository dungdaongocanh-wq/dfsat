<?php
require_once '../config/database.php';
require_once '../config/ehoadon.php';
checkLogin();

$conn = getDBConnection();

// ============================================================
// XỬ LÝ POST - Cập nhật thanh toán
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $sid    = intval($_POST['shipment_id'] ?? 0);

    if ($sid > 0) {
        if ($action === 'update_customer') {
            $paid_amount = floatval($_POST['customer_paid_amount'] ?? 0);
            $paid_at     = trim($_POST['customer_paid_at']     ?? '');
            $paid_note   = trim($_POST['customer_paid_note']   ?? '');
            $paid_at_val = ($paid_at !== '') ? $paid_at : null;

            $stmt = $conn->prepare("UPDATE shipments SET
                customer_paid_amount = ?,
                customer_paid_at     = ?,
                customer_paid_note   = ?
                WHERE id = ?");
            $stmt->bind_param("dssi", $paid_amount, $paid_at_val, $paid_note, $sid);
            if (!$stmt->execute()) {
                error_log("update_customer error: " . $stmt->error);
            }
            $stmt->close();

        } elseif ($action === 'update_supplier') {
            $paid_amount = floatval($_POST['supplier_paid_amount'] ?? 0);
            $paid_at     = trim($_POST['supplier_paid_at']     ?? '');
            $paid_note   = trim($_POST['supplier_paid_note']   ?? '');
            $paid_at_val = ($paid_at !== '') ? $paid_at : null;

            $stmt = $conn->prepare("UPDATE shipments SET
                supplier_paid_amount = ?,
                supplier_paid_at     = ?,
                supplier_paid_note   = ?
                WHERE id = ?");
            $stmt->bind_param("dssi", $paid_amount, $paid_at_val, $paid_note, $sid);
            if (!$stmt->execute()) {
                error_log("update_supplier error: " . $stmt->error);
            }
            $stmt->close();
        }
    }

    $redirectParams = array_filter([
        'search'     => $_POST['search']     ?? '',
        'status_kh'  => $_POST['status_kh']  ?? '',
        'status_ncc' => $_POST['status_ncc'] ?? '',
        'month'      => $_POST['month']      ?? '',
    ]);
    header("Location: debt.php" . (!empty($redirectParams) ? '?' . http_build_query($redirectParams) : ''));
    exit();
}

// ============================================================
// FILTER
// ============================================================
$search     = trim($_GET['search']     ?? '');
$status_kh  = trim($_GET['status_kh']  ?? '');
$status_ncc = trim($_GET['status_ncc'] ?? '');
$month      = trim($_GET['month']      ?? '');

$where  = ['1=1'];
$params = [];
$types  = '';

if ($search !== '') {
    $like = '%' . $search . '%';
    $where[]  = '(s.job_no LIKE ? OR s.hawb LIKE ? OR s.mawb LIKE ? OR c.company_name LIKE ? OR s.customs_declaration_no LIKE ?)';
    $params   = array_merge($params, [$like, $like, $like, $like, $like]);
    $types   .= 'sssss';
}

if ($month !== '') {
    $where[]  = 'DATE_FORMAT(s.arrival_date, "%Y-%m") = ?';
    $params[] = $month;
    $types   .= 's';
}

// Build SQL
$sql = "SELECT s.id, s.job_no, s.hawb, s.mawb, s.customs_declaration_no,
    s.arrival_date, s.status,
    c.company_name,
    COALESCE((
        SELECT SUM(sc.total_amount)
        FROM shipment_costs sc
        WHERE sc.shipment_id = s.id
    ), 0) AS total_cost,
    COALESCE((
    SELECT SUM(ss.total_amount)
    FROM shipment_sells ss
    WHERE ss.shipment_id = s.id
), 0) AS total_sell,
    COALESCE(s.customer_paid_amount, 0) AS customer_paid_amount,
    s.customer_paid_at,
    s.customer_paid_note,
    COALESCE(s.supplier_paid_amount, 0) AS supplier_paid_amount,
    s.supplier_paid_at,
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

// Filter status sau khi lấy data (vì cần tính toán)
$data = [];
foreach ($rows as $row) {
    $sell        = floatval($row['total_sell']);
    $cost        = floatval($row['total_cost']);
    $kh_paid     = floatval($row['customer_paid_amount']);
    $ncc_paid    = floatval($row['supplier_paid_amount']);
    $kh_remain   = $sell - $kh_paid;
    $ncc_remain  = $cost - $ncc_paid;

    // status_kh filter
    if ($status_kh === 'paid'    && $kh_paid  < $sell)  continue;
    if ($status_kh === 'unpaid'  && $kh_paid  >= $sell) continue;
    if ($status_kh === 'partial' && ($kh_paid <= 0 || $kh_paid >= $sell)) continue;

    // status_ncc filter
    if ($status_ncc === 'paid'    && $ncc_paid  < $cost)  continue;
    if ($status_ncc === 'unpaid'  && $ncc_paid  >= $cost) continue;
    if ($status_ncc === 'partial' && ($ncc_paid <= 0 || $ncc_paid >= $cost)) continue;

    $row['total_sell']  = $sell;
    $row['total_cost']  = $cost;
    $row['kh_remain']   = $kh_remain;
    $row['ncc_remain']  = $ncc_remain;
    $data[] = $row;
}

// Tổng hợp
$sum_sell       = array_sum(array_column($data, 'total_sell'));
$sum_cost       = array_sum(array_column($data, 'total_cost'));
$sum_kh_paid    = array_sum(array_column($data, 'customer_paid_amount'));
$sum_kh_remain  = array_sum(array_column($data, 'kh_remain'));
$sum_ncc_paid   = array_sum(array_column($data, 'supplier_paid_amount'));
$sum_ncc_remain = array_sum(array_column($data, 'ncc_remain'));
$sum_profit     = $sum_sell - $sum_cost;

$conn->close();

function fmt($n) { return number_format($n, 0, ',', '.'); }
function statusKH($paid, $sell) {
    if ($sell <= 0)      return ['bg-secondary', 'N/A'];
    if ($paid >= $sell)  return ['bg-success',   'Đã thu'];
    if ($paid > 0)       return ['bg-warning text-dark', 'Một phần'];
    return               ['bg-danger',    'Chưa thu'];
}
function statusNCC($paid, $cost) {
    if ($cost <= 0)      return ['bg-secondary', 'N/A'];
    if ($paid >= $cost)  return ['bg-success',   'Đã trả'];
    if ($paid > 0)       return ['bg-warning text-dark', 'Một phần'];
    return               ['bg-danger',    'Chưa trả'];
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Công Nợ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { font-size: .875rem; }
        .table th { font-size: .78rem; white-space: nowrap; }
        .table td { font-size: .80rem; vertical-align: middle; }
        .sticky-col { position: sticky; left: 0; background: #fff; z-index: 1; }
        .num { text-align: right; white-space: nowrap; }
        .profit-pos { color: #198754; font-weight: 700; }
        .profit-neg { color: #dc3545; font-weight: 700; }
        .summary-card { border-left: 4px solid; }
        .modal-title-sm { font-size: .95rem; }
        tfoot td { font-weight: 700; background: #f8f9fa; }
        .toast-container { z-index: 9999; }
    </style>
</head>
<body class="bg-light">

<!-- TOAST THÔNG BÁO -->
<div class="toast-container position-fixed top-0 end-0 p-3">
    <?php if (isset($_GET['saved'])): ?>
    <div id="saveToast" class="toast align-items-center text-bg-success border-0 show" role="alert" aria-live="assertive" data-bs-delay="3000">
        <div class="d-flex">
            <div class="toast-body">
                <i class="bi bi-check-circle-fill me-1"></i>
                <?php echo $_GET['saved'] === 'kh' ? 'Đã lưu thanh toán Khách hàng!' : 'Đã lưu thanh toán Nhà cung cấp!'; ?>
            </div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- NAVBAR -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top">
    <div class="container-fluid">
        <a class="navbar-brand" href="../dashboard.php"><i class="bi bi-box-seam"></i> Forwarder System</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item"><a class="nav-link" href="../dashboard.php">Dashboard</a></li>
                <li class="nav-item"><a class="nav-link" href="../customers/index.php">Khách hàng</a></li>
                <li class="nav-item"><a class="nav-link" href="../shipments/index.php">Lô hàng</a></li>
                <li class="nav-item"><a class="nav-link active" href="debt.php">Công Nợ</a></li>
                <li class="nav-item"><a class="nav-link" href="../suppliers/index.php">Nhà cung cấp</a></li>
                <?php if ($_SESSION['role'] == 'admin'): ?>
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Quản trị</a>
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

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">
            <i class="bi bi-cash-coin text-success"></i> Quản lý Công Nợ
            <span class="badge bg-secondary ms-1"><?php echo count($data); ?> lô</span>
        </h4>
    </div>

    <!-- SUMMARY CARDS -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-2">
            <div class="card summary-card h-100 py-2 px-3" style="border-color:#0d6efd;">
                <div class="small text-muted">Tổng Sell</div>
                <div class="fw-bold text-primary"><?php echo fmt($sum_sell); ?></div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card summary-card h-100 py-2 px-3" style="border-color:#dc3545;">
                <div class="small text-muted">Tổng Cost</div>
                <div class="fw-bold text-danger"><?php echo fmt($sum_cost); ?></div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card summary-card h-100 py-2 px-3" style="border-color:#198754;">
                <div class="small text-muted">Lợi nhuận</div>
                <div class="fw-bold <?php echo $sum_profit >= 0 ? 'text-success' : 'text-danger'; ?>">
                    <?php echo fmt($sum_profit); ?>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card summary-card h-100 py-2 px-3" style="border-color:#ffc107;">
                <div class="small text-muted">KH còn nợ</div>
                <div class="fw-bold text-warning"><?php echo fmt($sum_kh_remain); ?></div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card summary-card h-100 py-2 px-3" style="border-color:#fd7e14;">
                <div class="small text-muted">NCC còn nợ</div>
                <div class="fw-bold" style="color:#fd7e14;"><?php echo fmt($sum_ncc_remain); ?></div>
            </div>
        </div>
        <div class="col-6 col-md-2">
            <div class="card summary-card h-100 py-2 px-3" style="border-color:#6f42c1;">
                <div class="small text-muted">Đã thu KH</div>
                <div class="fw-bold" style="color:#6f42c1;"><?php echo fmt($sum_kh_paid); ?></div>
            </div>
        </div>
    </div>

    <!-- FILTER -->
    <div class="card shadow-sm mb-3">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <input type="text" name="search" class="form-control form-control-sm"
                           placeholder="🔍 Job No, HAWB, Khách hàng..."
                           value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-2">
                    <input type="month" name="month" class="form-control form-control-sm"
                           value="<?php echo htmlspecialchars($month); ?>"
                           title="Lọc theo tháng arrival">
                </div>
                <div class="col-md-2">
                    <select name="status_kh" class="form-select form-select-sm">
                        <option value="">— Công nợ KH —</option>
                        <option value="unpaid"  <?php echo $status_kh==='unpaid'  ?'selected':''; ?>>Chưa thu</option>
                        <option value="partial" <?php echo $status_kh==='partial' ?'selected':''; ?>>Một phần</option>
                        <option value="paid"    <?php echo $status_kh==='paid'    ?'selected':''; ?>>Đã thu</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="status_ncc" class="form-select form-select-sm">
                        <option value="">— Công nợ NCC —</option>
                        <option value="unpaid"  <?php echo $status_ncc==='unpaid'  ?'selected':''; ?>>Chưa trả</option>
                        <option value="partial" <?php echo $status_ncc==='partial' ?'selected':''; ?>>Một phần</option>
                        <option value="paid"    <?php echo $status_ncc==='paid'    ?'selected':''; ?>>Đã trả</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="bi bi-funnel"></i> Lọc
                    </button>
                    <a href="debt.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-x-circle"></i> Xóa lọc
                    </a>
                </div>
            </form>
        </div>
    </div>

    <!-- TABLE -->
    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered table-hover table-sm mb-0">
                    <thead class="table-dark">
                        <tr>
                            <th class="sticky-col" style="min-width:110px;">Job No</th>
                            <th style="min-width:140px;">Khách hàng</th>
                            <th style="min-width:100px;">HAWB</th>
                            <th style="min-width:100px;">MAWB</th>
                            <th style="min-width:100px;">Tờ Khai</th>
                            <th class="num" style="min-width:100px;">Cost</th>
                            <th class="num" style="min-width:100px;">Sell</th>
                            <th class="num" style="min-width:90px;">Lợi Nhuận</th>
                            <!-- KH -->
                            <th class="num text-warning" style="min-width:100px;">KH Trả</th>
                            <th style="min-width:90px;">Ngày Trả</th>
                            <th class="num text-danger" style="min-width:90px;">KH Còn Nợ</th>
                            <th style="min-width:80px;">T.Thái KH</th>
                            <!-- NCC -->
                            <th class="num text-info" style="min-width:100px;">Đã Trả NCC</th>
                            <th style="min-width:90px;">Ngày Trả NCC</th>
                            <th class="num" style="min-width:90px;color:#fd7e14;">NCC Còn Nợ</th>
                            <th style="min-width:80px;">T.Thái NCC</th>
                            <!-- Action -->
                            <th style="min-width:80px;">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($data)): ?>
                        <tr>
                            <td colspan="17" class="text-center text-muted py-4">
                                <i class="bi bi-inbox fs-4"></i><br>Không có dữ liệu
                            </td>
                        </tr>
                    <?php else: foreach ($data as $row):
                        $profit = $row['total_sell'] - $row['total_cost'];
                        $skh    = statusKH($row['customer_paid_amount'], $row['total_sell']);
                        $sncc   = statusNCC($row['supplier_paid_amount'], $row['total_cost']);
                    ?>
                        <tr>
                            <td class="sticky-col">
                                <a href="../shipments/view.php?id=<?php echo $row['id']; ?>"
                                   class="fw-bold text-primary text-decoration-none">
                                    <?php echo htmlspecialchars($row['job_no']); ?>
                                </a>
                            </td>
                            <td><?php echo htmlspecialchars($row['company_name'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($row['hawb'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($row['mawb'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($row['customs_declaration_no'] ?? '—'); ?></td>
                            <td class="num text-danger"><?php echo fmt($row['total_cost']); ?></td>
                            <td class="num text-primary"><?php echo fmt($row['total_sell']); ?></td>
                            <td class="num <?php echo $profit >= 0 ? 'profit-pos' : 'profit-neg'; ?>">
                                <?php echo fmt($profit); ?>
                            </td>
                            <!-- KH -->
                            <td class="num text-success fw-bold"><?php echo fmt($row['customer_paid_amount']); ?></td>
                            <td>
                                <?php echo $row['customer_paid_at']
                                    ? date('d/m/Y', strtotime($row['customer_paid_at']))
                                    : '<span class="text-muted">—</span>'; ?>
                            </td>
                            <td class="num <?php echo $row['kh_remain'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                <?php echo fmt($row['kh_remain']); ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $skh[0]; ?>">
                                    <?php echo $skh[1]; ?>
                                </span>
                            </td>
                            <!-- NCC -->
                            <td class="num text-info fw-bold"><?php echo fmt($row['supplier_paid_amount']); ?></td>
                            <td>
                                <?php echo $row['supplier_paid_at']
                                    ? date('d/m/Y', strtotime($row['supplier_paid_at']))
                                    : '<span class="text-muted">—</span>'; ?>
                            </td>
                            <td class="num <?php echo $row['ncc_remain'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                <?php echo fmt($row['ncc_remain']); ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $sncc[0]; ?>">
                                    <?php echo $sncc[1]; ?>
                                </span>
                            </td>
                            <td>
                                <button class="btn btn-outline-success btn-sm"
                                        onclick="openModal(<?php echo htmlspecialchars(json_encode([
                                            'id'                    => $row['id'],
                                            'job_no'                => $row['job_no'],
                                            'total_sell'            => $row['total_sell'],
                                            'total_cost'            => $row['total_cost'],
                                            'customer_paid_amount'  => $row['customer_paid_amount'],
                                            'customer_paid_at'      => $row['customer_paid_at'] ?? '',
                                            'customer_paid_note'    => $row['customer_paid_note'] ?? '',
                                            'supplier_paid_amount'  => $row['supplier_paid_amount'],
                                            'supplier_paid_at'      => $row['supplier_paid_at'] ?? '',
                                            'supplier_paid_note'    => $row['supplier_paid_note'] ?? '',
                                        ]), ENT_QUOTES); ?>)"
                                        title="Cập nhật thanh toán">
                                    <i class="bi bi-pencil-fill"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                    <?php if (!empty($data)): ?>
                    <tfoot>
                        <tr>
                            <td colspan="5" class="text-end">TỔNG CỘNG:</td>
                            <td class="num text-danger"><?php echo fmt($sum_cost); ?></td>
                            <td class="num text-primary"><?php echo fmt($sum_sell); ?></td>
                            <td class="num <?php echo $sum_profit >= 0 ? 'profit-pos' : 'profit-neg'; ?>">
                                <?php echo fmt($sum_profit); ?>
                            </td>
                            <td class="num text-success"><?php echo fmt($sum_kh_paid); ?></td>
                            <td></td>
                            <td class="num text-danger"><?php echo fmt($sum_kh_remain); ?></td>
                            <td></td>
                            <td class="num text-info"><?php echo fmt($sum_ncc_paid); ?></td>
                            <td></td>
                            <td class="num text-danger"><?php echo fmt($sum_ncc_remain); ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- MODAL CẬP NHẬT THANH TOÁN -->
<div class="modal fade" id="payModal" tabindex="-1" aria-labelledby="payModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white py-2">
                <h6 class="modal-title modal-title-sm" id="payModalLabel">
                    <i class="bi bi-pencil-fill"></i>
                    Cập nhật thanh toán — <span id="modalJobNo" class="fw-bold"></span>
                </h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <!-- KH -->
                    <div class="col-md-6">
                        <div class="card border-success h-100">
                            <div class="card-header bg-success text-white py-2">
                                <i class="bi bi-person-fill"></i> Thu tiền Khách hàng
                            </div>
                            <div class="card-body">
                                <form method="POST" action="debt.php" id="formKH">
                                    <input type="hidden" name="action" value="update_customer">
                                    <input type="hidden" name="shipment_id" id="kh_shipment_id">
                                    <input type="hidden" name="search"     value="<?php echo htmlspecialchars($search); ?>">
                                    <input type="hidden" name="status_kh"  value="<?php echo htmlspecialchars($status_kh); ?>">
                                    <input type="hidden" name="status_ncc" value="<?php echo htmlspecialchars($status_ncc); ?>">
                                    <input type="hidden" name="month"      value="<?php echo htmlspecialchars($month); ?>">
                                    <input type="hidden" name="saved"      value="kh">
                                    <div class="mb-2">
                                        <label class="form-label fw-bold small">Tổng Sell (chỉ đọc)</label>
                                        <input type="text" class="form-control form-control-sm bg-light"
                                               id="kh_sell_display" readonly>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label fw-bold small">Số tiền KH đã trả <span class="text-danger">*</span></label>
                                        <input type="number" name="customer_paid_amount"
                                               id="kh_paid_amount" class="form-control form-control-sm"
                                               min="0" step="1" required>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label fw-bold small">Ngày trả</label>
                                        <input type="date" name="customer_paid_at"
                                               id="kh_paid_at" class="form-control form-control-sm">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold small">Ghi chú</label>
                                        <input type="text" name="customer_paid_note"
                                               id="kh_paid_note" class="form-control form-control-sm"
                                               placeholder="VD: CK ngân hàng, số ref...">
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-success btn-sm">
                                            <i class="bi bi-save"></i> Lưu
                                        </button>
                                        <button type="button" class="btn btn-outline-success btn-sm"
                                                onclick="setFullPaid('kh')">
                                            <i class="bi bi-check-all"></i> Đã trả đủ
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    <!-- NCC -->
                    <div class="col-md-6">
                        <div class="card border-warning h-100">
                            <div class="card-header bg-warning text-dark py-2">
                                <i class="bi bi-truck"></i> Trả tiền Nhà cung cấp
                            </div>
                            <div class="card-body">
                                <form method="POST" action="debt.php" id="formNCC">
                                    <input type="hidden" name="action" value="update_supplier">
                                    <input type="hidden" name="shipment_id" id="ncc_shipment_id">
                                    <input type="hidden" name="search"     value="<?php echo htmlspecialchars($search); ?>">
                                    <input type="hidden" name="status_kh"  value="<?php echo htmlspecialchars($status_kh); ?>">
                                    <input type="hidden" name="status_ncc" value="<?php echo htmlspecialchars($status_ncc); ?>">
                                    <input type="hidden" name="month"      value="<?php echo htmlspecialchars($month); ?>">
                                    <input type="hidden" name="saved"      value="ncc">
                                    <div class="mb-2">
                                        <label class="form-label fw-bold small">Tổng Cost (chỉ đọc)</label>
                                        <input type="text" class="form-control form-control-sm bg-light"
                                               id="ncc_cost_display" readonly>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label fw-bold small">Số tiền đã trả NCC <span class="text-danger">*</span></label>
                                        <input type="number" name="supplier_paid_amount"
                                               id="ncc_paid_amount" class="form-control form-control-sm"
                                               min="0" step="1" required>
                                    </div>
                                    <div class="mb-2">
                                        <label class="form-label fw-bold small">Ngày trả</label>
                                        <input type="date" name="supplier_paid_at"
                                               id="ncc_paid_at" class="form-control form-control-sm">
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label fw-bold small">Ghi chú</label>
                                        <input type="text" name="supplier_paid_note"
                                               id="ncc_paid_note" class="form-control form-control-sm"
                                               placeholder="VD: Trả tháng 3/2026...">
                                    </div>
                                    <div class="d-flex gap-2">
                                        <button type="submit" class="btn btn-warning btn-sm">
                                            <i class="bi bi-save"></i> Lưu
                                        </button>
                                        <button type="button" class="btn btn-outline-warning btn-sm"
                                                onclick="setFullPaid('ncc')">
                                            <i class="bi bi-check-all"></i> Đã trả đủ
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<footer class="bg-white text-center py-2 border-top">
    <small class="text-muted">&copy; <?php echo date('Y'); ?> Forwarder System</small>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let currentRow = null;
let payModalInstance = null;

function openModal(row) {
    currentRow = row;
    document.getElementById('modalJobNo').textContent = row.job_no;

    // KH
    document.getElementById('kh_shipment_id').value  = row.id;
    document.getElementById('kh_sell_display').value = Number(row.total_sell).toLocaleString('vi-VN');
    document.getElementById('kh_paid_amount').value  = row.customer_paid_amount || 0;
    document.getElementById('kh_paid_at').value      = row.customer_paid_at || '';
    document.getElementById('kh_paid_note').value    = row.customer_paid_note || '';

    // NCC
    document.getElementById('ncc_shipment_id').value  = row.id;
    document.getElementById('ncc_cost_display').value = Number(row.total_cost).toLocaleString('vi-VN');
    document.getElementById('ncc_paid_amount').value  = row.supplier_paid_amount || 0;
    document.getElementById('ncc_paid_at').value      = row.supplier_paid_at || '';
    document.getElementById('ncc_paid_note').value    = row.supplier_paid_note || '';

    if (!payModalInstance) {
        payModalInstance = new bootstrap.Modal(document.getElementById('payModal'));
    }
    payModalInstance.show();
}

function setFullPaid(type) {
    if (!currentRow) return;
    if (type === 'kh') {
        document.getElementById('kh_paid_amount').value = currentRow.total_sell;
        if (!document.getElementById('kh_paid_at').value) {
            document.getElementById('kh_paid_at').value = new Date().toISOString().split('T')[0];
        }
    } else {
        document.getElementById('ncc_paid_amount').value = currentRow.total_cost;
        if (!document.getElementById('ncc_paid_at').value) {
            document.getElementById('ncc_paid_at').value = new Date().toISOString().split('T')[0];
        }
    }
}

// Auto-show toast nếu có saved param
document.addEventListener('DOMContentLoaded', function () {
    const toastEl = document.getElementById('saveToast');
    if (toastEl) {
        const toast = bootstrap.Toast.getOrCreateInstance(toastEl);
        toast.show();
    }
});
</script>
</body>
</html>