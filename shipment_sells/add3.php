<?php
require_once '../config/database.php';
checkLogin();

$shipment_id = isset($_GET['shipment_id']) ? intval($_GET['shipment_id']) : 0;

if ($shipment_id == 0) {
    header("Location: ../shipments/index.php");
    exit();
}

$conn = getDBConnection();

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
        /* ✅ FIX: Chi hộ toggle dạng switch lớn, rõ ràng */
        .pob-toggle-wrap {
            border: 2px solid #fcd34d;
            border-radius: 10px;
            padding: 14px 18px;
            background: #fffbeb;
            transition: all .2s;
        }
        .pob-toggle-wrap.active {
            background: #fef3c7;
            border-color: #f59e0b;
            box-shadow: 0 0 0 3px rgba(245,158,11,.15);
        }
        /* Switch lớn */
        .pob-toggle-wrap .form-check-input {
            width: 3em;
            height: 1.5em;
            cursor: pointer;
            accent-color: #f59e0b;
            flex-shrink: 0;
        }
        .pob-toggle-wrap .form-check-label {
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            color: #92400e;
            padding-left: .5rem;
        }
        .trucking-hint { display: none; }
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

    <div class="container mt-4">
        <div class="row">
            <div class="col-md-9 offset-md-1">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-plus-circle"></i>
                            Thêm Doanh thu bán ra (SELL) —
                            <?php echo htmlspecialchars($shipment['job_no']); ?>
                        </h5>
                    </div>
                    <div class="card-body">

                        <?php if ($error): ?>
                            <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>

                        <form method="POST" id="sellForm">

                            <!-- MÃ CHI PHÍ + NỘI DUNG -->
                            <div class="row mb-3">
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">
                                        Mã chi phí <span class="text-danger">*</span>
                                    </label>
                                    <input type="text" id="costCode"
                                           class="form-control text-uppercase fw-bold"
                                           placeholder="VD: THC, TRUCKING"
                                           autocomplete="off" required>
                                    <input type="hidden" name="cost_code_id" id="costCodeId">
                                    <div id="codeStatus" class="form-text mt-1"></div>
                                </div>
                                <div class="col-md-8">
                                    <label class="form-label fw-bold">
                                        Nội dung <span class="text-muted fw-normal small">(Tự động)</span>
                                    </label>
                                    <input type="text" id="costDescription"
                                           class="form-control bg-light" readonly
                                           placeholder="← Nhập mã CP bên trái rồi nhấn Tab/Enter">
                                </div>
                            </div>

                            <!-- SL / ĐƠN GIÁ / VAT / THÀNH TIỀN -->
                            <div class="row mb-3">
                                <div class="col-md-3">
                                    <label class="form-label fw-bold">Số lượng</label>
                                    <input type="number" name="quantity" id="quantity"
                                           class="form-control" step="0.01" value="1" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label fw-bold">Đơn giá (VND)</label>
                                    <input type="number" name="unit_price" id="unitPrice"
                                           class="form-control" step="1" value="0" required>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label fw-bold">VAT (%)</label>
                                    <input type="number" name="vat" id="vat"
                                           class="form-control" step="0.1" value="8" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label fw-bold">
                                        Thành tiền <span class="text-muted fw-normal small">(Tự động)</span>
                                    </label>
                                    <input type="text" id="totalAmount"
                                           class="form-control bg-light fw-bold text-success fs-5"
                                           readonly value="0 VND">
                                </div>
                            </div>

                            <!-- ✅ CHI HỘ — DẠNG SWITCH NỔI BẬT -->
                            <div class="mb-3">
                                <label class="form-label fw-bold">
                                    <i class="bi bi-arrow-left-right text-warning"></i>
                                    Chi hộ (POB)
                                </label>
                                <div class="pob-toggle-wrap" id="pobWrap">
                                    <div class="form-check form-switch d-flex align-items-center gap-2 mb-0">
                                        <input class="form-check-input"
                                               type="checkbox"
                                               role="switch"
                                               name="is_pob"
                                               id="isPob"
                                               value="1"
                                               onchange="togglePob(this)">
                                        <label class="form-check-label" for="isPob">
                                            ⚠️ Đây là khoản CHI HỘ — không xuất hoá đơn VAT
                                        </label>
                                    </div>
                                    <div class="text-muted small mt-2">
                                        <i class="bi bi-info-circle"></i>
                                        Tích vào nếu công ty <strong>chi hộ</strong> khoản này cho khách
                                        (thuế, phí cảng...).
                                        Khoản chi hộ sẽ <strong class="text-danger">KHÔNG</strong>
                                        xuất hiện trên hoá đơn VAT.
                                    </div>
                                </div>
                            </div>

                            <!-- GHI CHÚ -->
                            <div class="mb-3">
                                <label class="form-label fw-bold" id="notesLabel">
                                    Ghi chú
                                </label>
                                <!-- ✅ Hint Trucking -->
                                <div id="truckingHint" class="alert alert-warning py-2 mb-2 trucking-hint">
                                    <i class="bi bi-truck"></i>
                                    <strong>Trucking:</strong> Bắt buộc ghi
                                    <strong>tuyến đường + biển số xe</strong> vào ô Ghi chú.<br>
                                    Thông tin này sẽ tự ghép vào tên dịch vụ trên hoá đơn VAT.<br>
                                    <span class="text-muted">
                                        VD: <em>Nội Bài - Hà Nội, 29E 25946</em>
                                        → HĐ sẽ in: <strong>Trucking Fee (Nội Bài - Hà Nội, 29E 25946)</strong>
                                    </span>
                                </div>
                                <textarea name="notes" id="noteField"
                                          class="form-control" rows="2"
                                          placeholder="Ghi chú thêm..."></textarea>
                            </div>

                            <hr>
                            <div class="d-flex justify-content-between">
                                <a href="manage.php?shipment_id=<?php echo $shipment_id; ?>"
                                   class="btn btn-secondary">
                                    <i class="bi bi-arrow-left"></i> Quay lại
                                </a>
                                <button type="submit" class="btn btn-success btn-lg px-5">
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
    // -------------------------------------------------------
    // Tìm mã chi phí (blur + Enter)
    // -------------------------------------------------------
    const costCodeEl  = document.getElementById('costCode');
    const codeStatus  = document.getElementById('codeStatus');

    function lookupCostCode() {
        const code = costCodeEl.value.trim().toUpperCase();
        if (!code) return;
        codeStatus.innerHTML = '<span class="text-muted"><i class="bi bi-hourglass-split"></i> Đang tìm...</span>';

        fetch('../api/get_cost_code.php?code=' + encodeURIComponent(code))
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('costCodeId').value      = data.id;
                    document.getElementById('costDescription').value = data.description;
                    codeStatus.innerHTML = '<span class="text-success"><i class="bi bi-check-circle-fill"></i> '
                                        + data.description + '</span>';
                    checkTruckingCode(code);
                } else {
                    document.getElementById('costCodeId').value      = '';
                    document.getElementById('costDescription').value = '';
                    codeStatus.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle-fill"></i> '
                                        + 'Không tìm thấy mã: ' + code + '</span>';
                    hideTruckingHint();
                }
            })
            .catch(() => {
                codeStatus.innerHTML = '<span class="text-danger">❌ Lỗi kết nối</span>';
            });
    }

    costCodeEl.addEventListener('blur',    lookupCostCode);
    costCodeEl.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); lookupCostCode(); } });

    // -------------------------------------------------------
    // Trucking hint
    // -------------------------------------------------------
    function checkTruckingCode(code) {
        const isTruck = code.includes('TRUCK');
        document.getElementById('truckingHint').style.display = isTruck ? 'block' : 'none';
        document.getElementById('notesLabel').innerHTML = isTruck
            ? 'Ghi chú <span class="badge bg-danger ms-1">Bắt buộc với Trucking</span>'
            : 'Ghi chú';
        if (isTruck) {
            document.getElementById('noteField').placeholder = 'VD: Nội Bài - Hà Nội, 29E 25946';
            setTimeout(() => document.getElementById('noteField').focus(), 100);
        }
    }

    function hideTruckingHint() {
        document.getElementById('truckingHint').style.display = 'none';
        document.getElementById('notesLabel').innerHTML = 'Ghi chú';
        document.getElementById('noteField').placeholder = 'Ghi chú thêm...';
    }

    // -------------------------------------------------------
    // ✅ Toggle Chi hộ — đổi màu box
    // -------------------------------------------------------
    function togglePob(cb) {
        const wrap = document.getElementById('pobWrap');
        if (cb.checked) {
            wrap.classList.add('active');
        } else {
            wrap.classList.remove('active');
        }
    }

    // -------------------------------------------------------
    // Tính thành tiền
    // -------------------------------------------------------
    function calculateTotal() {
        const qty   = parseFloat(document.getElementById('quantity').value)  || 0;
        const price = parseFloat(document.getElementById('unitPrice').value) || 0;
        const vat   = parseFloat(document.getElementById('vat').value)       || 0;
        const total = Math.round(qty * price * (1 + vat / 100));
        document.getElementById('totalAmount').value =
            new Intl.NumberFormat('vi-VN').format(total) + ' VND';
    }

    document.getElementById('quantity').addEventListener('input',  calculateTotal);
    document.getElementById('unitPrice').addEventListener('input', calculateTotal);
    document.getElementById('vat').addEventListener('input',       calculateTotal);

    // -------------------------------------------------------
    // Validate submit
    // -------------------------------------------------------
    document.getElementById('sellForm').addEventListener('submit', function(e) {
        if (!document.getElementById('costCodeId').value) {
            e.preventDefault();
            alert('⚠️ Vui lòng nhập mã chi phí hợp lệ!\nNhấn Tab hoặc Enter sau khi nhập mã.');
            costCodeEl.focus();
            return;
        }
        const code  = costCodeEl.value.toUpperCase();
        const notes = document.getElementById('noteField').value.trim();
        if (code.includes('TRUCK') && !notes) {
            e.preventDefault();
            alert('⚠️ Trucking bắt buộc nhập Ghi chú!\nVD: Nội Bài - Hà Nội, 29E 25946');
            document.getElementById('noteField').focus();
        }
    });
    </script>
</body>
</html>