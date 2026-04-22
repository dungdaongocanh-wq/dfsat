<?php
require_once '../config/database.php';
checkLogin();

$conn = getDBConnection();

// Xử lý tìm kiếm & lọc
$search         = isset($_GET['search'])     ? trim($_GET['search'])     : '';
$status_filter  = isset($_GET['status'])     ? $_GET['status']           : '';
$locked_filter  = isset($_GET['locked'])     ? $_GET['locked']           : '';
$customer_filter= isset($_GET['customer'])   ? intval($_GET['customer']) : 0;
$date_from      = isset($_GET['date_from'])  ? trim($_GET['date_from'])  : '';
$date_to        = isset($_GET['date_to'])    ? trim($_GET['date_to'])    : '';
$email_filter   = isset($_GET['email_sent']) ? $_GET['email_sent']       : '';

$where = [];

if ($search) {
    $s = $conn->real_escape_string($search);
    $where[] = "(s.job_no LIKE '%$s%' OR s.mawb LIKE '%$s%' OR s.hawb LIKE '%$s%'
                 OR s.shipper LIKE '%$s%' OR s.cnee LIKE '%$s%'
                 OR s.customs_declaration_no LIKE '%$s%'
                 OR c.short_name LIKE '%$s%')";
}
if ($status_filter)   $where[] = "s.status = '$status_filter'";
if ($locked_filter)   $where[] = "s.is_locked = '$locked_filter'";
if ($customer_filter) $where[] = "s.customer_id = $customer_filter";
if ($date_from)       $where[] = "DATE(s.created_at) >= '$date_from'";
if ($date_to)         $where[] = "DATE(s.created_at) <= '$date_to'";
if ($email_filter)    $where[] = "s.email_sent = '$email_filter'";

$whereClause = count($where) > 0 ? 'WHERE ' . implode(' AND ', $where) : '';

$sql = "SELECT s.*,
               c.company_name, c.short_name AS customer_short,
               COALESCE(sc.total_cost, 0) AS total_cost,
               COALESCE(ss.total_sell, 0) AS total_sell,
               COALESCE(ss.total_sell, 0) - COALESCE(sc.total_cost, 0) AS profit
        FROM shipments s
        LEFT JOIN customers c ON s.customer_id = c.id
        LEFT JOIN (SELECT shipment_id, SUM(total_amount) AS total_cost FROM shipment_costs GROUP BY shipment_id) sc ON sc.shipment_id = s.id
        LEFT JOIN (SELECT shipment_id, SUM(total_amount) AS total_sell FROM shipment_sells GROUP BY shipment_id) ss ON ss.shipment_id = s.id
        $whereClause
        ORDER BY s.created_at DESC";

$result = $conn->query($sql);

$stats = [
    'total'      => $conn->query("SELECT COUNT(*) c FROM shipments")->fetch_assoc()['c'],
    'pending'    => $conn->query("SELECT COUNT(*) c FROM shipments WHERE status='pending'")->fetch_assoc()['c'],
    'in_transit' => $conn->query("SELECT COUNT(*) c FROM shipments WHERE status='in_transit'")->fetch_assoc()['c'],
    'arrived'    => $conn->query("SELECT COUNT(*) c FROM shipments WHERE status='arrived'")->fetch_assoc()['c'],
    'cleared'    => $conn->query("SELECT COUNT(*) c FROM shipments WHERE status='cleared'")->fetch_assoc()['c'],
    'delivered'  => $conn->query("SELECT COUNT(*) c FROM shipments WHERE status='delivered'")->fetch_assoc()['c'],
    'locked'     => $conn->query("SELECT COUNT(*) c FROM shipments WHERE is_locked='yes'")->fetch_assoc()['c'],
    'email_sent' => $conn->query("SELECT COUNT(*) c FROM shipments WHERE email_sent='yes'")->fetch_assoc()['c'],
    // ✅ THÊM: đếm số lô đã xuất HĐ VAT
    'vat_issued' => $conn->query("SELECT COUNT(*) c FROM shipments WHERE vat_invoice_status='issued'")->fetch_assoc()['c'],
];

$customers = $conn->query("SELECT id, short_name, company_name FROM customers WHERE status='active' ORDER BY short_name");

$conn->close();

$statusBadge = [
    'pending'    => ['color' => 'warning',  'text' => 'Chờ xử lý',       'icon' => 'hourglass-split'],
    'in_transit' => ['color' => 'primary',  'text' => 'Đang vận chuyển', 'icon' => 'truck'],
    'arrived'    => ['color' => 'info',     'text' => 'Đã đến',          'icon' => 'geo-alt'],
    'cleared'    => ['color' => 'success',  'text' => 'Đã thông quan',   'icon' => 'check-circle'],
    'delivered'  => ['color' => 'dark',     'text' => 'Đã giao',         'icon' => 'box-seam'],
    'cancelled'  => ['color' => 'danger',   'text' => 'Đã hủy',          'icon' => 'x-circle'],
];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Lô hàng - Forwarder System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .shipment-row {
            cursor: pointer;
            transition: background-color 0.15s, transform 0.1s;
        }
        .shipment-row:hover {
            background-color: #e8f4fd !important;
            transform: scale(1.001);
        }
        .shipment-row td { vertical-align: middle; }

        .stat-card {
            border-radius: 10px;
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 6px 20px rgba(0,0,0,.15);
        }
        .stat-card .stat-num  { font-size: 1.9rem; font-weight: 700; }
        .stat-card .stat-icon { font-size: 2rem; opacity: .6; }

        .job-no { font-weight: 700; color: #0d6efd; font-size: .9rem; letter-spacing: .4px; }
        .profit-pos { color: #198754; font-weight: 600; }
        .profit-neg { color: #dc3545; font-weight: 600; }
        .locked-badge { font-size: .65rem; padding: 2px 6px; border-radius: 4px; }

        .action-btn { opacity: 0; transition: opacity .2s; }
        .shipment-row:hover .action-btn { opacity: 1; }

        .filter-card { border-left: 4px solid #0d6efd; }

        .table thead th {
            background: #343a40;
            color: white;
            font-size: .78rem;
            white-space: nowrap;
            padding: 8px 6px;
        }
        .table tbody td { font-size: .82rem; padding: 6px; }

        .cd-badge {
            font-size: .72rem;
            background: #e8f5e9;
            color: #1b5e20;
            border: 1px solid #a5d6a7;
            border-radius: 4px;
            padding: 1px 5px;
            display: inline-block;
            max-width: 130px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Badge email */
        .email-sent-badge {
            font-size: .72rem;
            padding: 3px 7px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-weight: 600;
        }
        .email-sent-badge.sent {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #6ee7b7;
        }
        .email-sent-badge.not-sent {
            background: #f3f4f6;
            color: #6b7280;
            border: 1px solid #d1d5db;
        }
        .email-sent-time {
            font-size: .68rem;
            color: #6b7280;
            display: block;
            margin-top: 2px;
        }

        /* ✅ Badge VAT */
        .vat-badge {
            font-size: .72rem;
            padding: 3px 7px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            font-weight: 600;
            text-decoration: none;
        }
        .vat-badge:hover { opacity: .85; }
        .vat-badge.issued {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }
        .vat-badge.cancelled {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
        }
        .vat-badge.pending {
            background: #fef9c3;
            color: #854d0e;
            border: 1px solid #fde047;
        }
        .vat-badge.none {
            background: #f3f4f6;
            color: #6b7280;
            border: 1px solid #d1d5db;
        }
    </style>
</head>
<body class="bg-light">

    <!-- NAVBAR -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top shadow">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="../dashboard.php">
                <i class="bi bi-box-seam"></i> Forwarder System
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item"><a class="nav-link" href="../dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="../customers/index.php"><i class="bi bi-people"></i> Khách hàng</a></li>
                    <li class="nav-item"><a class="nav-link active" href="index.php"><i class="bi bi-box"></i> Lô hàng</a></li>
		    <li class="nav-item"><a class="nav-link" href="../debt/index.php">Công Nợ</a></li>
                    <li class="nav-item"><a class="nav-link" href="../suppliers/index.php"><i class="bi bi-truck"></i> Nhà cung cấp</a></li>
                    <?php if ($_SESSION['role'] == 'admin'): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-gear"></i> Quản trị
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="../accounts/index.php"><i class="bi bi-person-badge"></i> Tài khoản</a></li>
                            <li><a class="dropdown-item" href="../cost_codes/index.php"><i class="bi bi-tag"></i> Mã chi phí</a></li>
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
                            <li><a class="dropdown-item" href="../logout.php"><i class="bi bi-box-arrow-right"></i> Đăng xuất</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-3 pb-5">

        <!-- HEADER -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4 class="mb-0"><i class="bi bi-box text-primary"></i> Quản lý Lô hàng</h4>
            <a href="add.php" class="btn btn-primary">
                <i class="bi bi-plus-circle"></i> Thêm lô hàng mới
            </a>
        </div>

        <!-- THỐNG KÊ -->
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-2">
                <div class="card stat-card border-0 shadow-sm bg-primary text-white" onclick="filterByStatus('')">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-num"><?php echo $stats['total']; ?></div>
                                <div class="small">Tất cả</div>
                            </div>
                            <i class="bi bi-boxes stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card stat-card border-0 shadow-sm bg-warning" onclick="filterByStatus('pending')">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-num"><?php echo $stats['pending']; ?></div>
                                <div class="small">Chờ xử lý</div>
                            </div>
                            <i class="bi bi-hourglass-split stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card stat-card border-0 shadow-sm bg-primary text-white" onclick="filterByStatus('in_transit')">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-num"><?php echo $stats['in_transit']; ?></div>
                                <div class="small">Đang vận chuyển</div>
                            </div>
                            <i class="bi bi-truck stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card stat-card border-0 shadow-sm bg-info text-white" onclick="filterByStatus('arrived')">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-num"><?php echo $stats['arrived']; ?></div>
                                <div class="small">Đã đến</div>
                            </div>
                            <i class="bi bi-geo-alt stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card stat-card border-0 shadow-sm bg-success text-white" onclick="filterByStatus('cleared')">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-num"><?php echo $stats['cleared']; ?></div>
                                <div class="small">Đã thông quan</div>
                            </div>
                            <i class="bi bi-check-circle stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card stat-card border-0 shadow-sm bg-dark text-white" onclick="filterByStatus('delivered')">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <div class="stat-num"><?php echo $stats['delivered']; ?></div>
                                <div class="small">Đã giao</div>
                            </div>
                            <i class="bi bi-box-seam stat-icon"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- STAT EMAIL + VAT -->
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm" style="border-left:4px solid #10b981 !important; cursor:pointer"
                     onclick="filterByEmail('yes')">
                    <div class="card-body py-2 px-3 d-flex align-items-center gap-3">
                        <i class="bi bi-envelope-check-fill text-success fs-3"></i>
                        <div>
                            <div class="fw-bold fs-5 text-success"><?php echo $stats['email_sent']; ?></div>
                            <small class="text-muted">Đã gửi Debit Note</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm" style="border-left:4px solid #6b7280 !important; cursor:pointer"
                     onclick="filterByEmail('no')">
                    <div class="card-body py-2 px-3 d-flex align-items-center gap-3">
                        <i class="bi bi-envelope-x-fill text-secondary fs-3"></i>
                        <div>
                            <div class="fw-bold fs-5 text-secondary"><?php echo $stats['total'] - $stats['email_sent']; ?></div>
                            <small class="text-muted">Chưa gửi Debit Note</small>
                        </div>
                    </div>
                </div>
            </div>
            <!-- ✅ THÊM: Stat HĐ VAT -->
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm" style="border-left:4px solid #166534 !important; cursor:pointer"
                     onclick="filterByVat('issued')">
                    <div class="card-body py-2 px-3 d-flex align-items-center gap-3">
                        <i class="bi bi-receipt-cutoff text-success fs-3"></i>
                        <div>
                            <div class="fw-bold fs-5 text-success"><?php echo $stats['vat_issued']; ?></div>
                            <small class="text-muted">Đã xuất HĐ VAT</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="card border-0 shadow-sm" style="border-left:4px solid #dc2626 !important; cursor:pointer"
                     onclick="filterByVat('none')">
                    <div class="card-body py-2 px-3 d-flex align-items-center gap-3">
                        <i class="bi bi-receipt text-danger fs-3"></i>
                        <div>
                            <div class="fw-bold fs-5 text-danger"><?php echo $stats['total'] - $stats['vat_issued']; ?></div>
                            <small class="text-muted">Chưa xuất HĐ VAT</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- BỘ LỌC -->
        <div class="card filter-card shadow-sm mb-3">
            <div class="card-body py-2">
                <form method="GET" id="filterForm" class="row g-2 align-items-end">
                    <div class="col-md-3">
                        <label class="form-label small mb-1"><i class="bi bi-search"></i> Tìm kiếm</label>
                        <input type="text" name="search" class="form-control form-control-sm"
                               placeholder="Job No, MAWB, HAWB, Tờ khai, Shipper, KH..."
                               value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label small mb-1"><i class="bi bi-people"></i> Khách hàng</label>
                        <select name="customer" class="form-select form-select-sm">
                            <option value="">-- Tất cả --</option>
                            <?php while ($c = $customers->fetch_assoc()): ?>
                                <option value="<?php echo $c['id']; ?>"
                                    <?php echo $customer_filter == $c['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['short_name'] . ' - ' . $c['company_name']); ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label small mb-1"><i class="bi bi-flag"></i> Trạng thái</label>
                        <select name="status" class="form-select form-select-sm" id="statusSelect">
                            <option value="">-- Tất cả --</option>
                            <option value="pending"    <?php echo $status_filter=='pending'    ?'selected':''; ?>>Chờ xử lý</option>
                            <option value="in_transit" <?php echo $status_filter=='in_transit' ?'selected':''; ?>>Đang vận chuyển</option>
                            <option value="arrived"    <?php echo $status_filter=='arrived'    ?'selected':''; ?>>Đã đến</option>
                            <option value="cleared"    <?php echo $status_filter=='cleared'    ?'selected':''; ?>>Đã thông quan</option>
                            <option value="delivered"  <?php echo $status_filter=='delivered'  ?'selected':''; ?>>Đã giao</option>
                            <option value="cancelled"  <?php echo $status_filter=='cancelled'  ?'selected':''; ?>>Đã hủy</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label small mb-1"><i class="bi bi-lock"></i> Khóa</label>
                        <select name="locked" class="form-select form-select-sm">
                            <option value="">-- Tất cả --</option>
                            <option value="no"  <?php echo $locked_filter=='no'  ?'selected':''; ?>>Chưa khóa</option>
                            <option value="yes" <?php echo $locked_filter=='yes' ?'selected':''; ?>>Đã khóa</option>
                        </select>
                    </div>
                    <!-- LỌC EMAIL -->
                    <div class="col-md-1">
                        <label class="form-label small mb-1"><i class="bi bi-envelope"></i> Email</label>
                        <select name="email_sent" class="form-select form-select-sm" id="emailSelect">
                            <option value="">-- Tất cả --</option>
                            <option value="yes" <?php echo $email_filter=='yes' ?'selected':''; ?>>Đã gửi</option>
                            <option value="no"  <?php echo $email_filter=='no'  ?'selected':''; ?>>Chưa gửi</option>
                        </select>
                    </div>
                    <!-- ✅ THÊM: LỌC HĐ VAT -->
                    <div class="col-md-1">
                        <label class="form-label small mb-1"><i class="bi bi-receipt-cutoff"></i> HĐ VAT</label>
                        <select name="vat_status" class="form-select form-select-sm" id="vatSelect">
                            <option value="">-- Tất cả --</option>
                            <option value="issued"    <?php echo (($_GET['vat_status'] ?? '') == 'issued')    ? 'selected' : ''; ?>>Đã xuất</option>
                            <option value="cancelled" <?php echo (($_GET['vat_status'] ?? '') == 'cancelled') ? 'selected' : ''; ?>>Đã hủy</option>
                            <option value="none"      <?php echo (($_GET['vat_status'] ?? '') == 'none')      ? 'selected' : ''; ?>>Chưa xuất</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label small mb-1"><i class="bi bi-calendar"></i> Từ ngày</label>
                        <input type="date" name="date_from" class="form-control form-control-sm"
                               value="<?php echo $date_from; ?>">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label small mb-1"><i class="bi bi-calendar"></i> Đến ngày</label>
                        <input type="date" name="date_to" class="form-control form-control-sm"
                               value="<?php echo $date_to; ?>">
                    </div>
                    <div class="col-md-auto d-flex gap-1 flex-wrap">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-search"></i> Lọc
                        </button>
                        <a href="index.php" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-x"></i> Xóa
                        </a>
                        <a href="export_statement.php?<?php echo htmlspecialchars(http_build_query([
                            'search'    => $search,
                            'status'    => $status_filter,
                            'locked'    => $locked_filter,
                            'customer'  => $customer_filter ?: '',
                            'date_from' => $date_from,
                            'date_to'   => $date_to,
                        ])); ?>" class="btn btn-success btn-sm" title="Xuất Statement of Account">
                            <i class="bi bi-file-earmark-excel-fill"></i> Xuất SOA
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- THÔNG BÁO -->
        <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show py-2">
            <i class="bi bi-check-circle"></i>
            <?php
                if ($_GET['success'] == 'added')   echo 'Thêm lô hàng thành công!';
                if ($_GET['success'] == 'updated') echo 'Cập nhật lô hàng thành công!';
                if ($_GET['success'] == 'deleted') echo 'Xóa lô hàng thành công!';
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show py-2">
            <i class="bi bi-exclamation-triangle"></i>
            <?php
                if ($_GET['error'] == 'shipment_locked') echo 'Lô hàng đã khóa, không thể sửa!';
                if ($_GET['error'] == 'delete_failed')   echo 'Xóa lô hàng thất bại!';
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- BẢNG DANH SÁCH -->
        <div class="card shadow-sm">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center py-2">
                <span>
                    <i class="bi bi-table"></i> Danh sách lô hàng
                    <span class="badge bg-light text-dark ms-2"><?php echo $result->num_rows; ?> lô hàng</span>
                    <?php if ($search || $status_filter || $customer_filter || $date_from || $date_to || $locked_filter || $email_filter || !empty($_GET['vat_status'])): ?>
                        <span class="badge bg-warning text-dark ms-1">
                            <i class="bi bi-funnel"></i> Đang lọc
                        </span>
                    <?php endif; ?>
                </span>
                <small class="text-white-50">
                    <i class="bi bi-hand-index"></i> Click vào dòng để xem chi tiết
                </small>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-bordered mb-0">
                        <thead>
                            <tr>
                                <th style="width:32px">#</th>
                                <th>Job No</th>
                                <th>Khách hàng</th>
                                <th>MAWB / HAWB</th>
                                <th>Số tờ khai</th>
                                <th>Shipper / CNEE</th>
                                <th>VSL / FLIGHT</th>
                                <th>Kiện / GW / CW</th>
                                <th>Ngày đến</th>
                                <th>COST</th>
                                <th>SELL</th>
                                <th>Lợi nhuận</th>
                                <th>Debit Note</th>
                                <th>HĐ VAT</th><!-- ✅ CỘT MỚI -->
                                <th>Trạng thái</th>
                                <th style="width:70px">Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result->num_rows > 0): ?>
                                <?php $stt = 1; while ($row = $result->fetch_assoc()):
                                    $badge      = $statusBadge[$row['status']] ?? ['color'=>'secondary','text'=>$row['status'],'icon'=>'circle'];
                                    $profit     = floatval($row['profit']);
                                    $email_sent    = $row['email_sent']    ?? 'no';
                                    $email_sent_at = $row['email_sent_at'] ?? null;
                                    $vat_status    = $row['vat_invoice_status'] ?? '';
                                    $vat_no        = $row['vat_invoice_no']     ?? '';

                                    // ✅ Lọc VAT phía PHP (vì WHERE clause không có điều kiện vat_status)
                                    $vat_filter_val = $_GET['vat_status'] ?? '';
                                    if ($vat_filter_val === 'issued'    && $vat_status !== 'issued')    continue;
                                    if ($vat_filter_val === 'cancelled' && $vat_status !== 'cancelled') continue;
                                    if ($vat_filter_val === 'none'      && !empty($vat_status))         continue;
                                ?>
                                <tr class="shipment-row"
                                    onclick="goToDetail(<?php echo $row['id']; ?>, event)"
                                    data-id="<?php echo $row['id']; ?>">

                                    <!-- # -->
                                    <td class="text-center text-muted"><?php echo $stt++; ?></td>

                                    <!-- Job No -->
                                    <td>
                                        <span class="job-no"><?php echo htmlspecialchars($row['job_no']); ?></span>
                                        <?php if ($row['is_locked'] == 'yes'): ?>
                                            <br><span class="badge bg-danger locked-badge">
                                                <i class="bi bi-lock-fill"></i> Đã khóa
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($row['invoice_no']): ?>
                                            <br><small class="text-muted">
                                                <i class="bi bi-receipt"></i>
                                                <?php echo htmlspecialchars($row['invoice_no']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Khách hàng -->
                                    <td>
                                        <span class="badge bg-info text-dark">
                                            <?php echo htmlspecialchars($row['customer_short']); ?>
                                        </span>
                                        <br><small class="text-muted">
                                            <?php echo htmlspecialchars($row['company_name']); ?>
                                        </small>
                                    </td>

                                    <!-- MAWB / HAWB -->
                                    <td>
                                        <small>
                                            <span class="text-muted">M:</span>
                                            <strong><?php echo htmlspecialchars($row['mawb']); ?></strong><br>
                                            <span class="text-muted">H:</span>
                                            <?php echo htmlspecialchars($row['hawb']); ?>
                                        </small>
                                    </td>

                                    <!-- Số tờ khai -->
                                    <td>
                                        <?php if (!empty($row['customs_declaration_no'])): ?>
                                            <span class="cd-badge"
                                                  title="<?php echo htmlspecialchars($row['customs_declaration_no']); ?>">
                                                <i class="bi bi-file-earmark-text"></i>
                                                <?php echo htmlspecialchars($row['customs_declaration_no']); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted small">—</span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Shipper / CNEE -->
                                    <td>
                                        <small>
                                            <?php if ($row['shipper']): ?>
                                                <i class="bi bi-box-arrow-up text-primary"></i>
                                                <?php echo htmlspecialchars($row['shipper']); ?><br>
                                            <?php endif; ?>
                                            <?php if ($row['cnee']): ?>
                                                <i class="bi bi-box-arrow-down text-success"></i>
                                                <?php echo htmlspecialchars($row['cnee']); ?>
                                            <?php endif; ?>
                                            <?php if (!$row['shipper'] && !$row['cnee']): ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </small>
                                    </td>

                                    <!-- VSL/FLIGHT -->
                                    <td>
                                        <small>
                                            <?php if ($row['vessel_flight']): ?>
                                                <i class="bi bi-airplane text-primary"></i>
                                                <?php echo htmlspecialchars($row['vessel_flight']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </small>
                                    </td>

                                    <!-- Kiện / GW / CW -->
                                    <td>
                                        <small>
                                            <?php if ($row['packages']): ?>
                                                <i class="bi bi-stack"></i>
                                                <?php echo number_format($row['packages']); ?> kiện<br>
                                            <?php endif; ?>
                                            <?php if ($row['gw']): ?>
                                                GW: <?php echo number_format($row['gw'], 1); ?> kg<br>
                                            <?php endif; ?>
                                            <?php if ($row['cw']): ?>
                                                CW: <?php echo number_format($row['cw'], 1); ?>
                                            <?php endif; ?>
                                            <?php if (!$row['packages'] && !$row['gw'] && !$row['cw']): ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </small>
                                    </td>

                                    <!-- Ngày đến -->
                                    <td>
                                        <small>
                                            <?php if ($row['arrival_date']): ?>
                                                <i class="bi bi-calendar-check text-success"></i>
                                                <?php echo date('d/m/Y', strtotime($row['arrival_date'])); ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </small>
                                        <br>
                                        <small class="text-muted">
                                            <?php echo date('d/m/Y', strtotime($row['created_at'])); ?>
                                        </small>
                                    </td>

                                    <!-- COST -->
                                    <td class="text-end">
                                        <small class="text-danger fw-bold">
                                            <?php echo $row['total_cost'] > 0
                                                ? number_format($row['total_cost'], 0, ',', '.')
                                                : '—'; ?>
                                        </small>
                                    </td>

                                    <!-- SELL -->
                                    <td class="text-end">
                                        <small class="text-success fw-bold">
                                            <?php echo $row['total_sell'] > 0
                                                ? number_format($row['total_sell'], 0, ',', '.')
                                                : '—'; ?>
                                        </small>
                                    </td>

                                    <!-- Lợi nhuận -->
                                    <td class="text-end">
                                        <?php if ($row['total_cost'] > 0 || $row['total_sell'] > 0): ?>
                                            <small class="<?php echo $profit >= 0 ? 'profit-pos' : 'profit-neg'; ?>">
                                                <?php echo ($profit >= 0 ? '+' : '') . number_format($profit, 0, ',', '.'); ?>
                                            </small>
                                        <?php else: ?>
                                            <small class="text-muted">—</small>
                                        <?php endif; ?>
                                    </td>

                                    <!-- DEBIT NOTE / EMAIL -->
                                    <td class="text-center">
                                        <?php if ($email_sent === 'yes'): ?>
                                            <span class="email-sent-badge sent">
                                                <i class="bi bi-envelope-check-fill"></i> Đã gửi
                                            </span>
                                            <?php if ($email_sent_at): ?>
                                                <span class="email-sent-time">
                                                    <?php echo date('d/m/Y H:i', strtotime($email_sent_at)); ?>
                                                </span>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="email-sent-badge not-sent">
                                                <i class="bi bi-envelope-x"></i> Chưa gửi
                                            </span>
                                        <?php endif; ?>
                                    </td>

                                    <!-- ✅ CỘT HĐ VAT MỚI -->
                                    <td class="text-center" onclick="event.stopPropagation()">
                                        <?php if ($vat_status === 'issued'): ?>
                                            <a href="vat_invoice.php?id=<?php echo $row['id']; ?>&tab=info"
                                               class="vat-badge issued"
                                               title="Số HĐ: <?php echo htmlspecialchars($vat_no); ?>">
                                                <i class="bi bi-receipt-cutoff"></i>
                                                <?php echo htmlspecialchars($vat_no) ?: 'Đã xuất'; ?>
                                            </a>
                                        <?php elseif ($vat_status === 'cancelled'): ?>
                                            <a href="vat_invoice.php?id=<?php echo $row['id']; ?>&tab=info"
                                               class="vat-badge cancelled"
                                               title="Hóa đơn đã hủy">
                                                <i class="bi bi-x-circle"></i> Đã hủy
                                            </a>
                                        <?php elseif (!empty($vat_status)): ?>
                                            <a href="vat_invoice.php?id=<?php echo $row['id']; ?>"
                                               class="vat-badge pending"
                                               title="Trạng thái: <?php echo htmlspecialchars($vat_status); ?>">
                                                <i class="bi bi-hourglass-split"></i>
                                                <?php echo htmlspecialchars($vat_status); ?>
                                            </a>
                                        <?php else: ?>
                                            <a href="vat_invoice.php?id=<?php echo $row['id']; ?>"
                                               class="vat-badge none"
                                               title="Chưa xuất hóa đơn VAT — Click để phát hành">
                                                <i class="bi bi-plus-circle"></i> Xuất HĐ
                                            </a>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Trạng thái -->
                                    <td>
                                        <span class="badge bg-<?php echo $badge['color']; ?>">
                                            <i class="bi bi-<?php echo $badge['icon']; ?>"></i>
                                            <?php echo $badge['text']; ?>
                                        </span>
                                    </td>

                                    <!-- Thao tác -->
                                    <td onclick="event.stopPropagation()">
                                        <div class="d-flex gap-1 justify-content-center action-btn">
                                            <a href="edit.php?id=<?php echo $row['id']; ?>"
                                               class="btn btn-warning btn-sm" title="Sửa">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <?php if ($_SESSION['role'] == 'admin'): ?>
                                            <a href="delete.php?id=<?php echo $row['id']; ?>"
                                               class="btn btn-danger btn-sm" title="Xóa"
                                               onclick="return confirm('Xóa lô hàng <?php echo htmlspecialchars($row['job_no']); ?>?')">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endwhile; ?>

                            <?php else: ?>
                                <tr>
                                    <!-- ✅ colspan 16 vì thêm 1 cột HĐ VAT -->
                                    <td colspan="16" class="text-center py-5">
                                        <i class="bi bi-inbox" style="font-size:3rem;color:#ccc"></i>
                                        <p class="text-muted mt-2 mb-2">Không tìm thấy lô hàng nào</p>
                                        <?php if ($search || $status_filter || $customer_filter || $date_from || $date_to || $email_filter || !empty($_GET['vat_status'])): ?>
                                            <a href="index.php" class="btn btn-sm btn-outline-primary">
                                                <i class="bi bi-x"></i> Xóa bộ lọc
                                            </a>
                                        <?php else: ?>
                                            <a href="add.php" class="btn btn-sm btn-primary">
                                                <i class="bi bi-plus-circle"></i> Thêm lô hàng đầu tiên
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div>

    <footer class="bg-white text-center py-2 border-top">
        <small class="text-muted">&copy; <?php echo date('Y'); ?> Forwarder System</small>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function goToDetail(id, event) {
            if (event.target.closest('.action-btn')) return;
            window.location.href = 'view.php?id=' + id;
        }

        function filterByStatus(status) {
            document.getElementById('statusSelect').value = status;
            document.getElementById('filterForm').submit();
        }

        function filterByEmail(val) {
            document.getElementById('emailSelect').value = val;
            document.getElementById('filterForm').submit();
        }

        // ✅ THÊM: lọc theo VAT status
        function filterByVat(val) {
            document.getElementById('vatSelect').value = val;
            document.getElementById('filterForm').submit();
        }

        document.querySelectorAll('#filterForm select').forEach(sel => {
            sel.addEventListener('change', () => {
                document.getElementById('filterForm').submit();
            });
        });
    </script>
</body>
</html>