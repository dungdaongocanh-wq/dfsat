<?php
require_once '../config/database.php';
checkLogin();

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id == 0) {
    header("Location: index.php");
    exit();
}

$conn = getDBConnection();

$stmt = $conn->prepare("SELECT * FROM shipments WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$shipment = $stmt->get_result()->fetch_assoc();

if (!$shipment) {
    header("Location: index.php");
    exit();
}

if ($shipment['is_locked'] == 'yes' && $_SESSION['role'] != 'admin') {
    header("Location: index.php?error=shipment_locked");
    exit();
}

$suppliers  = $conn->query("SELECT id, supplier_name, short_name FROM suppliers WHERE status='active' ORDER BY short_name");
$cost_codes = $conn->query("SELECT id, code, description FROM cost_codes WHERE status='active' ORDER BY code");

$stmt_cur_sup = $conn->prepare("SELECT supplier_id FROM shipment_suppliers WHERE shipment_id = ?");
$stmt_cur_sup->bind_param("i", $id);
$stmt_cur_sup->execute();
$cur_sup_result  = $stmt_cur_sup->get_result();
$current_sup_ids = [];
while ($r = $cur_sup_result->fetch_assoc()) {
    $current_sup_ids[] = $r['supplier_id'];
}

$stmt_costs = $conn->prepare("SELECT sc.*, cc.code, cc.description, s.short_name AS sup_short
                               FROM shipment_costs sc
                               JOIN cost_codes cc ON sc.cost_code_id = cc.id
                               LEFT JOIN suppliers s ON sc.supplier_id = s.id
                               WHERE sc.shipment_id = ? ORDER BY sc.id");
$stmt_costs->bind_param("i", $id);
$stmt_costs->execute();
$existing_costs = $stmt_costs->get_result()->fetch_all(MYSQLI_ASSOC);

$stmt_sells = $conn->prepare("SELECT ss.*, cc.code, cc.description
                               FROM shipment_sells ss
                               JOIN cost_codes cc ON ss.cost_code_id = cc.id
                               WHERE ss.shipment_id = ? ORDER BY ss.id");
$stmt_sells->bind_param("i", $id);
$stmt_sells->execute();
$existing_sells = $stmt_sells->get_result()->fetch_all(MYSQLI_ASSOC);

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if ($shipment['is_locked'] == 'yes' && $_SESSION['role'] != 'admin') {
        $error = 'Lô hàng đã khóa, bạn không có quyền sửa!';
    } else {
        $customer_id            = intval($_POST['customer_id']);
        $mawb                   = trim($_POST['mawb']);
        $hawb                   = trim($_POST['hawb']);
        $customs_declaration_no = trim($_POST['customs_declaration_no']);
        $shipper                = trim($_POST['shipper']);
        $cnee                   = trim($_POST['cnee']);
        $vessel_flight          = trim($_POST['vessel_flight']);
        $pol                    = strtoupper(trim($_POST['pol']));
        $pod                    = strtoupper(trim($_POST['pod']));
        $packages               = intval($_POST['packages']);
        $gw                     = floatval($_POST['gw']);
        $cw                     = floatval($_POST['cw']);
        $warehouse              = trim($_POST['warehouse']);
        $cont_seal              = trim($_POST['cont_seal']);
        $arrival_date           = $_POST['arrival_date'];
        $status                 = $_POST['status'];
        $notes                  = trim($_POST['notes']);
        $supplier_ids           = isset($_POST['supplier_ids']) ? $_POST['supplier_ids'] : [];
        $costs                  = isset($_POST['costs'])        ? $_POST['costs']        : [];
        $sells                  = isset($_POST['sells'])        ? $_POST['sells']        : [];
        $delete_costs           = isset($_POST['delete_costs']) ? $_POST['delete_costs'] : [];
        $delete_sells           = isset($_POST['delete_sells']) ? $_POST['delete_sells'] : [];

        if ($customer_id == 0) {
            $error = 'Vui lòng chọn Khách hàng!';
        } elseif (empty($hawb)) {
            $error = 'HAWB là bắt buộc!';
        } elseif (empty($mawb)) {
            $error = 'MAWB là bắt buộc!';
        } else {
            $conn->begin_transaction();
            try {
                // ── UPDATE shipment ──────────────────────────────────────
                // 18 ? : i s s s s s s s s i d d s s s s s i
                // customer_id(i) mawb(s) hawb(s) cd_no(s)
                // shipper(s) cnee(s) vessel(s) pol(s) pod(s)
                // packages(i) gw(d) cw(d) warehouse(s) cont_seal(s)
                // arrival_date(s) status(s) notes(s) id(i)
                $stmt_upd = $conn->prepare("UPDATE shipments SET
                    customer_id=?, mawb=?, hawb=?, customs_declaration_no=?,
                    shipper=?, cnee=?, vessel_flight=?, pol=?, pod=?,
                    packages=?, gw=?, cw=?, warehouse=?, cont_seal=?,
                    arrival_date=?, status=?, notes=?
                    WHERE id=?");
                $stmt_upd->bind_param(
                    "issssssssiddsssssi",
                    $customer_id, $mawb, $hawb, $customs_declaration_no,
                    $shipper, $cnee, $vessel_flight, $pol, $pod,
                    $packages, $gw, $cw, $warehouse, $cont_seal,
                    $arrival_date, $status, $notes, $id
                );
                $stmt_upd->execute();

                // ── Suppliers ────────────────────────────────────────────
                $conn->query("DELETE FROM shipment_suppliers WHERE shipment_id = $id");
                if (!empty($supplier_ids)) {
                    $stmt_sup = $conn->prepare("INSERT INTO shipment_suppliers (shipment_id, supplier_id) VALUES (?, ?)");
                    foreach ($supplier_ids as $sid) {
                        $sid = intval($sid);
                        $stmt_sup->bind_param("ii", $id, $sid);
                        $stmt_sup->execute();
                    }
                }

                // ── Delete costs ─────────────────────────────────────────
                if (!empty($delete_costs)) {
                    $ph       = implode(',', array_fill(0, count($delete_costs), '?'));
                    $stmt_del = $conn->prepare("DELETE FROM shipment_costs WHERE id IN ($ph) AND shipment_id = ?");
                    $types    = str_repeat('i', count($delete_costs)) . 'i';
                    $params   = array_merge(array_map('intval', $delete_costs), [$id]);
                    $stmt_del->bind_param($types, ...$params);
                    $stmt_del->execute();
                }

                // ── Delete sells ─────────────────────────────────────────
                if (!empty($delete_sells)) {
                    $ph       = implode(',', array_fill(0, count($delete_sells), '?'));
                    $stmt_del = $conn->prepare("DELETE FROM shipment_sells WHERE id IN ($ph) AND shipment_id = ?");
                    $types    = str_repeat('i', count($delete_sells)) . 'i';
                    $params   = array_merge(array_map('intval', $delete_sells), [$id]);
                    $stmt_del->bind_param($types, ...$params);
                    $stmt_del->execute();
                }

                // ── Costs: UPDATE hoặc INSERT ────────────────────────────
                foreach ($costs as $cost) {
                    if (empty($cost['cost_code_id'])) continue;
                    $cc_id   = intval($cost['cost_code_id']);
                    $qty     = floatval($cost['quantity']);
                    $price   = floatval($cost['unit_price']);
                    $vat     = floatval($cost['vat']);
                    $total   = $qty * $price * (1 + $vat / 100);
                    $sup_id  = !empty($cost['supplier_id']) ? intval($cost['supplier_id']) : null;
                    $note_c  = trim($cost['notes'] ?? '');
                    $user_id = intval($_SESSION['user_id']);

                    if (!empty($cost['id'])) {
                        // UPDATE: 9 ?
                        // cc_id(i) qty(d) price(d) vat(d) total(d) sup_id(i) note_c(s) cost_id(i) id(i)
                        $cost_id = intval($cost['id']);
                        $stmt_uc = $conn->prepare("UPDATE shipment_costs SET
                            cost_code_id=?, quantity=?, unit_price=?, vat=?, total_amount=?,
                            supplier_id=?, notes=?
                            WHERE id=? AND shipment_id=?");
                        $stmt_uc->bind_param("iddddisii",
                            $cc_id, $qty, $price, $vat, $total,
                            $sup_id, $note_c,
                            $cost_id, $id);
                        $stmt_uc->execute();
                    } else {
                        // INSERT: 9 ?
                        // id(i) cc_id(i) qty(d) price(d) vat(d) total(d) sup_id(i) note_c(s) user_id(i)
                        $stmt_ic = $conn->prepare("INSERT INTO shipment_costs
                            (shipment_id, cost_code_id, quantity, unit_price, vat, total_amount, supplier_id, notes, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt_ic->bind_param("iiddddisi",
                            $id, $cc_id, $qty, $price, $vat, $total,
                            $sup_id, $note_c, $user_id);
                        $stmt_ic->execute();
                    }
                }

                // ── Sells: UPDATE hoặc INSERT ────────────────────────────
                foreach ($sells as $sell) {
                    if (empty($sell['cost_code_id'])) continue;
                    $cc_id   = intval($sell['cost_code_id']);
                    $qty     = floatval($sell['quantity']);
                    $price   = floatval($sell['unit_price']);
                    $vat     = floatval($sell['vat']);
                    $total   = $qty * $price * (1 + $vat / 100);
                    $is_pob  = isset($sell['is_pob']) ? 1 : 0;
                    $note_s  = trim($sell['notes'] ?? '');
                    $user_id = intval($_SESSION['user_id']);

                    if (!empty($sell['id'])) {
                        // UPDATE: 9 ?
                        // cc_id(i) qty(d) price(d) vat(d) total(d) is_pob(i) note_s(s) sell_id(i) id(i)
                        $sell_id = intval($sell['id']);
                        $stmt_us = $conn->prepare("UPDATE shipment_sells SET
                            cost_code_id=?, quantity=?, unit_price=?, vat=?, total_amount=?,
                            is_pob=?, notes=?
                            WHERE id=? AND shipment_id=?");
                        $stmt_us->bind_param("iddddisii",
                            $cc_id, $qty, $price, $vat, $total,
                            $is_pob, $note_s,
                            $sell_id, $id);
                        $stmt_us->execute();
                    } else {
                        // INSERT: 9 ?
                        // id(i) cc_id(i) qty(d) price(d) vat(d) total(d) is_pob(i) note_s(s) user_id(i)
                        $stmt_is = $conn->prepare("INSERT INTO shipment_sells
                            (shipment_id, cost_code_id, quantity, unit_price, vat, total_amount, is_pob, notes, created_by)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt_is->bind_param("iiddddisi",
                            $id, $cc_id, $qty, $price, $vat, $total,
                            $is_pob, $note_s, $user_id);
                        $stmt_is->execute();
                    }
                }

                $conn->commit();
                header("Location: view.php?id=$id&success=updated");
                exit();

            } catch (Exception $e) {
                $conn->rollback();
                $error = 'Có lỗi xảy ra: ' . $e->getMessage();
            }
        }
    }
} else {
    $_POST = $shipment;
    if ($shipment['customer_id']) {
        $stmt_cus = $conn->prepare("SELECT short_name, company_name FROM customers WHERE id = ?");
        $stmt_cus->bind_param("i", $shipment['customer_id']);
        $stmt_cus->execute();
        $cus_info = $stmt_cus->get_result()->fetch_assoc();
        $_POST['customer_short'] = $cus_info['short_name'] ?? '';
        $_POST['customer_name']  = $cus_info['company_name'] ?? '';
    }
}

$cost_codes_json = [];
$cost_codes->data_seek(0);
while ($cc = $cost_codes->fetch_assoc()) {
    $cost_codes_json[$cc['id']] = ['code' => $cc['code'], 'description' => $cc['description']];
}

$suppliers_json = [];
$suppliers->data_seek(0);
while ($s = $suppliers->fetch_assoc()) {
    $suppliers_json[$s['id']] = ['short_name' => $s['short_name'], 'supplier_name' => $s['supplier_name']];
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sửa Lô hàng - <?php echo htmlspecialchars($shipment['job_no']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        .section-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 8px 15px;
            border-radius: 5px;
            margin-bottom: 15px;
            margin-top: 20px;
        }
        .section-header.blue   { background: linear-gradient(135deg, #2193b0, #6dd5ed); }
        .section-header.green  { background: linear-gradient(135deg, #11998e, #38ef7d); color: #333; }
        .section-header.orange { background: linear-gradient(135deg, #f7971e, #ffd200); color: #333; }
        .section-header.red    { background: linear-gradient(135deg, #eb3349, #f45c43); }
        .cost-row, .sell-row {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 10px;
            margin-bottom: 8px;
            transition: border-color .2s, background .2s;
        }
        .cost-row:hover, .sell-row:hover { border-color: #adb5bd; }
        .deleted-row {
            background: #f8d7da !important;
            opacity: .6;
            pointer-events: none;
        }
        .deleted-row * { text-decoration: line-through; }
        .sell-row.is-pob {
            background: #fffbeb !important;
            border-color: #fcd34d !important;
        }
        .pob-check-wrap {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 6px;
            padding: 4px 10px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
        }
        .trucking-hint {
            display: none;
            font-size: .8rem;
            color: #92400e;
            background: #fef3c7;
            border: 1px solid #fcd34d;
            border-radius: 4px;
            padding: 3px 7px;
            margin-top: 3px;
        }
    </style>
</head>
<body class="bg-light">

    <nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="../dashboard.php"><i class="bi bi-box-seam"></i> Forwarder System</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item"><a class="nav-link" href="../dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="../customers/index.php">Khách hàng</a></li>
                    <li class="nav-item"><a class="nav-link active" href="index.php">Lô hàng</a></li>
                    <li class="nav-item"><a class="nav-link" href="../suppliers/index.php">Nhà cung cấp</a></li>
                    <?php if ($_SESSION['role'] == 'admin'): ?>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">Quản trị</a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="../accounts/index.php">Tài khoản</a></li>
                            <li><a class="dropdown-item" href="../cost_codes/index.php">Mã chi phí</a></li>
                        </ul>
                    </li>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
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

    <div class="container-fluid mt-3 pb-5">
        <div class="card shadow-sm">
            <div class="card-header <?php echo $shipment['is_locked'] == 'yes' ? 'bg-danger' : 'bg-warning text-dark'; ?> py-2">
                <h5 class="mb-0">
                    <i class="bi bi-pencil"></i> Sửa Lô hàng:
                    <strong><?php echo htmlspecialchars($shipment['job_no']); ?></strong>
                    <?php if ($shipment['is_locked'] == 'yes'): ?>
                        <span class="badge bg-dark ms-2"><i class="bi bi-lock-fill"></i> ĐÃ KHÓA - ADMIN MODE</span>
                    <?php endif; ?>
                </h5>
            </div>
            <div class="card-body">

                <?php if ($shipment['is_locked'] == 'yes'): ?>
                <div class="alert alert-danger py-2">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <strong>CẢNH BÁO!</strong> Lô hàng đã khóa. Bạn đang sửa với quyền Admin.
                </div>
                <?php endif; ?>

                <?php if ($error): ?>
                <div class="alert alert-danger py-2">
                    <i class="bi bi-exclamation-triangle"></i> <?php echo $error; ?>
                </div>
                <?php endif; ?>

                <form method="POST" id="editForm">

                    <div class="section-header blue">
                        <i class="bi bi-info-circle"></i> Thông tin cơ bản
                    </div>
                    <div class="row">
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Job No</label>
                            <input type="text" class="form-control bg-light fw-bold text-primary"
                                   value="<?php echo htmlspecialchars($shipment['job_no']); ?>" readonly>
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Ngày tạo</label>
                            <input type="text" class="form-control bg-light"
                                   value="<?php echo date('d/m/Y H:i', strtotime($shipment['created_at'])); ?>" readonly>
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Mã KH <span class="text-danger">*</span></label>
                            <input type="text" id="customerCode" class="form-control text-uppercase"
                                   placeholder="VD: ABC"
                                   value="<?php echo htmlspecialchars($_POST['customer_short'] ?? ''); ?>">
                            <input type="hidden" name="customer_id" id="customerId"
                                   value="<?php echo htmlspecialchars($_POST['customer_id'] ?? ''); ?>">
                            <small class="text-muted">Nhập mã để tự động điền</small>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Khách hàng <span class="text-muted small">(Tự động)</span></label>
                            <input type="text" id="customerName" class="form-control bg-light"
                                   value="<?php echo htmlspecialchars($_POST['customer_name'] ?? ''); ?>" readonly>
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Trạng thái</label>
                            <select name="status" class="form-select">
                                <option value="pending"    <?php echo ($_POST['status'] ?? '') == 'pending'    ? 'selected' : ''; ?>>Chờ xử lý</option>
                                <option value="in_transit" <?php echo ($_POST['status'] ?? '') == 'in_transit' ? 'selected' : ''; ?>>Đang vận chuyển</option>
                                <option value="arrived"    <?php echo ($_POST['status'] ?? '') == 'arrived'    ? 'selected' : ''; ?>>Đã đến</option>
                                <option value="cleared"    <?php echo ($_POST['status'] ?? '') == 'cleared'    ? 'selected' : ''; ?>>Đã thông quan</option>
                                <option value="delivered"  <?php echo ($_POST['status'] ?? '') == 'delivered'  ? 'selected' : ''; ?>>Đã giao</option>
                                <option value="cancelled"  <?php echo ($_POST['status'] ?? '') == 'cancelled'  ? 'selected' : ''; ?>>Đã hủy</option>
                            </select>
                        </div>
                    </div>

                    <div class="section-header green">
                        <i class="bi bi-file-earmark-text"></i> Thông tin vận đơn
                    </div>
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">MAWB <span class="text-danger">*</span></label>
                            <input type="text" name="mawb" class="form-control" required
                                   value="<?php echo htmlspecialchars($_POST['mawb'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">HAWB <span class="text-danger">*</span></label>
                            <input type="text" name="hawb" class="form-control" required
                                   value="<?php echo htmlspecialchars($_POST['hawb'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Số tờ khai</label>
                            <input type="text" name="customs_declaration_no" class="form-control"
                                   value="<?php echo htmlspecialchars($_POST['customs_declaration_no'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Ngày hàng đến</label>
                            <input type="date" name="arrival_date" class="form-control"
                                   value="<?php echo $_POST['arrival_date'] ?? ''; ?>">
                        </div>
                    </div>

                    <div class="section-header orange">
                        <i class="bi bi-box-seam"></i> Thông tin hàng hóa
                    </div>
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Shipper</label>
                            <input type="text" name="shipper" class="form-control"
                                   value="<?php echo htmlspecialchars($_POST['shipper'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">CNEE (Người nhận)</label>
                            <input type="text" name="cnee" class="form-control"
                                   value="<?php echo htmlspecialchars($_POST['cnee'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">VSL / FLIGHT</label>
                            <input type="text" name="vessel_flight" class="form-control"
                                   value="<?php echo htmlspecialchars($_POST['vessel_flight'] ?? ''); ?>">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Kho hàng</label>
                            <input type="text" name="warehouse" class="form-control"
                                   value="<?php echo htmlspecialchars($_POST['warehouse'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-2 mb-3">
                            <label class="form-label">POL (Cảng đi)</label>
                            <input type="text" name="pol" class="form-control text-uppercase"
                                   value="<?php echo htmlspecialchars($_POST['pol'] ?? ''); ?>">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">POD (Cảng đến)</label>
                            <input type="text" name="pod" class="form-control text-uppercase"
                                   value="<?php echo htmlspecialchars($_POST['pod'] ?? ''); ?>">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Số kiện</label>
                            <input type="number" name="packages" class="form-control" min="0"
                                   value="<?php echo intval($_POST['packages'] ?? 0); ?>">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">GW (kg)</label>
                            <input type="number" step="0.01" name="gw" class="form-control" min="0"
                                   value="<?php echo floatval($_POST['gw'] ?? 0); ?>">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">CW / CBM</label>
                            <input type="number" step="0.01" name="cw" class="form-control" min="0"
                                   value="<?php echo floatval($_POST['cw'] ?? 0); ?>">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label class="form-label">Cont / Seal</label>
                            <input type="text" name="cont_seal" class="form-control"
                                   value="<?php echo htmlspecialchars($_POST['cont_seal'] ?? ''); ?>">
                        </div>
                    </div>

                    <div class="section-header">
                        <i class="bi bi-building"></i> Nhà cung cấp
                    </div>
                    <div class="mb-3">
                        <?php
                        $suppliers->data_seek(0);
                        while ($sup = $suppliers->fetch_assoc()):
                            $checked = in_array($sup['id'], $current_sup_ids) ? 'checked' : '';
                        ?>
                            <div class="form-check form-check-inline mb-2">
                                <input class="form-check-input" type="checkbox"
                                       name="supplier_ids[]"
                                       value="<?php echo $sup['id']; ?>"
                                       id="sup_<?php echo $sup['id']; ?>"
                                       <?php echo $checked; ?>>
                                <label class="form-check-label badge bg-warning text-dark"
                                       for="sup_<?php echo $sup['id']; ?>">
                                    <?php echo htmlspecialchars($sup['short_name']); ?>
                                </label>
                            </div>
                        <?php endwhile; ?>
                    </div>

                    <div class="section-header red">
                        <i class="bi bi-cash-stack"></i> Chi phí đầu vào (COST)
                        <button type="button" class="btn btn-sm btn-light float-end" onclick="addCostRow()">
                            <i class="bi bi-plus-circle"></i> Thêm dòng mới
                        </button>
                    </div>
                    <div id="costRows">
                        <?php foreach ($existing_costs as $idx => $cost): ?>
                        <div class="cost-row" id="cost-exist-<?php echo $cost['id']; ?>">
                            <input type="hidden" name="costs[<?php echo $idx; ?>][id]" value="<?php echo $cost['id']; ?>">
                            <div class="row align-items-end g-2">
                                <div class="col-md-2">
                                    <label class="form-label small mb-1">Mã chi phí</label>
                                    <select name="costs[<?php echo $idx; ?>][cost_code_id]" class="form-select form-select-sm"
                                            onchange="updateExistCostDesc(<?php echo $idx; ?>)">
                                        <?php
                                        $cost_codes->data_seek(0);
                                        while ($cc = $cost_codes->fetch_assoc()):
                                        ?>
                                            <option value="<?php echo $cc['id']; ?>"
                                                <?php echo $cost['cost_code_id'] == $cc['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($cc['code']); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small mb-1">Nội dung</label>
                                    <input type="text" class="form-control form-control-sm bg-light"
                                           id="cost-exist-desc-<?php echo $idx; ?>"
                                           value="<?php echo htmlspecialchars($cost['description']); ?>" readonly>
                                </div>
                                <div class="col-md-1">
                                    <label class="form-label small mb-1">SL</label>
                                    <input type="number" name="costs[<?php echo $idx; ?>][quantity]"
                                           class="form-control form-control-sm"
                                           value="<?php echo $cost['quantity']; ?>" step="0.01" min="0"
                                           oninput="calcExistCostTotal(<?php echo $idx; ?>)">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small mb-1">Đơn giá</label>
                                    <input type="number" name="costs[<?php echo $idx; ?>][unit_price]"
                                           class="form-control form-control-sm"
                                           value="<?php echo $cost['unit_price']; ?>" step="0.01" min="0"
                                           oninput="calcExistCostTotal(<?php echo $idx; ?>)">
                                </div>
                                <div class="col-md-1">
                                    <label class="form-label small mb-1">VAT%</label>
                                    <input type="number" name="costs[<?php echo $idx; ?>][vat]"
                                           class="form-control form-control-sm"
                                           value="<?php echo $cost['vat']; ?>" step="0.1" min="0"
                                           oninput="calcExistCostTotal(<?php echo $idx; ?>)">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small mb-1">Thành tiền</label>
                                    <input type="text" class="form-control form-control-sm bg-light fw-bold text-danger"
                                           id="cost-exist-total-<?php echo $idx; ?>"
                                           value="<?php echo number_format($cost['total_amount'], 0, ',', '.'); ?>" readonly>
                                </div>
                                <div class="col-md-1">
                                    <label class="form-label small mb-1">NCC</label>
                                    <select name="costs[<?php echo $idx; ?>][supplier_id]" class="form-select form-select-sm">
                                        <option value="">--</option>
                                        <?php
                                        $suppliers->data_seek(0);
                                        while ($s = $suppliers->fetch_assoc()):
                                        ?>
                                            <option value="<?php echo $s['id']; ?>"
                                                <?php echo ($cost['supplier_id'] ?? 0) == $s['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($s['short_name']); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="col-md-1">
                                    <label class="form-label small mb-1">Ghi chú</label>
                                    <input type="text" name="costs[<?php echo $idx; ?>][notes]"
                                           class="form-control form-control-sm"
                                           value="<?php echo htmlspecialchars($cost['notes'] ?? ''); ?>">
                                </div>
                                <div class="col-md-auto">
                                    <label class="form-label small mb-1 d-block">&nbsp;</label>
                                    <button type="button" class="btn btn-sm btn-danger"
                                            onclick="markDelete('cost', <?php echo $cost['id']; ?>)">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="section-header green">
                        <i class="bi bi-currency-dollar"></i> Doanh thu bán ra (SELL)
                        <button type="button" class="btn btn-sm btn-light float-end" onclick="addSellRow()">
                            <i class="bi bi-plus-circle"></i> Thêm dòng mới
                        </button>
                    </div>
                    <div id="sellRows">
                        <?php foreach ($existing_sells as $idx => $sell):
                            $is_pob_checked = intval($sell['is_pob'] ?? 0) === 1;
                            $is_trucking    = stripos($sell['code'] ?? '', 'TRUCK') !== false;
                        ?>
                        <div class="sell-row <?php echo $is_pob_checked ? 'is-pob' : ''; ?>"
                             id="sell-exist-<?php echo $sell['id']; ?>">
                            <input type="hidden" name="sells[<?php echo $idx; ?>][id]" value="<?php echo $sell['id']; ?>">
                            <div class="row align-items-end g-2">
                                <div class="col-md-2">
                                    <label class="form-label small mb-1">Mã chi phí</label>
                                    <select name="sells[<?php echo $idx; ?>][cost_code_id]" class="form-select form-select-sm"
                                            onchange="updateExistSellDesc(<?php echo $idx; ?>)">
                                        <?php
                                        $cost_codes->data_seek(0);
                                        while ($cc = $cost_codes->fetch_assoc()):
                                        ?>
                                            <option value="<?php echo $cc['id']; ?>"
                                                <?php echo $sell['cost_code_id'] == $cc['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($cc['code']); ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small mb-1">Nội dung</label>
                                    <input type="text" class="form-control form-control-sm bg-light"
                                           id="sell-exist-desc-<?php echo $idx; ?>"
                                           value="<?php echo htmlspecialchars($sell['description']); ?>" readonly>
                                </div>
                                <div class="col-md-1">
                                    <label class="form-label small mb-1">SL</label>
                                    <input type="number" name="sells[<?php echo $idx; ?>][quantity]"
                                           class="form-control form-control-sm"
                                           value="<?php echo $sell['quantity']; ?>" step="0.01" min="0"
                                           oninput="calcExistSellTotal(<?php echo $idx; ?>)">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small mb-1">Đơn giá</label>
                                    <input type="number" name="sells[<?php echo $idx; ?>][unit_price]"
                                           class="form-control form-control-sm"
                                           value="<?php echo $sell['unit_price']; ?>" step="0.01" min="0"
                                           oninput="calcExistSellTotal(<?php echo $idx; ?>)">
                                </div>
                                <div class="col-md-1">
                                    <label class="form-label small mb-1">VAT%</label>
                                    <input type="number" name="sells[<?php echo $idx; ?>][vat]"
                                           class="form-control form-control-sm"
                                           value="<?php echo $sell['vat']; ?>" step="0.1" min="0"
                                           oninput="calcExistSellTotal(<?php echo $idx; ?>)">
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small mb-1">Thành tiền</label>
                                    <input type="text" class="form-control form-control-sm bg-light fw-bold text-success"
                                           id="sell-exist-total-<?php echo $idx; ?>"
                                           value="<?php echo number_format($sell['total_amount'], 0, ',', '.'); ?>" readonly>
                                </div>
                                <div class="col-md-1">
                                    <label class="form-label small mb-1">Ghi chú</label>
                                    <input type="text" name="sells[<?php echo $idx; ?>][notes]"
                                           class="form-control form-control-sm"
                                           value="<?php echo htmlspecialchars($sell['notes'] ?? ''); ?>"
                                           <?php echo $is_trucking ? 'placeholder="VD: Nội Bài - Hà Nội, 29E 25946"' : ''; ?>>
                                    <?php if ($is_trucking): ?>
                                    <div class="trucking-hint" style="display:block;">
                                        <i class="bi bi-truck"></i> Ghi tuyến đường + biển số xe
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-auto">
                                    <label class="form-label small mb-1 d-block">&nbsp;</label>
                                    <div class="pob-check-wrap">
                                        <input type="checkbox"
                                               name="sells[<?php echo $idx; ?>][is_pob]"
                                               value="1"
                                               id="sell-exist-pob-<?php echo $idx; ?>"
                                               class="form-check-input mt-0"
                                               <?php echo $is_pob_checked ? 'checked' : ''; ?>
                                               onchange="toggleExistPob(<?php echo $idx; ?>, '<?php echo $sell['id']; ?>')">
                                        <label for="sell-exist-pob-<?php echo $idx; ?>"
                                               class="small fw-bold mb-0"
                                               style="cursor:pointer;color:#92400e;">
                                            <i class="bi bi-arrow-left-right"></i> Chi hộ
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-auto">
                                    <label class="form-label small mb-1 d-block">&nbsp;</label>
                                    <button type="button" class="btn btn-sm btn-danger"
                                            onclick="markDelete('sell', <?php echo $sell['id']; ?>)">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="section-header">
                        <i class="bi bi-chat-left-text"></i> Ghi chú
                    </div>
                    <div class="mb-3">
                        <textarea name="notes" class="form-control" rows="2"
                                  placeholder="Ghi chú thêm..."><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                    </div>

                    <?php if ($shipment['is_locked'] == 'yes'): ?>
                    <div class="section-header red">
                        <i class="bi bi-lock-fill"></i> Thông tin Khóa
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Số hóa đơn</label>
                            <input type="text" class="form-control bg-light"
                                   value="<?php echo htmlspecialchars($shipment['invoice_no'] ?? ''); ?>" readonly>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Ngày xuất HĐ</label>
                            <input type="text" class="form-control bg-light"
                                   value="<?php echo !empty($shipment['invoice_date']) ? date('d/m/Y', strtotime($shipment['invoice_date'])) : ''; ?>" readonly>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Thời gian khóa</label>
                            <input type="text" class="form-control bg-light"
                                   value="<?php echo !empty($shipment['locked_at']) ? date('d/m/Y H:i', strtotime($shipment['locked_at'])) : ''; ?>" readonly>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="d-flex justify-content-between mt-4">
                        <div>
                            <a href="view.php?id=<?php echo $id; ?>" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Quay lại
                            </a>
                            <a href="index.php" class="btn btn-outline-secondary ms-1">
                                <i class="bi bi-list"></i> Danh sách
                            </a>
                        </div>
                        <button type="submit"
                                class="btn btn-lg <?php echo $shipment['is_locked'] == 'yes' ? 'btn-danger' : 'btn-warning'; ?>">
                            <i class="bi bi-save"></i> Cập nhật lô hàng
                        </button>
                    </div>

                </form>
            </div>
        </div>
    </div>

    <footer class="bg-white text-center py-2 border-top mt-3">
        <small class="text-muted">&copy; <?php echo date('Y'); ?> Forwarder System</small>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    const costCodes = <?php echo json_encode($cost_codes_json); ?>;
    const suppliers = <?php echo json_encode($suppliers_json); ?>;
    let costRowIndex = <?php echo count($existing_costs); ?>;
    let sellRowIndex = <?php echo count($existing_sells); ?>;

    document.getElementById('customerCode').addEventListener('blur', function () {
        const code = this.value.trim().toUpperCase();
        if (!code) return;
        const curId = document.getElementById('customerId').value;
        fetch('../api/get_customer.php?short_name=' + encodeURIComponent(code))
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('customerId').value   = data.id;
                    document.getElementById('customerName').value = data.company_name;
                    this.value = data.short_name;
                } else {
                    alert('Không tìm thấy khách hàng: ' + code);
                    document.getElementById('customerId').value  = curId;
                }
            });
    });
    document.getElementById('customerCode').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); this.blur(); }
    });

    function updateExistCostDesc(idx) {
        const sel  = document.querySelector(`select[name="costs[${idx}][cost_code_id]"]`);
        const desc = document.getElementById(`cost-exist-desc-${idx}`);
        if (sel && sel.value && costCodes[sel.value]) desc.value = costCodes[sel.value].description;
    }
    function updateExistSellDesc(idx) {
        const sel  = document.querySelector(`select[name="sells[${idx}][cost_code_id]"]`);
        const desc = document.getElementById(`sell-exist-desc-${idx}`);
        if (sel && sel.value && costCodes[sel.value]) {
            desc.value = costCodes[sel.value].description;
            const code = costCodes[sel.value].code || '';
            const rowEl = sel.closest('.sell-row');
            const hintEl = rowEl ? rowEl.querySelector('.trucking-hint') : null;
            if (hintEl) hintEl.style.display = code.toUpperCase().includes('TRUCK') ? 'block' : 'none';
        }
    }

    function calcExistCostTotal(idx) {
        const qty   = parseFloat(document.querySelector(`input[name="costs[${idx}][quantity]"]`).value)   || 0;
        const price = parseFloat(document.querySelector(`input[name="costs[${idx}][unit_price]"]`).value) || 0;
        const vat   = parseFloat(document.querySelector(`input[name="costs[${idx}][vat]"]`).value)        || 0;
        document.getElementById(`cost-exist-total-${idx}`).value = (qty * price * (1 + vat / 100)).toLocaleString('vi-VN');
    }
    function calcExistSellTotal(idx) {
        const qty   = parseFloat(document.querySelector(`input[name="sells[${idx}][quantity]"]`).value)   || 0;
        const price = parseFloat(document.querySelector(`input[name="sells[${idx}][unit_price]"]`).value) || 0;
        const vat   = parseFloat(document.querySelector(`input[name="sells[${idx}][vat]"]`).value)        || 0;
        document.getElementById(`sell-exist-total-${idx}`).value = (qty * price * (1 + vat / 100)).toLocaleString('vi-VN');
    }

    function toggleExistPob(idx, rowId) {
        const checkbox = document.getElementById(`sell-exist-pob-${idx}`);
        const row      = document.getElementById(`sell-exist-${rowId}`);
        if (checkbox && row) {
            row.classList.toggle('is-pob', checkbox.checked);
        }
    }

    function markDelete(type, rowId) {
        if (!confirm('Bạn có chắc muốn xóa dòng này?')) return;
        const row = document.getElementById(`${type}-exist-${rowId}`);
        row.classList.add('deleted-row');
        const inp = document.createElement('input');
        inp.type  = 'hidden';
        inp.name  = `delete_${type}s[]`;
        inp.value = rowId;
        row.appendChild(inp);
        row.querySelectorAll('input, select, button').forEach(el => el.disabled = true);
        const undoBtn = document.createElement('button');
        undoBtn.type      = 'button';
        undoBtn.className = 'btn btn-sm btn-secondary ms-1';
        undoBtn.innerHTML = '<i class="bi bi-arrow-counterclockwise"></i> Hoàn tác';
        undoBtn.disabled  = false;
        undoBtn.onclick   = () => undoDelete(type, rowId, inp, undoBtn);
        row.querySelector('.row').appendChild(undoBtn);
    }
    function undoDelete(type, rowId, inp, undoBtn) {
        const row = document.getElementById(`${type}-exist-${rowId}`);
        row.classList.remove('deleted-row');
        inp.remove();
        undoBtn.remove();
        row.querySelectorAll('input, select, button').forEach(el => el.disabled = false);
    }

    function addCostRow() {
        const supOpts = Object.keys(suppliers)
            .map(id => `<option value="${id}">${suppliers[id].short_name}</option>`).join('');
        const ccOpts = Object.keys(costCodes)
            .map(id => `<option value="${id}">${costCodes[id].code}</option>`).join('');
        const html = `
        <div class="cost-row" id="cost-new-${costRowIndex}">
            <div class="row align-items-end g-2">
                <div class="col-md-2">
                    <label class="form-label small mb-1">Mã chi phí</label>
                    <select name="costs[${costRowIndex}][cost_code_id]" class="form-select form-select-sm"
                            onchange="updateNewCostDesc(${costRowIndex})">
                        <option value="">-- Chọn --</option>${ccOpts}
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Nội dung</label>
                    <input type="text" id="cost-new-desc-${costRowIndex}"
                           class="form-control form-control-sm bg-light" readonly placeholder="Tự động">
                </div>
                <div class="col-md-1">
                    <label class="form-label small mb-1">SL</label>
                    <input type="number" name="costs[${costRowIndex}][quantity]"
                           class="form-control form-control-sm" value="1" step="0.01" min="0"
                           oninput="calcNewCostTotal(${costRowIndex})">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Đơn giá</label>
                    <input type="number" name="costs[${costRowIndex}][unit_price]"
                           class="form-control form-control-sm" value="0" step="0.01" min="0"
                           oninput="calcNewCostTotal(${costRowIndex})">
                </div>
                <div class="col-md-1">
                    <label class="form-label small mb-1">VAT%</label>
                    <input type="number" name="costs[${costRowIndex}][vat]"
                           class="form-control form-control-sm" value="0" step="0.1" min="0"
                           oninput="calcNewCostTotal(${costRowIndex})">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Thành tiền</label>
                    <input type="text" id="cost-new-total-${costRowIndex}"
                           class="form-control form-control-sm bg-light fw-bold text-danger" readonly value="0">
                </div>
                <div class="col-md-1">
                    <label class="form-label small mb-1">NCC</label>
                    <select name="costs[${costRowIndex}][supplier_id]" class="form-select form-select-sm">
                        <option value="">--</option>${supOpts}
                    </select>
                </div>
                <div class="col-md-1">
                    <label class="form-label small mb-1">Ghi chú</label>
                    <input type="text" name="costs[${costRowIndex}][notes]" class="form-control form-control-sm">
                </div>
                <div class="col-md-auto">
                    <label class="form-label small mb-1 d-block">&nbsp;</label>
                    <button type="button" class="btn btn-sm btn-danger"
                            onclick="document.getElementById('cost-new-${costRowIndex}').remove()">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        </div>`;
        document.getElementById('costRows').insertAdjacentHTML('beforeend', html);
        costRowIndex++;
    }
    function updateNewCostDesc(i) {
        const sel  = document.querySelector(`select[name="costs[${i}][cost_code_id]"]`);
        const desc = document.getElementById(`cost-new-desc-${i}`);
        desc.value = sel.value && costCodes[sel.value] ? costCodes[sel.value].description : '';
    }
    function calcNewCostTotal(i) {
        const qty   = parseFloat(document.querySelector(`input[name="costs[${i}][quantity]"]`).value)   || 0;
        const price = parseFloat(document.querySelector(`input[name="costs[${i}][unit_price]"]`).value) || 0;
        const vat   = parseFloat(document.querySelector(`input[name="costs[${i}][vat]"]`).value)        || 0;
        document.getElementById(`cost-new-total-${i}`).value = (qty * price * (1 + vat / 100)).toLocaleString('vi-VN');
    }

    function addSellRow() {
        const ccOpts = Object.keys(costCodes)
            .map(id => `<option value="${id}">${costCodes[id].code}</option>`).join('');
        const idx = sellRowIndex;
        const html = `
        <div class="sell-row" id="sell-new-${idx}">
            <div class="row align-items-end g-2">
                <div class="col-md-2">
                    <label class="form-label small mb-1">Mã chi phí</label>
                    <select name="sells[${idx}][cost_code_id]" class="form-select form-select-sm"
                            onchange="updateNewSellDesc(${idx})">
                        <option value="">-- Chọn --</option>${ccOpts}
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Nội dung</label>
                    <input type="text" id="sell-new-desc-${idx}"
                           class="form-control form-control-sm bg-light" readonly placeholder="Tự động">
                </div>
                <div class="col-md-1">
                    <label class="form-label small mb-1">SL</label>
                    <input type="number" name="sells[${idx}][quantity]"
                           class="form-control form-control-sm" value="1" step="0.01" min="0"
                           oninput="calcNewSellTotal(${idx})">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Đơn giá</label>
                    <input type="number" name="sells[${idx}][unit_price]"
                           class="form-control form-control-sm" value="0" step="0.01" min="0"
                           oninput="calcNewSellTotal(${idx})">
                </div>
                <div class="col-md-1">
                    <label class="form-label small mb-1">VAT%</label>
                    <input type="number" name="sells[${idx}][vat]"
                           class="form-control form-control-sm" value="0" step="0.1" min="0"
                           oninput="calcNewSellTotal(${idx})">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Thành tiền</label>
                    <input type="text" id="sell-new-total-${idx}"
                           class="form-control form-control-sm bg-light fw-bold text-success" readonly value="0">
                </div>
                <div class="col-md-1">
                    <label class="form-label small mb-1">Ghi chú</label>
                    <input type="text" name="sells[${idx}][notes]" id="sell-new-notes-${idx}"
                           class="form-control form-control-sm" placeholder="Ghi chú...">
                    <div class="trucking-hint" id="sell-new-truckhint-${idx}">
                        <i class="bi bi-truck"></i> Tuyến đường + biển số<br>
                        <em>VD: Nội Bài - HN, 29E 25946</em>
                    </div>
                </div>
                <div class="col-md-auto">
                    <label class="form-label small mb-1 d-block">&nbsp;</label>
                    <div class="pob-check-wrap">
                        <input type="checkbox" name="sells[${idx}][is_pob]" value="1"
                               id="sell-new-pob-${idx}" class="form-check-input mt-0"
                               onchange="toggleNewPob(${idx})">
                        <label for="sell-new-pob-${idx}" class="small fw-bold mb-0"
                               style="cursor:pointer;color:#92400e;">
                            <i class="bi bi-arrow-left-right"></i> Chi hộ
                        </label>
                    </div>
                </div>
                <div class="col-md-auto">
                    <label class="form-label small mb-1 d-block">&nbsp;</label>
                    <button type="button" class="btn btn-sm btn-danger"
                            onclick="document.getElementById('sell-new-${idx}').remove()">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
        </div>`;
        document.getElementById('sellRows').insertAdjacentHTML('beforeend', html);
        sellRowIndex++;
    }
    function updateNewSellDesc(i) {
        const sel  = document.querySelector(`select[name="sells[${i}][cost_code_id]"]`);
        const desc = document.getElementById(`sell-new-desc-${i}`);
        if (!sel || !sel.value || !costCodes[sel.value]) { if (desc) desc.value = ''; return; }
        const code = costCodes[sel.value].code || '';
        desc.value = costCodes[sel.value].description;
        const hint  = document.getElementById(`sell-new-truckhint-${i}`);
        const notes = document.getElementById(`sell-new-notes-${i}`);
        if (hint && code.toUpperCase().includes('TRUCK')) {
            hint.style.display = 'block';
            if (notes) { notes.placeholder = 'VD: Nội Bài - Hà Nội, 29E 25946'; notes.focus(); }
        } else if (hint) {
            hint.style.display = 'none';
            if (notes) notes.placeholder = 'Ghi chú...';
        }
    }
    function calcNewSellTotal(i) {
        const qty   = parseFloat(document.querySelector(`input[name="sells[${i}][quantity]"]`).value)   || 0;
        const price = parseFloat(document.querySelector(`input[name="sells[${i}][unit_price]"]`).value) || 0;
        const vat   = parseFloat(document.querySelector(`input[name="sells[${i}][vat]"]`).value)        || 0;
        document.getElementById(`sell-new-total-${i}`).value = (qty * price * (1 + vat / 100)).toLocaleString('vi-VN');
    }
    function toggleNewPob(i) {
        const cb  = document.getElementById(`sell-new-pob-${i}`);
        const row = document.getElementById(`sell-new-${i}`);
        if (cb && row) row.classList.toggle('is-pob', cb.checked);
    }

    document.getElementById('editForm').addEventListener('submit', function (e) {
        if (!document.getElementById('customerId').value) {
            e.preventDefault();
            alert('Vui lòng chọn khách hàng hợp lệ!');
            document.getElementById('customerCode').focus();
        }
    });
    </script>
</body>
</html>