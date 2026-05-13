<?php
require_once 'config/database.php';
checkLogin();

$conn = getDBConnection();

// Thống kê tổng quan
$stats = [
    'total_shipments' => $conn->query("SELECT COUNT(*) as count FROM shipments")->fetch_assoc()['count'],
    'shipments_pending' => $conn->query("SELECT COUNT(*) as count FROM shipments WHERE status='pending'")->fetch_assoc()['count'],
    'shipments_in_transit' => $conn->query("SELECT COUNT(*) as count FROM shipments WHERE status='in_transit'")->fetch_assoc()['count'],
    'shipments_arrived' => $conn->query("SELECT COUNT(*) as count FROM shipments WHERE status='arrived'")->fetch_assoc()['count'],
    'shipments_cleared' => $conn->query("SELECT COUNT(*) as count FROM shipments WHERE status='cleared'")->fetch_assoc()['count'],
    'shipments_delivered' => $conn->query("SELECT COUNT(*) as count FROM shipments WHERE status='delivered'")->fetch_assoc()['count'],
    'shipments_locked' => $conn->query("SELECT COUNT(*) as count FROM shipments WHERE is_locked='yes'")->fetch_assoc()['count'],
    'shipments_unlocked' => $conn->query("SELECT COUNT(*) as count FROM shipments WHERE is_locked='no'")->fetch_assoc()['count'],
    'total_customers' => $conn->query("SELECT COUNT(*) as count FROM customers")->fetch_assoc()['count'],
    'active_customers' => $conn->query("SELECT COUNT(*) as count FROM customers WHERE status='active'")->fetch_assoc()['count'],
    'total_suppliers' => $conn->query("SELECT COUNT(*) as count FROM suppliers")->fetch_assoc()['count'],
    'active_suppliers' => $conn->query("SELECT COUNT(*) as count FROM suppliers WHERE status='active'")->fetch_assoc()['count'],
    'total_users' => $conn->query("SELECT COUNT(*) as count FROM accounts")->fetch_assoc()['count'],
    'active_users' => $conn->query("SELECT COUNT(*) as count FROM accounts WHERE status='active'")->fetch_assoc()['count'],
    'total_cost_codes' => $conn->query("SELECT COUNT(*) as count FROM cost_codes")->fetch_assoc()['count'],
];

// Lấy 5 lô hàng mới nhất
$recent_shipments = $conn->query("SELECT s.*, c.short_name as customer_short_name 
                                   FROM shipments s 
                                   LEFT JOIN customers c ON s.customer_id = c.id 
                                   ORDER BY s.created_at DESC 
                                   LIMIT 5");

// Tính tổng doanh thu và chi phí
$total_cost_query = $conn->query("SELECT SUM(total_amount) as total FROM shipment_costs");
$total_cost = $total_cost_query->fetch_assoc()['total'] ?? 0;

$total_sell_query = $conn->query("SELECT SUM(total_amount) as total FROM shipment_sells");
$total_sell = $total_sell_query->fetch_assoc()['total'] ?? 0;

$total_profit = $total_sell - $total_cost;

$conn->close();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Forwarder System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .stat-card {
            transition: transform 0.2s;
        }
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        .stat-icon {
            font-size: 2.5rem;
            opacity: 0.8;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="bi bi-box-seam"></i> Forwarder System
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="dashboard.php">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="customers/index.php">
                            <i class="bi bi-people"></i> Khách hàng
                        </a>
                    </li>
                    
			<li class="nav-item">
                        <a class="nav-link" href="quotations/index.php">
                            <i class="bi bi-box"></i> Báo Giá
                        </a>
                    </li>
			<li class="nav-item">
                        <a class="nav-link" href="shipments/index.php">
                            <i class="bi bi-box"></i> Lô hàng
                        </a>
                    </li>
		    <li class="nav-item"><a class="nav-link" href="debt/index.php">Công Nợ
			</a>
		    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="suppliers/index.php">
                            <i class="bi bi-truck"></i> Nhà cung cấp
                        </a>
                    </li>
                    <?php if ($_SESSION['role'] == 'admin'): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-gear"></i> Quản trị
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="accounts/index.php">
                                <i class="bi bi-person-badge"></i> Tài khoản
                            </a></li>
                            <li><a class="dropdown-item" href="cost_codes/index.php">
                                <i class="bi bi-tag"></i> Mã chi phí
                            </a></li>
                        </ul>
                    </li>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($_SESSION['full_name']); ?>
                            <span class="badge bg-light text-dark"><?php echo $_SESSION['role'] == 'admin' ? 'Admin' : 'User'; ?></span>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="logout.php">
                                <i class="bi bi-box-arrow-right"></i> Đăng xuất
                            </a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Content -->
    <div class="container-fluid mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-speedometer2"></i> Dashboard</h2>
            <div class="text-muted">
                <i class="bi bi-calendar"></i> <?php echo date('d/m/Y H:i'); ?>
            </div>
        </div>

        <!-- Thống kê tổng quan -->
        <div class="row mb-4">
            <div class="col-12">
                <h5 class="mb-3"><i class="bi bi-graph-up"></i> Tổng quan hệ thống</h5>
            </div>
            
            <!-- Lô hàng -->
            <div class="col-md-3 mb-3">
                <div class="card stat-card border-primary">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted">Tổng lô hàng</h6>
                                <h2 class="text-primary mb-0"><?php echo $stats['total_shipments']; ?></h2>
                            </div>
                            <i class="bi bi-box stat-icon text-primary"></i>
                        </div>
                    </div>
                    <div class="card-footer bg-primary text-white">
                        <a href="shipments/index.php" class="text-white text-decoration-none">
                            Xem chi tiết <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Khách hàng -->
            <div class="col-md-3 mb-3">
                <div class="card stat-card border-info">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted">Khách hàng</h6>
                                <h2 class="text-info mb-0"><?php echo $stats['total_customers']; ?></h2>
                                <small class="text-muted"><?php echo $stats['active_customers']; ?> đang hoạt động</small>
                            </div>
                            <i class="bi bi-people stat-icon text-info"></i>
                        </div>
                    </div>
                    <div class="card-footer bg-info text-white">
                        <a href="customers/index.php" class="text-white text-decoration-none">
                            Xem chi tiết <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Nhà cung cấp -->
            <div class="col-md-3 mb-3">
                <div class="card stat-card border-success">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted">Nhà cung cấp</h6>
                                <h2 class="text-success mb-0"><?php echo $stats['total_suppliers']; ?></h2>
                                <small class="text-muted"><?php echo $stats['active_suppliers']; ?> đang hoạt động</small>
                            </div>
                            <i class="bi bi-truck stat-icon text-success"></i>
                        </div>
                    </div>
                    <div class="card-footer bg-success text-white">
                        <a href="suppliers/index.php" class="text-white text-decoration-none">
                            Xem chi tiết <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Tài khoản -->
            <?php if ($_SESSION['role'] == 'admin'): ?>
            <div class="col-md-3 mb-3">
                <div class="card stat-card border-warning">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h6 class="text-muted">Tài khoản</h6>
                                <h2 class="text-warning mb-0"><?php echo $stats['total_users']; ?></h2>
                                <small class="text-muted"><?php echo $stats['active_users']; ?> đang hoạt động</small>
                            </div>
                            <i class="bi bi-person-badge stat-icon text-warning"></i>
                        </div>
                    </div>
                    <div class="card-footer bg-warning text-dark">
                        <a href="accounts/index.php" class="text-dark text-decoration-none">
                            Xem chi tiết <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Thống kê trạng thái lô hàng -->
        <div class="row mb-4">
            <div class="col-12">
                <h5 class="mb-3"><i class="bi bi-pie-chart"></i> Trạng thái lô hàng</h5>
            </div>

            <div class="col-md-2 mb-3">
                <div class="card border-warning">
                    <div class="card-body text-center">
                        <i class="bi bi-hourglass-split text-warning" style="font-size: 2rem;"></i>
                        <h3 class="mt-2 text-warning"><?php echo $stats['shipments_pending']; ?></h3>
                        <p class="mb-0 text-muted">Chờ xử lý</p>
                    </div>
                </div>
            </div>

            <div class="col-md-2 mb-3">
                <div class="card border-primary">
                    <div class="card-body text-center">
                        <i class="bi bi-truck text-primary" style="font-size: 2rem;"></i>
                        <h3 class="mt-2 text-primary"><?php echo $stats['shipments_in_transit']; ?></h3>
                        <p class="mb-0 text-muted">Đang vận chuyển</p>
                    </div>
                </div>
            </div>

            <div class="col-md-2 mb-3">
                <div class="card border-info">
                    <div class="card-body text-center">
                        <i class="bi bi-geo-alt text-info" style="font-size: 2rem;"></i>
                        <h3 class="mt-2 text-info"><?php echo $stats['shipments_arrived']; ?></h3>
                        <p class="mb-0 text-muted">Đã đến</p>
                    </div>
                </div>
            </div>

            <div class="col-md-2 mb-3">
                <div class="card border-success">
                    <div class="card-body text-center">
                        <i class="bi bi-check-circle text-success" style="font-size: 2rem;"></i>
                        <h3 class="mt-2 text-success"><?php echo $stats['shipments_cleared']; ?></h3>
                        <p class="mb-0 text-muted">Đã thông quan</p>
                    </div>
                </div>
            </div>

            <div class="col-md-2 mb-3">
                <div class="card border-dark">
                    <div class="card-body text-center">
                        <i class="bi bi-box-seam text-dark" style="font-size: 2rem;"></i>
                        <h3 class="mt-2 text-dark"><?php echo $stats['shipments_delivered']; ?></h3>
                        <p class="mb-0 text-muted">Đã giao</p>
                    </div>
                </div>
            </div>

            <div class="col-md-2 mb-3">
                <div class="card border-danger">
                    <div class="card-body text-center">
                        <i class="bi bi-lock-fill text-danger" style="font-size: 2rem;"></i>
                        <h3 class="mt-2 text-danger"><?php echo $stats['shipments_locked']; ?></h3>
                        <p class="mb-0 text-muted">Đã khóa</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tài chính -->
        <div class="row mb-4">
            <div class="col-12">
                <h5 class="mb-3"><i class="bi bi-cash-stack"></i> Tổng quan tài chính</h5>
            </div>

            <div class="col-md-3">
                <div class="card border-danger">
                    <div class="card-body text-center">
                        <h6 class="text-danger">Tổng Chi phí (COST)</h6>
                        <h3 class="text-danger"><?php echo number_format($total_cost, 0, ',', '.'); ?></h3>
                        <small class="text-muted">VND</small>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card border-success">
                    <div class="card-body text-center">
                        <h6 class="text-success">Tổng Doanh thu (SELL)</h6>
                        <h3 class="text-success"><?php echo number_format($total_sell, 0, ',', '.'); ?></h3>
                        <small class="text-muted">VND</small>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card border-primary">
                    <div class="card-body text-center">
                        <h6 class="text-primary">Lợi nhuận</h6>
                        <h3 class="<?php echo $total_profit >= 0 ? 'text-primary' : 'text-warning'; ?>">
                            <?php echo number_format($total_profit, 0, ',', '.'); ?>
                        </h3>
                        <small class="<?php echo $total_profit >= 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo $total_profit >= 0 ? 'Lãi' : 'Lỗ'; ?>
                        </small>
                    </div>
                </div>
            </div>

            <div class="col-md-3">
                <div class="card border-info">
                    <div class="card-body text-center">
                        <h6 class="text-info">Mã chi phí</h6>
                        <h3 class="text-info"><?php echo $stats['total_cost_codes']; ?></h3>
                        <small class="text-muted">
                            <a href="cost_codes/index.php" class="text-decoration-none">Quản lý</a>
                        </small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Lô hàng mới nhất -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-clock-history"></i> 5 Lô hàng mới nhất</h5>
                        <a href="shipments/index.php" class="btn btn-light btn-sm">Xem tất cả</a>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Job No</th>
                                        <th>Khách hàng</th>
                                        <th>HAWB/MAWB</th>
                                        <th>Trạng thái</th>
                                        <th>Ngày tạo</th>
                                        <th>Khóa</th>
                                        <th>Thao tác</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($recent_shipments->num_rows > 0): ?>
                                        <?php while ($row = $recent_shipments->fetch_assoc()): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($row['job_no']); ?></strong></td>
                                                <td><span class="badge bg-info"><?php echo htmlspecialchars($row['customer_short_name']); ?></span></td>
                                                <td><small><?php echo htmlspecialchars($row['hawb'] . ' / ' . $row['mawb']); ?></small></td>
                                                <td>
                                                    <?php
                                                    $statusColors = [
                                                        'pending' => 'warning',
                                                        'in_transit' => 'primary',
                                                        'arrived' => 'info',
                                                        'cleared' => 'success',
                                                        'delivered' => 'dark',
                                                        'cancelled' => 'danger'
                                                    ];
                                                    $statusTexts = [
                                                        'pending' => 'Chờ xử lý',
                                                        'in_transit' => 'Đang vận chuyển',
                                                        'arrived' => 'Đã đến',
                                                        'cleared' => 'Đã thông quan',
                                                        'delivered' => 'Đã giao',
                                                        'cancelled' => 'Hủy'
                                                    ];
                                                    ?>
                                                    <span class="badge bg-<?php echo $statusColors[$row['status']] ?? 'secondary'; ?>">
                                                        <?php echo $statusTexts[$row['status']] ?? $row['status']; ?>
                                                    </span>
                                                </td>
                                                <td><small><?php echo date('d/m/Y H:i', strtotime($row['created_at'])); ?></small></td>
                                                <td>
                                                    <?php if ($row['is_locked'] == 'yes'): ?>
                                                        <i class="bi bi-lock-fill text-danger"></i>
                                                    <?php else: ?>
                                                        <i class="bi bi-unlock text-success"></i>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <a href="shipments/view.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-info">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center text-muted py-4">
                                                Chưa có lô hàng nào
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="row mt-4 mb-4">
            <div class="col-12">
                <h5 class="mb-3"><i class="bi bi-lightning"></i> Thao tác nhanh</h5>
            </div>
            <div class="col-md-3">
                <a href="shipments/add.php" class="btn btn-primary w-100 mb-3">
                    <i class="bi bi-plus-circle"></i> Thêm lô hàng mới
                </a>
            </div>
            <div class="col-md-3">
                <a href="customers/add.php" class="btn btn-info w-100 mb-3">
                    <i class="bi bi-person-plus"></i> Thêm khách h  ng
                </a>
            </div>
            <div class="col-md-3">
                <a href="suppliers/add.php" class="btn btn-success w-100 mb-3">
                    <i class="bi bi-building-add"></i> Thêm nhà cung cấp
                </a>
            </div>
            <?php if ($_SESSION['role'] == 'admin'): ?>
            <div class="col-md-3">
                <a href="cost_codes/add.php" class="btn btn-warning w-100 mb-3">
                    <i class="bi bi-tag"></i> Thêm mã chi phí
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <footer class="bg-light text-center py-3 mt-5">
        <p class="mb-0 text-muted">
            &copy; <?php echo date('Y'); ?> Forwarder System - Quản lý vận tải quốc tế
        </p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>