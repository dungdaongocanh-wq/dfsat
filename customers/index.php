<?php
require_once '../config/database.php';
checkLogin();

$conn = getDBConnection();

// Xử lý tìm kiếm
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';

$whereConditions = [];

if ($search) {
    $whereConditions[] = "(company_name LIKE '%$search%' OR short_name LIKE '%$search%' OR tax_code LIKE '%$search%' OR contact_person LIKE '%$search%')";
}

if ($status_filter) {
    $whereConditions[] = "status = '$status_filter'";
}

$whereClause = '';
if (count($whereConditions) > 0) {
    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
}

// Lấy danh sách khách hàng
$sql = "SELECT * FROM customers $whereClause ORDER BY created_at DESC";
$result = $conn->query($sql);

// Thống kê
$total_customers = $conn->query("SELECT COUNT(*) as count FROM customers")->fetch_assoc()['count'];
$active_customers = $conn->query("SELECT COUNT(*) as count FROM customers WHERE status='active'")->fetch_assoc()['count'];
$inactive_customers = $conn->query("SELECT COUNT(*) as count FROM customers WHERE status='inactive'")->fetch_assoc()['count'];

$conn->close();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Khách hàng - Forwarder System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="../dashboard.php">
                <i class="bi bi-box-seam"></i> Forwarder System
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="../dashboard.php">Dashboard</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="index.php">Khách hàng</a>
                    </li>
		   <li class="nav-item">
                        <a class="nav-link" href="../quotations/index.php">Báo Giá</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../shipments/index.php">Lô hàng</a>
                    </li>
		    <li class="nav-item"><a class="nav-link" href="../debt/index.php">Công Nợ</a>
		    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../suppliers/index.php">Nhà cung cấp</a>
                    </li>
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
                        <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle"></i> <?php echo $_SESSION['full_name']; ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="../logout.php">Đăng xuất</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Content -->
    <div class="container-fluid mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-people"></i> Quản lý Khách hàng</h2>
            <div>
                <?php if ($_SESSION['role'] == 'admin'): ?>
                <a href="import.php" class="btn btn-success">
                    <i class="bi bi-file-earmark-excel"></i> Import Excel
                </a>
                <?php endif; ?>
                <a href="add.php" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> Thêm khách hàng
                </a>
            </div>
        </div>

        <!-- Thống kê -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card border-primary">
                    <div class="card-body text-center">
                        <h6 class="text-primary">Tổng khách hàng</h6>
                        <h2 class="text-primary"><?php echo $total_customers; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-success">
                    <div class="card-body text-center">
                        <h6 class="text-success">Đang hoạt động</h6>
                        <h2 class="text-success"><?php echo $active_customers; ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card border-secondary">
                    <div class="card-body text-center">
                        <h6 class="text-secondary">Ngưng hoạt động</h6>
                        <h2 class="text-secondary"><?php echo $inactive_customers; ?></h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tìm kiếm và lọc -->
        <div class="card mb-3">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-8">
                        <input type="text" name="search" class="form-control" placeholder="Tìm kiếm theo tên công ty, tên viết tắt, MST, người liên hệ..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-2">
                        <select name="status" class="form-select">
                            <option value="">-- Tất cả trạng thái --</option>
                            <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Hoạt động</option>
                            <option value="inactive" <?php echo $status_filter == 'inactive' ? 'selected' : ''; ?>>Ngưng hoạt động</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="bi bi-search"></i> Tìm kiếm
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Thông báo -->
        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <?php
                    if ($_GET['success'] == 'added') echo '<i class="bi bi-check-circle"></i> Thêm khách hàng thành công!';
                    if ($_GET['success'] == 'updated') echo '<i class="bi bi-check-circle"></i> Cập nhật khách hàng thành công!';
                    if ($_GET['success'] == 'deleted') echo '<i class="bi bi-check-circle"></i> Xóa khách hàng thành công!';
                    if ($_GET['success'] == 'imported') echo '<i class="bi bi-check-circle"></i> Import dữ liệu thành công!';
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <?php
                    if ($_GET['error'] == 'delete_failed') echo '<i class="bi bi-exclamation-triangle"></i> Không thể xóa khách hàng!';
                    if ($_GET['error'] == 'in_use') echo '<i class="bi bi-exclamation-triangle"></i> Không thể xóa khách hàng đang có lô hàng!';
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Bảng danh sách -->
        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>STT</th>
                                <th>Tên viết tắt</th>
                                <th>Tên công ty</th>
                                <th>MST</th>
                                <th>Địa chỉ</th>
                                <th>Liên hệ</th>
                                <th>Trạng thái</th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($result->num_rows > 0): ?>
                                <?php $stt = 1; while ($row = $result->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo $stt++; ?></td>
                                        <td>
                                            <span class="badge bg-info fs-6"><?php echo htmlspecialchars($row['short_name']); ?></span>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($row['company_name']); ?></strong>
                                        </td>
                                        <td><?php echo htmlspecialchars($row['tax_code']); ?></td>
                                        <td>
                                            <small><?php echo htmlspecialchars($row['address']); ?></small>
                                        </td>
                                        <td>
                                            <small>
                                                <i class="bi bi-person"></i> <?php echo htmlspecialchars($row['contact_person']); ?><br>
                                                <i class="bi bi-telephone"></i> <?php echo htmlspecialchars($row['phone']); ?><br>
                                                <i class="bi bi-envelope"></i> <?php echo htmlspecialchars($row['email']); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php if ($row['status'] == 'active'): ?>
                                                <span class="badge bg-success">Hoạt động</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Ngưng</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <a href="view.php?id=<?php echo $row['id']; ?>" class="btn btn-info" title="Xem chi tiết">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="edit.php?id=<?php echo $row['id']; ?>" class="btn btn-warning" title="Sửa">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <?php if ($_SESSION['role'] == 'admin'): ?>
                                                <a href="delete.php?id=<?php echo $row['id']; ?>" class="btn btn-danger" onclick="return confirm('Bạn có chắc muốn xóa khách hàng này?')" title="Xóa">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="text-center py-4">
                                        <i class="bi bi-inbox" style="font-size: 3rem; color: #ccc;"></i>
                                        <p class="text-muted mt-2">Không tìm thấy khách hàng nào</p>
                                        <?php if ($search || $status_filter): ?>
                                            <a href="index.php" class="btn btn-sm btn-primary">Xóa bộ lọc</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Hướng dẫn nhanh -->
        <div class="card mt-4 bg-light">
            <div class="card-body">
                <h6><i class="bi bi-lightbulb"></i> Hướng dẫn sử dụng:</h6>
                <ul class="mb-0">
                    <li><strong>Thêm khách hàng:</strong> Click nút "Thêm khách hàng" để tạo khách hàng mới thủ công</li>
                    <li><strong>Import Excel:</strong> Click nút "Import Excel" để nhập hàng loạt từ file Excel (chỉ Admin)</li>
                    <li><strong>Tìm kiếm:</strong> Nhập từ khóa vào ô tìm kiếm để lọc danh sách</li>
                    <li><strong>Sửa/Xóa:</strong> Click icon tương ứng trong cột "Thao tác"</li>
                    <li><strong>Xem chi tiết:</strong> Click icon mắt để xem đầy đủ thông tin khách hàng</li>
                </ul>
            </div>
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