<?php
// Cấu hình kết nối database
define('DB_HOST', 'localhost');
define('DB_USER', 'liprolog_user');
define('DB_PASS', 'dung@123A');
define('DB_NAME', 'liprolog_forwarder');

// Tạo kết nối
function getDBConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($conn->connect_error) {
        die("Kết nối thất bại: " . $conn->connect_error);
    }
    $conn->set_charset("utf8mb4");
    return $conn;
}

// Bắt đầu session
session_start();

// ─────────────────────────────────────────
// HÀM XÁC THỰC & PHÂN QUYỀN
// ─────────────────────────────────────────

// Kiểm tra đăng nhập, nếu chưa → redirect login
function checkLogin() {
    if (!isset($_SESSION['user_id'])) {
        header("Location: /lipro/login.php");
        exit();
    }
}

// Kiểm tra quyền admin, nếu không → dừng hẳn
function checkAdmin() {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        die("Bạn không có quyền truy cập!");
    }
}

// Có phải admin không? (true/false)
function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
}

// Có phải supplier (nhà cung cấp) không? (true/false)
function isSupplier() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'supplier';
}

// Có phải admin hoặc staff (user) không? (true/false)
function isAdminOrStaff() {
    return isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'user']);
}

// Lấy role hiện tại
function getCurrentRole() {
    return $_SESSION['role'] ?? '';
}

// Lấy user_id hiện tại
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

// Lấy tên hiện tại
function getCurrentUserName() {
    return $_SESSION['full_name'] ?? '';
}

// ─────────────────────────────────────────
// HÀM THÔNG BÁO
// ─────────────────────────────────────────

// Đếm số thông báo chưa đọc của user hiện tại
function getUnreadNotificationCount($conn) {
    if (!isset($_SESSION['user_id'])) return 0;
    $uid  = intval($_SESSION['user_id']);
    $res  = $conn->query("SELECT COUNT(*) c FROM notifications WHERE user_id = $uid AND is_read = 0");
    if (!$res) return 0;
    return intval($res->fetch_assoc()['c'] ?? 0);
}

// ─────────────────────────────────────────
// HÀM TẠO JOB NO
// ─────────────────────────────────────────

function generateJobNo($conn) {
    $year  = date('Y');
    $month = date('m');

    $stmt = $conn->prepare("SELECT counter FROM job_no_counter WHERE year = ? AND month = ?");
    $stmt->bind_param("ii", $year, $month);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows > 0) {
        $row     = $result->fetch_assoc();
        $counter = $row['counter'] + 1;

        $stmt_update = $conn->prepare("UPDATE job_no_counter SET counter = ? WHERE year = ? AND month = ?");
        $stmt_update->bind_param("iii", $counter, $year, $month);
        $stmt_update->execute();
        $stmt_update->close();
    } else {
        $counter = 1;

        $stmt_insert = $conn->prepare("INSERT INTO job_no_counter (year, month, counter) VALUES (?, ?, ?)");
        $stmt_insert->bind_param("iii", $year, $month, $counter);
        $stmt_insert->execute();
        $stmt_insert->close();
    }

    $stmt->close();

    // Format: JOB-YYYYMM-0001
    return sprintf("JOB-%s%s-%04d", $year, $month, $counter);
}