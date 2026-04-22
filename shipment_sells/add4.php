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
$stmt = $conn->prepare("SELECT job_no FROM shipments WHERE id = ?");
$stmt->bind_param("i", $shipment_id);
$stmt->execute();
$shipment = $stmt->get_result()->fetch_assoc();

if (!$shipment) {
    header("Location: ../shipments/index.php");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $cost_code_id = intval($_POST['cost_code_id']);
    $quantity     = floatval($_POST['quantity']);
    $unit_price   = floatval($_POST['unit_price']);
    $vat          = floatval($_POST['vat']);
    $is_pob       = isset($_POST['is_pob']) ? 1 : 0;
    $notes        = trim($_POST['notes']);

    $total_amount = $quantity * $unit_price * (1 + $vat / 100);

    if ($cost_code_id == 0) {
        $error = 'Vui lòng chọn mã chi phí!';
    } else {
        $stmt = $conn->prepare("
            INSERT INTO shipment_sells
                (shipment_id, cost_code_id, quantity, unit_price, vat, is_pob, total_amount, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("iidddidsi",
            $shipment_id, $cost_code_id,
            $quantity, $unit_price, $vat,
            $is_pob,
            $total_amount, $notes,
            $_SESSION['user_id']
        );

        if ($stmt->execute()) {
            header("Location: manage.php?shipment_id=$shipment_id&success=added");
            exit();
        } else {
            $error = 'Có lỗi xảy ra: ' . $conn->error;
        }

        $stmt->close();
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Thêm Doanh thu - Forwarder System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .pob-check-wrap {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 10px 15px;
        }
        .pob-check-wrap label { cursor: pointer; font-weight: 600; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="../dashboard.php"><i class="bi bi-box-seam"></i> Forwarder System</a>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row">
            <div class="col-md-9 offset-md-1">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-plus-circle"></i>
                            Thêm Doanh thu bán ra (SELL) - Job:
                            <?php echo htmlspecialchars($shipment['job_no']); ?>
                        </h5>
                    </div>
                    <div class="card-body">

                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>

                        <form method="POST" id="sellForm">

                            <!-- MÃ CHI PHÍ + NỘI DUNG -->
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Mã chi phí <span class="text-danger">*</span></label>
                                    <input type="text" id="costCode" class="form-control text-uppercase"
                                           placeholder="VD: THC, TRUCKING" required>
                                    <input type="hidden" name="cost_code_id" id="costCodeId">
                                    <small class="text-muted">Nhập mã để tự động điền nội dung</small>
                                </div>
                                <div class="col-md-8 mb-3">
                                    <label class="form-label">Nội dung <span class="text-muted small">(Tự động)</span></label>
                                    <input type="text" id="costDescription" class="form-control bg-light" readonly>
                                </div>
                            </div>

                            <!-- SL / ĐƠN GIÁ / VAT / THÀNH TIỀN -->
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Số lượng</label>
                                    <input type="number" name="quantity" id="quantity"
                                           class="form-control" step="0.01" value="1" required>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label class="form-label">Đơn giá (VND)</label>
                                    <input type="number" name="unit_price" id="unitPrice"
                                           class="form-control" step="0.01" value="0" required>
                                </div>
                                <div class="col-md-2 mb-3">
                                    <label class="form-label">VAT (%)</label>
                                    <input type="number" name="vat" id="vat"
                                           class="form-control" step="0.1" value="0" required>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label class="form-label">Thành tiền <span class="text-muted small">(Tự động)</span></label>
                                    <input type="text" id="totalAmount"
                                           class="form-control bg-light fw-bold text-success" readonly value="0 VND">
                                </div>
                            </div>

                            <!-- ✅ CHI HỘ (POB) -->
                            <div class="mb-3">
                                <div class="pob-check-wrap">
                                    <div class="form-check form-check-inline mb-0">
                                        <input class="form-check-input" type="checkbox"
                                               name="is_pob" id="isPob" value="1"
                                               onchange="togglePobNote()">
                                        <label class="form-check-label" for="isPob">
                                            <i class="bi bi-arrow-left-right text-warning"></i>
                                            Chi hộ (POB / B2B)
                                        </label>
                                    </div>
                                    <small class="text-muted d-block mt-1">
                                        <i class="bi bi-info-circle"></i>
                                        Tích vào nếu đây là khoản <strong>chi hộ</strong> —
                                        sẽ <strong class="text-danger">không xuất hoá đơn VAT</strong> cho khoản này.
                                    </small>
                                </div>
                            </div>

                            <!-- GHI CHÚ -->
                            <div class="mb-3">
                                <label class="form-label fw-bold">
                                    Ghi chú
                                    <span id="noteHint" class="text-muted fw-normal small" style="display:none;">
                                        — <span class="text-danger">Trucking cần ghi: tuyến đường + biển số xe</span>,
                                        VD: <em>Nội Bài - Hà Nội, 29E 25946</em>
                                    </span>
                                </label>
                                <textarea name="notes" id="noteField" class="form-control" rows="2"
                                          placeholder="Ghi chú thêm..."></textarea>
                                <div id="truckingHint" class="alert alert-warning py-1 mt-1 small" style="display:none;">
                                    <i class="bi bi-truck"></i>
                                    <strong>Trucking:</strong> Ghi chú phải ghi đầy đủ:
                                    <strong>tuyến đường + biển số xe</strong>.
                                    VD: <em>Nội Bài - Hà Nội, 29E 25946</em>
                                    — Thông tin này sẽ xuất hiện trên hoá đơn VAT.
                                </div>
                            </div>

                            <div class="d-flex justify-content-between">
                                <a href="manage.php?shipment_id=<?php echo $shipment_id; ?>"
                                   class="btn btn-secondary">
                                    <i class="bi bi-arrow-left"></i> Quay lại
                                </a>
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-save"></i> Lưu doanh thu
                                </button>
                            </div>

                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Auto-fill mã chi phí
    document.getElementById('costCode').addEventListener('blur', function () {
        const code = this.value.trim().toUpperCase();
        if (!code) return;
        fetch('../api/get_cost_code.php?code=' + encodeURIComponent(code))
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('costCodeId').value      = data.id;
                    document.getElementById('costDescription').value = data.description;
                    checkTruckingCode(code);
                } else {
                    alert('Không tìm thấy mã chi phí: ' + code);
                    this.value = '';
                    document.getElementById('costCodeId').value      = '';
                    document.getElementById('costDescription').value = '';
                    hideTruckingHint();
                }
            });
    });

    document.getElementById('costCode').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); this.blur(); }
    });

    function checkTruckingCode(code) {
        const isTrucking = code.toUpperCase().includes('TRUCK');
        document.getElementById('truckingHint').style.display = isTrucking ? 'block' : 'none';
        document.getElementById('noteHint').style.display     = isTrucking ? 'inline' : 'none';
        if (isTrucking) {
            document.getElementById('noteField').placeholder =
                'VD: Nội Bài - Hà Nội, 29E 25946';
            document.getElementById('noteField').focus();
        }
    }
    function hideTruckingHint() {
        document.getElementById('truckingHint').style.display = 'none';
        document.getElementById('noteHint').style.display     = 'none';
    }

    function togglePobNote() {
        const checked = document.getElementById('isPob').checked;
        const vatInput = document.getElementById('vat');
        if (checked) {
            vatInput.setAttribute('title', 'Khoản chi hộ thường VAT = 0%');
        }
    }

    function calculateTotal() {
        const qty   = parseFloat(document.getElementById('quantity').value)  || 0;
        const price = parseFloat(document.getElementById('unitPrice').value) || 0;
        const vat   = parseFloat(document.getElementById('vat').value)       || 0;
        const total = qty * price * (1 + vat / 100);
        document.getElementById('totalAmount').value =
            total.toLocaleString('vi-VN') + ' VND';
    }

    document.getElementById('quantity').addEventListener('input',  calculateTotal);
    document.getElementById('unitPrice').addEventListener('input', calculateTotal);
    document.getElementById('vat').addEventListener('input',       calculateTotal);

    document.getElementById('sellForm').addEventListener('submit', function (e) {
        if (!document.getElementById('costCodeId').value) {
            e.preventDefault();
            alert('Vui lòng chọn mã chi phí hợp lệ!');
            document.getElementById('costCode').focus();
            return;
        }
        const code  = document.getElementById('costCode').value.toUpperCase();
        const notes = document.getElementById('noteField').value.trim();
        if (code.includes('TRUCK') && !notes) {
            e.preventDefault();
            alert('⚠️ Trucking cần ghi chú tuyến đường + biển số xe!\nVD: Nội Bài - Hà Nội, 29E 25946');
            document.getElementById('noteField').focus();
        }
    });
    </script>
</body>
</html>