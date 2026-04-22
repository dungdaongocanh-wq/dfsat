<?php
require_once '../config/database.php';
header('Content-Type: application/json');

$code = isset($_GET['code']) ? strtoupper(trim($_GET['code'])) : '';

if (empty($code)) {
    echo json_encode(['success' => false]);
    exit();
}

$conn = getDBConnection();
$stmt = $conn->prepare("SELECT id, description FROM cost_codes WHERE code = ? AND status = 'active'");
$stmt->bind_param("s", $code);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows > 0) {
    $row = $result->fetch_assoc();
    echo json_encode([
        'success' => true,
        'id' => $row['id'],
        'description' => $row['description']
    ]);
} else {
    echo json_encode(['success' => false]);
}

$stmt->close();
$conn->close();
?>