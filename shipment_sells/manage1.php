<?php
require_once '../config/database.php';
checkLogin();

$shipment_id = isset($_GET['shipment_id']) ? intval($_GET['shipment_id']) : 0;

if ($shipment_id == 0) {
    header("Location: ../shipments/index.php");
    exit();
}

$conn = getDBConnection();

// Lấy thông tin lô hàng
$stmt = $conn->prepare("SELECT s.*, c.company_name FROM shipments s LEFT JOIN customers c ON s.customer_id = c.id WHERE s.id = ?");
$stmt->bind_param("i", $shipment_id);
$stmt->execute();
$shipment = $stmt->get_result()->fetch_assoc();

if (!$shipment) {
    header("Location: ../shipments/index.php");
    exit();
}

// Lấy danh sách sell
$stmt = $conn->prepare("SELECT ss.*, cc.code, cc.description 
                        FROM shipment_sells ss 
                        JOIN cost_codes cc ON ss.cost_code_id = cc.id 
                        WHERE ss.shipment_id = ? 
                        ORDER BY ss.created_at DESC");
$stmt->bind_param("i", $shipment_id);
$stmt->execute();
$sells = $stmt->get_result();

// Tính tổng sell
$stmt = $conn->prepare("SELECT SUM(total_amount) as total FROM shipment_sells WHERE shipment_id = ?");
$stmt->bind_param("i", $shipment_id);
$stmt->execute();
$total_sell = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

// Tính tổng cost
$stmt = $conn->prepare("SELECT SUM(total_amount) as total FROM shipment_costs WHERE shipment_id = ?");
$stmt->bind_param("i", $shipment_id);
$stmt->execute();
$total_cost = $stmt->get_result()->fetch_assoc()['total'] ?? 0;

// Tính lợi nhuận
$profit = $total_sell - $total_cost;
$profit_percent = $total_sell > 0 ? ($profit / $total_sell * 100) : 0;

$conn->close();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý Doanh thu - <?php echo htmlspecialchars($shipment['job_no']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="../dashboard.php"><i class="bi bi-box-seam"></i> Forwarder System</a>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="bi bi-currency-dollar"></i> Doanh thu bán ra (SELL) - Job: <?php echo htmlspecialchars($shipment['job_no']); ?></h2>
            <div>
                <a href="add.php?shipment_id=<?php echo $shipment_id; ?>" class="btn btn-success">
                    <i class="bi bi-plus-circle"></i> Thêm doanh thu
                </a>
                <a href="../shipments/view.php?id=<?php echo $shipment_id; ?>" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Quay lại
                </a>
            </div>
        </div>

        <div class="row mb-3">
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <h6>Thông tin lô hàng:</h6>
                        <p class="mb-1"><strong>Job No:</strong> <?php echo htmlspecialchars($shipment['job_no']); ?></p>
                        <p class="mb-1"><strong>Khách hàng:</strong> <?php echo htmlspecialchars($shipment['company_name']); ?></p>
                        <p class="mb-0"><strong>HAWB/MAWB:</strong> <?php echo htmlspecialchars($shipment['hawb'] . ' / ' . $shipment['mawb']); ?></p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-danger text-white">
                    <div class="card-body text-center">
                        <h6>Tổng Chi phí (COST)</h6>
                        <h3><?php echo number_format($total_cost, 0, ',', '.'); ?></h3>
                        <small>VND</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body text-center">
                        <h6>Tổng Doanh thu (SELL)</h6>
                        <h3><?php echo number_format($total_sell, 0, ',', '.'); ?></h3>
                        <small>VND</small>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card <?php echo $profit >= 0 ? 'bg-primary' : 'bg-warning'; ?> text-white">
                    <div class="card-body text-center">
                        <h6>Lợi nhuận</h6>
                        <h3><?php echo number_format($profit, 0, ',', '.'); ?></h3>
                        <small><?php echo number_format($profit_percent, 1); ?>% / <?php echo $profit >= 0 ? 'Lãi' : 'Lỗ'; ?></small>
                    </div>
                </div>
            </div>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php
                    if ($_GET['success'] == 'added') echo 'Thêm doanh thu thành công!';
                    if ($_GET['success'] == 'deleted') echo 'Xóa doanh thu thành công!';
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>STT</th>
                                <th>Mã CP</th>
                                <th>Nội dung</th>
                                <th>Số lượng</th>
                                <th>Đơn giá</th>
                                <th>VAT (%)</th>
                                <th>Thành tiền</th>
                                <th>Ghi chú</th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($sells->num_rows > 0): ?>
                                <?php $stt = 1; while ($row = $sells->fetch_assoc()): ?>
                                    <tr>
                                        <td><?php echo $stt++; ?></td>
                                        <td><strong class="text-success"><?php echo htmlspecialchars($row['code']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($row['description']); ?></td>
                                        <td><?php echo number_format($row['quantity'], 2); ?></td>
                                        <td><?php echo number_format($row['unit_price'], 0, ',', '.'); ?></td>
                                        <td><?php echo number_format($row['vat'], 1); ?>%</td>
                                        <td><strong><?php echo number_format($row['total_amount'], 0, ',', '.'); ?></strong></td>
                                        <td><small><?php echo htmlspecialchars($row['notes']); ?></small></td>
                                        <td>
                                            <a href="delete.php?id=<?php echo $row['id']; ?>&shipment_id=<?php echo $shipment_id; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Bạn có chắc muốn xóa?')">
                                                <i class="bi bi-trash"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                                <tr class="table-success">
                                    <td colspan="6" class="text-end"><strong>TỔNG DOANH THU:</strong></td>
                                    <td colspan="3"><strong><?php echo number_format($total_sell, 0, ',', '.'); ?> VND</strong></td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td colspan="9" class="text-center py-4">
                                        <i class="bi bi-inbox" style="font-size: 3rem; color: #ccc;"></i>
                                        <p class="text-muted mt-2">Chưa có doanh thu nào</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Bảng tổng hợp -->
        <div class="card mt-4 border-primary">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-calculator"></i> Tổng hợp chi phí & lợi nhuận</h5>
            </div>
            <div class="card-body">
                <table class="table table-bordered">
                    <tr>
                        <th width="50%">Tổng chi phí đầu vào (COST):</th>
                        <td class="text-end"><strong class="text-danger"><?php echo number_format($total_cost, 0, ',', '.'); ?> VND</strong></td>
                    </tr>
                    <tr>
                        <th>Tổng doanh thu bán ra (SELL):</th>
                        <td class="text-end"><strong class="text-success"><?php echo number_format($total_sell, 0, ',', '.'); ?> VND</strong></td>
                    </tr>
                    <tr class="table-primary">
                        <th>Lợi nhuận (SELL - COST):</th>
                        <td class="text-end">
                            <strong class="<?php echo $profit >= 0 ? 'text-primary' : 'text-warning'; ?>">
                                <?php echo number_format($profit, 0, ',', '.'); ?> VND 
                                (<?php echo number_format($profit_percent, 1); ?>%)
                            </strong>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>