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
$stmt = $conn->prepare("SELECT s.*, c.company_name FROM shipments s
                         LEFT JOIN customers c ON s.customer_id = c.id
                         WHERE s.id = ?");
$stmt->bind_param("i", $shipment_id);
$stmt->execute();
$shipment = $stmt->get_result()->fetch_assoc();

if (!$shipment) {
    header("Location: ../shipments/index.php");
    exit();
}

// Lấy danh sách sell — ✅ thêm is_pob
$stmt = $conn->prepare("SELECT ss.*, cc.code, cc.description
                         FROM shipment_sells ss
                         JOIN cost_codes cc ON ss.cost_code_id = cc.id
                         WHERE ss.shipment_id = ?
                         ORDER BY ss.id ASC");
$stmt->bind_param("i", $shipment_id);
$stmt->execute();
$sells = $stmt->get_result();

// Tổng sell / cost
$total_sell = $conn->query("SELECT COALESCE(SUM(total_amount),0) t FROM shipment_sells WHERE shipment_id=$shipment_id")->fetch_assoc()['t'];
$total_cost = $conn->query("SELECT COALESCE(SUM(total_amount),0) t FROM shipment_costs WHERE shipment_id=$shipment_id")->fetch_assoc()['t'];

// ✅ Tổng chi hộ (POB) — không tính vào HĐ VAT
$total_pob  = $conn->query("SELECT COALESCE(SUM(total_amount),0) t FROM shipment_sells WHERE shipment_id=$shipment_id AND is_pob=1")->fetch_assoc()['t'];
// Tổng sẽ xuất HĐ VAT = total_sell - total_pob
$total_vat_invoice = $total_sell - $total_pob;

$profit         = $total_sell - $total_cost;
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
    <style>
        .table tbody td { vertical-align: middle; font-size: .87rem; }
        /* ✅ Dòng chi hộ làm nhạt màu để phân biệt */
        .row-pob {
            background: #fffbeb !important;
            opacity: .88;
        }
        .pob-badge {
            font-size: .7rem;
            padding: 2px 7px;
            border-radius: 10px;
            background: #fef3c7;
            color: #92400e;
            border: 1px solid #fcd34d;
            white-space: nowrap;
        }
        .trucking-note {
            font-size: .78rem;
            color: #6b7280;
            font-style: italic;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="../dashboard.php">
                <i class="bi bi-box-seam"></i> Forwarder System
            </a>
        </div>
    </nav>

    <div class="container-fluid mt-4">

        <!-- HEADER -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h5 class="mb-0">
                <i class="bi bi-currency-dollar text-success"></i>
                Doanh thu bán ra (SELL) —
                <strong class="text-primary"><?php echo htmlspecialchars($shipment['job_no']); ?></strong>
            </h5>
            <div class="d-flex gap-2">
                <a href="add.php?shipment_id=<?php echo $shipment_id; ?>"
                   class="btn btn-success btn-sm">
                    <i class="bi bi-plus-circle"></i> Thêm doanh thu
                </a>
                <a href="../shipments/view.php?id=<?php echo $shipment_id; ?>"
                   class="btn btn-secondary btn-sm">
                    <i class="bi bi-arrow-left"></i> Quay lại
                </a>
            </div>
        </div>

        <!-- CARDS TỔNG HỢP -->
        <div class="row g-2 mb-3">
            <div class="col-6 col-md-2">
                <div class="card bg-danger text-white text-center p-2 shadow-sm">
                    <small><i class="bi bi-cash-stack"></i> Tổng COST</small>
                    <strong><?php echo number_format($total_cost, 0, ',', '.'); ?></strong>
                    <small>VND</small>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card bg-success text-white text-center p-2 shadow-sm">
                    <small><i class="bi bi-currency-dollar"></i> Tổng SELL</small>
                    <strong><?php echo number_format($total_sell, 0, ',', '.'); ?></strong>
                    <small>VND</small>
                </div>
            </div>
            <div class="col-6 col-md-2">
                <div class="card <?php echo $profit >= 0 ? 'bg-primary' : 'bg-warning'; ?> text-white text-center p-2 shadow-sm">
                    <small><i class="bi bi-graph-up"></i> Lợi nhuận</small>
                    <strong><?php echo ($profit >= 0 ? '+' : '') . number_format($profit, 0, ',', '.'); ?></strong>
                    <small><?php echo number_format($profit_percent, 1); ?>%</small>
                </div>
            </div>
            <!-- ✅ Card chi hộ -->
            <div class="col-6 col-md-2">
                <div class="card text-center p-2 shadow-sm" style="background:#fef9c3;border:1px solid #fcd34d;">
                    <small class="text-amber-800">
                        <i class="bi bi-arrow-left-right text-warning"></i> Chi hộ (POB)
                    </small>
                    <strong class="text-warning"><?php echo number_format($total_pob, 0, ',', '.'); ?></strong>
                    <small class="text-muted">VND — Không xuất HĐ VAT</small>
                </div>
            </div>
            <!-- ✅ Card tổng xuất HĐ VAT -->
            <div class="col-6 col-md-2">
                <div class="card text-center p-2 shadow-sm" style="background:#dcfce7;border:1px solid #86efac;">
                    <small class="text-green-800">
                        <i class="bi bi-receipt-cutoff text-success"></i> Xuất HĐ VAT
                    </small>
                    <strong class="text-success"><?php echo number_format($total_vat_invoice, 0, ',', '.'); ?></strong>
                    <small class="text-muted">VND</small>
                </div>
            </div>
        </div>

        <!-- THÔNG BÁO -->
        <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show py-2">
            <i class="bi bi-check-circle"></i>
            <?php
                if ($_GET['success'] == 'added')   echo 'Thêm doanh thu thành công!';
                if ($_GET['success'] == 'deleted') echo 'Xóa doanh thu thành công!';
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- BẢNG SELL -->
        <div class="card shadow-sm">
            <div class="card-header bg-success text-white py-2">
                <i class="bi bi-table"></i> Danh sách doanh thu
                <span class="badge bg-light text-dark ms-2"><?php echo $sells->num_rows; ?> dòng</span>
                <?php if ($total_pob > 0): ?>
                    <span class="badge ms-1" style="background:#fcd34d;color:#92400e;">
                        <i class="bi bi-arrow-left-right"></i>
                        Có <?php
                            // đếm số dòng is_pob — dùng lại từ $sells sau khi data_seek
                            $conn2 = getDBConnection();
                            $r = $conn2->query("SELECT COUNT(*) c FROM shipment_sells WHERE shipment_id=$shipment_id AND is_pob=1");
                            echo $r->fetch_assoc()['c'];
                            $conn2->close();
                        ?> khoản chi hộ
                    </span>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover table-bordered mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th style="width:35px">#</th>
                                <th>Mã CP</th>
                                <th>Nội dung</th>
                                <th class="text-center">SL</th>
                                <th class="text-end">Đơn giá</th>
                                <th class="text-center">VAT%</th>
                                <th class="text-end">Thành tiền</th>
                                <!-- ✅ CỘT CHI HỘ -->
                                <th class="text-center" style="width:80px"
                                    title="Chi hộ = không xuất hoá đơn VAT">
                                    <i class="bi bi-arrow-left-right"></i> Chi hộ
                                </th>
                                <th>Ghi chú</th>
                                <th style="width:60px">Xóa</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($sells->num_rows > 0):
                                $stt        = 1;
                                $sum_normal = 0;
                                $sum_pob    = 0;
                                while ($row = $sells->fetch_assoc()):
                                    $is_pob = intval($row['is_pob'] ?? 0);
                                    if ($is_pob) $sum_pob    += $row['total_amount'];
                                    else         $sum_normal += $row['total_amount'];
                                    $is_trucking = stripos($row['code'], 'TRUCK') !== false;
                            ?>
                            <tr class="<?php echo $is_pob ? 'row-pob' : ''; ?>">
                                <td class="text-center text-muted"><?php echo $stt++; ?></td>
                                <td>
                                    <span class="badge bg-success">
                                        <?php echo htmlspecialchars($row['code']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($row['description']); ?>
                                    <?php if ($is_trucking && !empty($row['notes'])): ?>
                                        <br>
                                        <span class="trucking-note">
                                            <i class="bi bi-truck"></i>
                                            <?php echo htmlspecialchars($row['notes']); ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?php echo number_format($row['quantity'], 2); ?></td>
                                <td class="text-end"><?php echo number_format($row['unit_price'], 0, ',', '.'); ?></td>
                                <td class="text-center"><?php echo number_format($row['vat'], 1); ?>%</td>
                                <td class="text-end fw-bold text-success">
                                    <?php echo number_format($row['total_amount'], 0, ',', '.'); ?>
                                </td>

                                <!-- ✅ CỘT CHI HỘ -->
                                <td class="text-center">
                                    <?php if ($is_pob): ?>
                                        <span class="pob-badge">
                                            <i class="bi bi-check-circle-fill text-warning"></i> Chi hộ
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <small class="text-muted">
                                        <?php
                                        // Trucking: ghi chú đã hiển thị dưới description
                                        // Các loại khác: hiển thị ở đây
                                        if (!$is_trucking) {
                                            echo htmlspecialchars($row['notes'] ?? '');
                                        }
                                        ?>
                                    </small>
                                </td>
                                <td>
                                    <a href="delete.php?id=<?php echo $row['id']; ?>&shipment_id=<?php echo $shipment_id; ?>"
                                       class="btn btn-sm btn-danger"
                                       onclick="return confirm('Xóa dòng này?')">
                                        <i class="bi bi-trash"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endwhile; ?>

                            <!-- Dòng TỔNG -->
                            <tr class="table-success fw-bold">
                                <td colspan="6" class="text-end">TỔNG SELL:</td>
                                <td class="text-end text-success">
                                    <?php echo number_format($total_sell, 0, ',', '.'); ?>
                                </td>
                                <td colspan="3"></td>
                            </tr>

                            <?php if ($total_pob > 0): ?>
                            <!-- ✅ Dòng tổng chi hộ -->
                            <tr style="background:#fffbeb;">
                                <td colspan="6" class="text-end text-muted">
                                    <i class="bi bi-arrow-left-right"></i>
                                    Trong đó Chi hộ (không xuất HĐ VAT):
                                </td>
                                <td class="text-end" style="color:#92400e;">
                                    <?php echo number_format($total_pob, 0, ',', '.'); ?>
                                </td>
                                <td colspan="3"></td>
                            </tr>
                            <!-- ✅ Dòng tổng xuất HĐ VAT -->
                            <tr style="background:#dcfce7;">
                                <td colspan="6" class="text-end fw-bold">
                                    <i class="bi bi-receipt-cutoff text-success"></i>
                                    Tổng xuất Hoá đơn VAT:
                                </td>
                                <td class="text-end fw-bold text-success">
                                    <?php echo number_format($total_vat_invoice, 0, ',', '.'); ?>
                                </td>
                                <td colspan="3"></td>
                            </tr>
                            <?php endif; ?>

                            <?php else: ?>
                            <tr>
                                <td colspan="10" class="text-center py-4 text-muted">
                                    <i class="bi bi-inbox" style="font-size:2.5rem;color:#ccc;"></i>
                                    <p class="mt-2">Chưa có doanh thu nào</p>
                                    <a href="add.php?shipment_id=<?php echo $shipment_id; ?>"
                                       class="btn btn-sm btn-success">
                                        <i class="bi bi-plus-circle"></i> Thêm ngay
                                    </a>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- BẢNG TỔNG HỢP -->
        <div class="card mt-3 border-primary shadow-sm">
            <div class="card-header bg-primary text-white py-2">
                <i class="bi bi-calculator"></i> Tổng hợp chi phí & lợi nhuận
            </div>
            <div class="card-body p-0">
                <table class="table table-bordered mb-0">
                    <tr>
                        <td width="55%"><i class="bi bi-cash-stack text-danger"></i> Tổng chi phí đầu vào (COST):</td>
                        <td class="text-end fw-bold text-danger">
                            <?php echo number_format($total_cost, 0, ',', '.'); ?> VND
                        </td>
                    </tr>
                    <tr>
                        <td><i class="bi bi-currency-dollar text-success"></i> Tổng doanh thu (SELL):</td>
                        <td class="text-end fw-bold text-success">
                            <?php echo number_format($total_sell, 0, ',', '.'); ?> VND
                        </td>
                    </tr>
                    <?php if ($total_pob > 0): ?>
                    <tr style="background:#fffbeb;">
                        <td class="text-muted">
                            <i class="bi bi-arrow-left-right text-warning"></i>
                            Trong đó Chi hộ (POB):
                        </td>
                        <td class="text-end" style="color:#92400e;">
                            <?php echo number_format($total_pob, 0, ',', '.'); ?> VND
                        </td>
                    </tr>
                    <tr style="background:#dcfce7;">
                        <td>
                            <i class="bi bi-receipt-cutoff text-success"></i>
                            Tổng xuất Hoá đơn VAT (SELL - Chi hộ):
                        </td>
                        <td class="text-end fw-bold text-success">
                            <?php echo number_format($total_vat_invoice, 0, ',', '.'); ?> VND
                        </td>
                    </tr>
                    <?php endif; ?>
                    <tr class="<?php echo $profit >= 0 ? 'table-success' : 'table-danger'; ?>">
                        <td><i class="bi bi-graph-up"></i> Lợi nhuận (SELL - COST):</td>
                        <td class="text-end fw-bold <?php echo $profit >= 0 ? 'text-success' : 'text-danger'; ?>">
                            <?php echo ($profit >= 0 ? '+' : '') . number_format($profit, 0, ',', '.'); ?> VND
                            <span class="badge <?php echo $profit >= 0 ? 'bg-success' : 'bg-danger'; ?> ms-1">
                                <?php echo number_format($profit_percent, 1); ?>%
                            </span>
                            <?php echo $profit >= 0 ? '✅' : '❌'; ?>
                        </td>
                    </tr>
                </table>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>