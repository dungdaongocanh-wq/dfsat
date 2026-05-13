<?php
require_once '../config/database.php';
checkLogin();
checkAdmin();

$error = '';
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id == 0) {
    header("Location: index.php");
    exit();
}

$conn = getDBConnection();

// Lấy thông tin tài khoản
$stmt = $conn->prepare("SELECT * FROM accounts WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows == 0) {
    header("Location: index.php");
    exit();
}

$account = $result->fetch_assoc();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $full_name = trim($_POST['full_name']);
    $email = trim($_POST['email']);
    $role = $_POST['role'];
    $status = $_POST['status'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    if (empty($username) || empty($full_name)) {
        $error = 'Vui lòng nhập đầy đủ thông tin bắt buộc!';
    } elseif (!empty($new_password) && $new_password !== $confirm_password) {
        $error = 'Mật khẩu xác nhận không khớp!';
    } elseif (!empty($new_password) && strlen($new_password) < 6) {
        $error = 'Mật khẩu phải có ít nhất 6 ký tự!';
    } else {
        // Kiểm tra username trùng (trừ chính nó)
        $stmt = $conn->prepare("SELECT id FROM accounts WHERE username = ? AND id != ?");
        $stmt->bind_param("si", $username, $id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows > 0) {
            $error = 'Tên đăng nhập đã tồn tại!';
        } else {
            // Cập nhật
            if (!empty($new_password)) {
                // Có đổi mật khẩu
                $stmt = $conn->prepare("UPDATE accounts SET username=?, password=MD5(?), full_name=?, email=?, role=?, status=? WHERE id=?");
                $stmt->bind_param("ssssssi", $username, $new_password, $full_name, $email, $role, $status, $id);
            } else {
                // Không đổi mật khẩu
                $stmt = $conn->prepare("UPDATE accounts SET username=?, full_name=?, email=?, role=?, status=? WHERE id=?");
                $stmt->bind_param("sssssi", $username, $full_name, $email, $role, $status, $id);
            }
            
            if ($stmt->execute()) {
                header("Location: index.php?success=updated");
                exit();
            } else {
                $error = 'Có lỗi xảy ra: ' . $conn->error;
            }
        }
    }
} else {
    $_POST = $account;
}

$stmt->close();
$conn->close();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sửa Tài khoản - Forwarder System</title>
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
                        <a class="nav-link" href="../customers/index.php">Khách hàng</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../shipments/index.php">Lô hàng</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="../suppliers/index.php">Nhà cung cấp</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="index.php">Tài khoản</a>
                    </li>
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
    <div class="container mt-4">
        <div class="row">
            <div class="col-md-6 offset-md-3">
                <div class="card">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0"><i class="bi bi-pencil"></i> Sửa thông tin Tài khoản</h5>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>

                        <form method="POST">
                            <div class="mb-3">
                                <label class="form-label">Tên đăng nhập <span class="text-danger">*</span></label>
                                <input type="text" name="username" class="form-control" required value="<?php echo htmlspecialchars($_POST['username']); ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Họ và tên <span class="text-danger">*</span></label>
                                <input type="text" name="full_name" class="form-control" required value="<?php echo htmlspecialchars($_POST['full_name']); ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($_POST['email']); ?>">
                            </div>

                            <hr>
                            <h6 class="text-muted">Đổi mật khẩu (để trống nếu không đổi)</h6>

                            <div class="mb-3">
                                <label class="form-label">Mật khẩu mới</label>
                                <input type="password" name="new_password" class="form-control" minlength="6">
                                <small class="text-muted">Tối thiểu 6 ký tự</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Xác nhận mật khẩu mới</label>
                                <input type="password" name="confirm_password" class="form-control" minlength="6">
                            </div>

                            <hr>

                            <div class="mb-3">
                                <label class="form-label">Quyền</label>
                                <select name="role" class="form-select">
                                    <option value="user" <?php echo $_POST['role'] == 'user' ? 'selected' : ''; ?>>User (Nhân viên)</option>
                                    <option value="admin" <?php echo $_POST['role'] == 'admin' ? 'selected' : ''; ?>>Admin (Quản trị viên)</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Trạng thái</label>
                                <select name="status" class="form-select">
                                    <option value="active" <?php echo $_POST['status'] == 'active' ? 'selected' : ''; ?>>Hoạt động</option>
                                    <option value="inactive" <?php echo $_POST['status'] == 'inactive' ? 'selected' : ''; ?>>Khóa</option>
                                </select>
                            </div>

                            <div class="d-flex justify-content-between">
                                <a href="index.php" class="btn btn-secondary">
                                    <i class="bi bi-arrow-left"></i> Quay lại
                                </a>
                                <button type="submit" class="btn btn-warning">
                                    <i class="bi bi-save"></i> Cập nhật
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>