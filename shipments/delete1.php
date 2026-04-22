<?php
require_once '../config/database.php';
checkLogin();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id == 0) {
    header("Location: index.php");
    exit();
}

$conn = getDBConnection();

// Xóa liên kết với nhà cung cấp trước (do có foreign key)
$stmt = $conn->prepare("DELETE FROM shipment_suppliers WHERE shipment_id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();

// Xóa lô hàng
$stmt = $conn->prepare("DELETE FROM shipments WHERE id = ?");
$stmt->bind_param("i", $id);

if ($stmt->execute()) {
    header("Location: index.php?success=deleted");
} else {
    header("Location: index.php?error=delete_failed");
}

$stmt->close();
$conn->close();
exit();
?>