<?php
include 'config.php';

$admin_id = $_SESSION['admin_id'] ?? null;
if (!isset($admin_id)) {
    header('location:login.php');
    exit;
}

include 'admin_log_activity.php';

// ==================== ADD NEW INGREDIENT ====================
if (isset($_POST['add_ingredient'])) {
    try {
        $ingredient_name = trim($_POST['ingredient_name'] ?? '');
        $category = $_POST['category'] ?? '';
        $quantity = floatval($_POST['quantity'] ?? 0);
        $unit = $_POST['unit'] ?? '';
        $min_stock = floatval($_POST['min_stock_level'] ?? 0);

        if (empty($ingredient_name) || !in_array($category, ['consumable', 'packaging'], true) || empty($unit)) {
            throw new Exception('Please fill in all required fields');
        }

        $insert_stmt = $conn->prepare(
            "INSERT INTO inventory (ingredient_name, category, quantity, unit, min_stock_level) 
             VALUES (?, ?, ?, ?, ?)"
        );
        $insert_stmt->bind_param("ssdsd", $ingredient_name, $category, $quantity, $unit, $min_stock);

        if (!$insert_stmt->execute()) {
            throw new Exception("Failed to add ingredient");
        }
        $ingredient_id = $insert_stmt->insert_id;
        $insert_stmt->close();

        log_admin_activity($admin_id, 'Add Ingredient', "Added new ingredient: $ingredient_name", 'success', 'ingredient', $ingredient_id, null, ['name' => $ingredient_name, 'quantity' => $quantity, 'unit' => $unit]);
        $_SESSION['message'] = ['type' => 'success', 'text' => "$ingredient_name added successfully!"];

    } catch (Exception $e) {
        log_admin_activity((int)$admin_id, 'Add Ingredient', 'Failed to add ingredient: ' . $e->getMessage(), 'failure', 'ingredient');
        $_SESSION['message'] = ['type' => 'error', 'text' => $e->getMessage()];
    }

    header('location:admin_inventory.php');
    exit;
}

// ==================== UPDATE INVENTORY ====================
if (isset($_POST['update_inventory'])) {
    try {
        $ingredient_id = intval($_POST['ingredient_id'] ?? 0);
        $new_quantity = floatval($_POST['new_quantity'] ?? 0);
        $min_stock = floatval($_POST['min_stock_level'] ?? 0);

        if ($ingredient_id <= 0) {
            throw new Exception('Invalid ingredient selected');
        }

        $info_stmt = $conn->prepare("SELECT ingredient_name, quantity, min_stock_level FROM inventory WHERE id = ?");
        $info_stmt->bind_param("i", $ingredient_id);
        $info_stmt->execute();
        $info_result = $info_stmt->get_result();
        $info_row = $info_result->fetch_assoc();
        if (!$info_row) {
            throw new Exception('Ingredient not found');
        }
        $ingredient_name = $info_row['ingredient_name'] ?? 'Unknown';
        $info_stmt->close();

        $update_stmt = $conn->prepare("UPDATE inventory SET quantity = ?, min_stock_level = ? WHERE id = ?");
        $update_stmt->bind_param("ddi", $new_quantity, $min_stock, $ingredient_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update inventory");
        }
        $update_stmt->close();

        log_admin_activity($admin_id, 'Update Inventory', "Updated $ingredient_name: qty=$new_quantity", 'success', 'ingredient', $ingredient_id, ['quantity' => $info_row['quantity'], 'min_stock_level' => $info_row['min_stock_level']], ['quantity' => $new_quantity, 'min_stock_level' => $min_stock]);
        $_SESSION['message'] = ['type' => 'success', 'text' => "$ingredient_name updated successfully!"];

    } catch (Exception $e) {
        log_admin_activity((int)$admin_id, 'Update Inventory', 'Failed to update inventory: ' . $e->getMessage(), 'failure', 'ingredient', isset($ingredient_id) ? $ingredient_id : null);
        $_SESSION['message'] = ['type' => 'error', 'text' => $e->getMessage()];
    }

    header('location:admin_inventory.php');
    exit;
}

// ==================== DELETE INGREDIENT ====================
if (isset($_GET['delete'])) {
    try {
        $ingredient_id = intval($_GET['delete']);
        $record_stmt = $conn->prepare('SELECT ingredient_name, quantity, unit FROM inventory WHERE id = ? LIMIT 1');
        $record_stmt->bind_param('i', $ingredient_id);
        $record_stmt->execute();
        $record_stmt->bind_result($deleted_ingredient_name, $deleted_quantity, $deleted_unit);
        $record_found = $record_stmt->fetch();
        $record_stmt->close();
        
        $delete_stmt = $conn->prepare("DELETE FROM inventory WHERE id = ?");
        $delete_stmt->bind_param("i", $ingredient_id);
        
        if (!$delete_stmt->execute()) {
            throw new Exception("Failed to delete ingredient");
        }
        $delete_stmt->close();

        log_admin_activity($admin_id, 'Delete Ingredient', "Deleted ingredient: " . ($deleted_ingredient_name ?? "ID $ingredient_id"), 'success', 'ingredient', $ingredient_id, $record_found ? ['name' => $deleted_ingredient_name, 'quantity' => $deleted_quantity, 'unit' => $deleted_unit] : null, null);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Ingredient deleted successfully'];
    } catch (Exception $e) {
        log_admin_activity((int)$admin_id, 'Delete Ingredient', 'Failed to delete ingredient: ' . $e->getMessage(), 'failure', 'ingredient', isset($ingredient_id) ? $ingredient_id : null);
        $_SESSION['message'] = ['type' => 'error', 'text' => $e->getMessage()];
    }

    header('location:admin_inventory.php');
    exit;
}

// ==================== FETCH INVENTORY ====================
$inventory_stmt = $conn->prepare("SELECT * FROM inventory ORDER BY category ASC, ingredient_name ASC");
$inventory_stmt->execute();
$inventory_result = $inventory_stmt->get_result();
$all_items = [];
$low_stock_count = 0;
$total_count = 0;
while ($row = $inventory_result->fetch_assoc()) {
    $all_items[] = $row;
    $total_count++;
    if (floatval($row['quantity']) <= floatval($row['min_stock_level'])) {
        $low_stock_count++;
    }
}
$inventory_stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Management — Six Origins Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" />
    <link rel="icon" type="image/png" href="images/logos.png">
    <style>
        :root {
            --red:        #C6453E;
            --red-dark:   #A0302A;
            --brown:      #5E1F13;
            --brown-mid:  #664C47;
            --cream:      #FFF2E0;
            --cream-light:#FFFAF5;
            --cream-mid:  #F7EEE4;
            --white:      #FFFFFF;
            --border:     #EAD9C8;
            --radius-lg:  18px;
            --radius:     12px;
            --radius-sm:  8px;
            --shadow-sm:  0 2px 8px rgba(94,31,19,.06);
            --shadow:     0 6px 24px rgba(94,31,19,.09);
            --shadow-lg:  0 16px 48px rgba(94,31,19,.13);
            --transition: all .25s cubic-bezier(.4,0,.2,1);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background: var(--cream-light);
            color: var(--brown);
            font-family: 'Montserrat', system-ui, sans-serif;
            min-height: 100vh;
        }

        /* ── TOAST ── */
        .toast-container {
            position: fixed;
            top: 24px; right: 24px;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        .toast {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            border-radius: var(--radius);
            font-weight: 700;
            font-size: .88rem;
            min-width: 260px;
            max-width: 380px;
            box-shadow: var(--shadow-lg);
            animation: slideIn .35s ease forwards;
        }
        .toast.success { background: #fff; border-left: 4px solid #10B981; color: #065F46; }
        .toast.error   { background: #fff; border-left: 4px solid var(--red);   color: #991B1B; }
        .toast .toast-icon { font-size: 1.1rem; flex-shrink: 0; }
        .toast .toast-close {
            margin-left: auto;
            background: none;
            border: none;
            cursor: pointer;
            color: inherit;
            opacity: .5;
            font-size: .85rem;
        }
        .toast .toast-close:hover { opacity: 1; }
        @keyframes slideIn {
            from { transform: translateX(120%); opacity: 0; }
            to   { transform: translateX(0);    opacity: 1; }
        }
        @keyframes slideOut {
            to { transform: translateX(120%); opacity: 0; }
        }

        /* ── PAGE WRAPPER ── */
        .page-wrapper {
            max-width: 1440px;
            margin: 0 auto;
            padding: 36px 28px 72px;
        }

        /* ── PAGE HEADER ── */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-bottom: 28px;
            flex-wrap: wrap;
            gap: 16px;
        }
        .page-header-left h1 {
            font-size: 1.65rem;
            font-weight: 900;
            color: var(--brown);
            line-height: 1.1;
        }
        .page-header-left p {
            font-size: .85rem;
            color: var(--brown-mid);
            font-weight: 500;
            margin-top: 4px;
        }

        /* ── STATS ROW ── */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 28px;
        }
        .stat-card {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 18px 20px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }
        .stat-card:hover { box-shadow: var(--shadow); transform: translateY(-2px); }
        .stat-icon {
            width: 46px; height: 46px;
            border-radius: 12px;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.1rem;
            flex-shrink: 0;
        }
        .stat-icon.red   { background: #FEE2E2; color: var(--red); }
        .stat-icon.green { background: #D1FAE5; color: #065F46; }
        .stat-icon.brown { background: var(--cream); color: var(--brown); }
        .stat-card h3 { font-size: 1.5rem; font-weight: 900; line-height: 1; }
        .stat-card p  { font-size: .75rem; font-weight: 600; color: var(--brown-mid); margin-top: 2px; }

        /* ── MAIN LAYOUT ── */
        .main-layout {
            display: grid;
            grid-template-columns: 340px 1fr;
            gap: 24px;
            align-items: start;
        }

        /* ── FORM PANEL ── */
        .form-panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            position: sticky;
            top: 24px;
        }
        .form-panel-header {
            background: linear-gradient(135deg, var(--brown) 0%, #8B2E22 100%);
            padding: 20px 22px 18px;
            color: #fff;
        }
        .form-panel-header h2 {
            font-size: 1rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .form-panel-header p {
            font-size: .75rem;
            opacity: .7;
            margin-top: 4px;
            font-weight: 500;
        }
        .form-body { padding: 22px; }

        /* ── FORM ELEMENTS ── */
        .field { display: flex; flex-direction: column; gap: 5px; }
        .field + .field { margin-top: 14px; }
        .field label {
            font-size: .73rem;
            font-weight: 800;
            color: var(--brown-mid);
            text-transform: uppercase;
            letter-spacing: .04em;
        }
        .field input,
        .field select {
            padding: 10px 13px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--cream-light);
            color: var(--brown);
            font-family: inherit;
            font-size: .88rem;
            font-weight: 600;
            outline: none;
            transition: var(--transition);
            width: 100%;
        }
        .field input:focus,
        .field select:focus {
            border-color: var(--red);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(198,69,62,.1);
        }
        .field input::placeholder { color: #C4A898; font-weight: 500; }
        .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 14px; }
        .field-hint {
            font-size: .7rem;
            color: var(--brown-mid);
            opacity: .7;
            font-weight: 500;
        }

        .btn-submit {
            margin-top: 20px;
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, var(--red) 0%, var(--red-dark) 100%);
            color: #fff;
            border: none;
            border-radius: var(--radius-sm);
            font-family: inherit;
            font-size: .9rem;
            font-weight: 800;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: var(--transition);
            letter-spacing: .01em;
        }
        .btn-submit:hover {
            background: linear-gradient(135deg, var(--red-dark) 0%, var(--brown) 100%);
            box-shadow: 0 6px 20px rgba(198,69,62,.35);
            transform: translateY(-1px);
        }
        .btn-submit:active { transform: translateY(0); }

        /* ── TABLE PANEL ── */
        .table-panel {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        /* ── TOOLBAR ── */
        .toolbar {
            padding: 18px 22px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        .toolbar h2 {
            font-size: 1rem;
            font-weight: 800;
            color: var(--brown);
        }
        .toolbar-controls {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .search-box {
            position: relative;
        }
        .search-box i {
            position: absolute;
            left: 11px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--brown-mid);
            font-size: .85rem;
            pointer-events: none;
        }
        .search-box input {
            padding: 8px 12px 8px 32px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--cream-light);
            font-family: inherit;
            font-size: .83rem;
            font-weight: 600;
            color: var(--brown);
            outline: none;
            transition: var(--transition);
            width: 200px;
        }
        .search-box input:focus {
            border-color: var(--red);
            width: 240px;
        }
        .filter-select {
            padding: 8px 12px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--cream-light);
            font-family: inherit;
            font-size: .83rem;
            font-weight: 600;
            color: var(--brown);
            outline: none;
            cursor: pointer;
        }

        /* ── TABLE ── */
        .table-scroll { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }

        thead { background: #FDF8F4; }
        th {
            padding: 12px 16px;
            text-align: left;
            font-size: .72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: var(--brown-mid);
            white-space: nowrap;
            border-bottom: 1px solid var(--border);
        }
        th.sortable { cursor: pointer; user-select: none; }
        th.sortable:hover { color: var(--brown); }
        th.sortable i { margin-left: 4px; opacity: .5; font-size: .65rem; }

        td {
            padding: 13px 16px;
            font-size: .88rem;
            font-weight: 500;
            border-bottom: 1px solid #F5EDE4;
            vertical-align: middle;
        }
        tr:last-child td { border-bottom: none; }
        tbody tr {
            transition: background .15s;
        }
        tbody tr:hover { background: #FFFAF5; }

        .item-name { font-weight: 700; color: var(--brown); }
        .item-unit { color: var(--brown-mid); font-size: .82rem; }
        .qty-value { font-weight: 800; font-size: .95rem; }
        .qty-low  { color: #EF4444; }
        .qty-ok   { color: #059669; }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 9px;
            border-radius: 100px;
            font-size: .72rem;
            font-weight: 700;
        }
        .badge-cat  { background: var(--cream-mid); color: var(--brown-mid); }
        .badge-low  { background: #FEE2E2; color: #DC2626; }
        .badge-ok   { background: #D1FAE5; color: #065F46; }
        .badge-warn { background: #FEF3C7; color: #92400E; }

        /* ── Progress bar for stock ── */
        .stock-bar-wrap { width: 80px; }
        .stock-bar {
            height: 5px;
            background: #EEE5DD;
            border-radius: 99px;
            overflow: hidden;
        }
        .stock-bar-fill {
            height: 100%;
            border-radius: 99px;
            transition: width .4s ease;
        }
        .fill-low  { background: #EF4444; }
        .fill-ok   { background: #10B981; }
        .fill-warn { background: #F59E0B; }

        /* ── ACTION BUTTONS ── */
        .actions { display: flex; gap: 6px; }
        .btn-icon {
            width: 32px; height: 32px;
            border-radius: 7px;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .78rem;
            transition: var(--transition);
            text-decoration: none;
        }
        .btn-icon:hover { transform: translateY(-1px); }
        .btn-edit-icon   { background: #EFF6FF; color: #2563EB; }
        .btn-edit-icon:hover { background: #DBEAFE; }
        .btn-delete-icon { background: #FEF2F2; color: #EF4444; }
        .btn-delete-icon:hover { background: #FEE2E2; }

        /* ── EMPTY STATE ── */
        .empty-state {
            padding: 64px 32px;
            text-align: center;
        }
        .empty-state .empty-icon {
            width: 72px; height: 72px;
            background: var(--cream-mid);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.8rem;
            color: var(--brown-mid);
            margin: 0 auto 18px;
            opacity: .5;
        }
        .empty-state p { color: var(--brown-mid); font-weight: 600; font-size: .9rem; }

        /* ── NO RESULTS (filtered) ── */
        #noResults {
            display: none;
            padding: 40px;
            text-align: center;
            color: var(--brown-mid);
            font-weight: 600;
            font-size: .9rem;
        }

        /* ── MODAL ── */
        .modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.45);
            z-index: 1000;
            backdrop-filter: blur(2px);
            align-items: center;
            justify-content: center;
        }
        .modal-backdrop.open { display: flex; }

        .modal {
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            width: 92%;
            max-width: 400px;
            animation: popIn .25s ease;
            overflow: hidden;
        }
        @keyframes popIn {
            from { transform: scale(.92); opacity: 0; }
            to   { transform: scale(1);  opacity: 1; }
        }
        .modal-header {
            background: linear-gradient(135deg, var(--brown) 0%, #8B2E22 100%);
            padding: 18px 22px;
            color: #fff;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h3 { font-size: .95rem; font-weight: 800; }
        .modal-header .modal-close {
            background: rgba(255,255,255,.15);
            border: none;
            color: #fff;
            width: 28px; height: 28px;
            border-radius: 7px;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer;
            font-size: .8rem;
            transition: var(--transition);
        }
        .modal-header .modal-close:hover { background: rgba(255,255,255,.28); }
        .modal-body { padding: 22px; }
        .modal-item-name {
            font-size: .88rem;
            font-weight: 700;
            color: var(--brown);
            background: var(--cream-mid);
            padding: 9px 13px;
            border-radius: var(--radius-sm);
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .modal-field { display: flex; flex-direction: column; gap: 5px; }
        .modal-field + .modal-field { margin-top: 14px; }
        .modal-field label {
            font-size: .72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: var(--brown-mid);
        }
        .modal-field input {
            padding: 10px 13px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--cream-light);
            font-family: inherit;
            font-size: .9rem;
            font-weight: 700;
            color: var(--brown);
            outline: none;
            transition: var(--transition);
        }
        .modal-field input:focus {
            border-color: var(--red);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(198,69,62,.1);
        }
        .modal-footer {
            padding: 16px 22px;
            background: #FDF8F4;
            border-top: 1px solid var(--border);
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        .btn-modal-cancel {
            padding: 9px 18px;
            background: transparent;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            font-family: inherit;
            font-size: .85rem;
            font-weight: 700;
            color: var(--brown-mid);
            cursor: pointer;
            transition: var(--transition);
        }
        .btn-modal-cancel:hover { border-color: var(--brown-mid); color: var(--brown); }
        .btn-modal-save {
            padding: 9px 22px;
            background: var(--red);
            border: none;
            border-radius: var(--radius-sm);
            font-family: inherit;
            font-size: .85rem;
            font-weight: 800;
            color: #fff;
            cursor: pointer;
            transition: var(--transition);
            display: flex; align-items: center; gap: 6px;
        }
        .btn-modal-save:hover { background: var(--red-dark); }

        /* ── RESPONSIVE ── */
        @media (max-width: 1100px) {
            .main-layout { grid-template-columns: 1fr; }
            .form-panel { position: static; }
        }
        @media (max-width: 768px) {
            .stats-row { grid-template-columns: 1fr 1fr; }
            .page-wrapper { padding: 20px 16px 60px; }
        }
        @media (max-width: 480px) {
            .stats-row { grid-template-columns: 1fr; }
            .search-box input { width: 160px; }
            .search-box input:focus { width: 180px; }
        }
    </style>
</head>
<body>

<?php include 'admin_header.php'; ?>

<!-- TOAST NOTIFICATIONS -->
<div class="toast-container" id="toastContainer">
<?php if (isset($_SESSION['message'])): $msg = $_SESSION['message']; unset($_SESSION['message']); ?>
<div class="toast <?= $msg['type'] ?>" role="alert">
    <span class="toast-icon">
        <?= $msg['type'] === 'success' ? '<i class="fa-solid fa-circle-check"></i>' : '<i class="fa-solid fa-circle-exclamation"></i>' ?>
    </span>
    <?= htmlspecialchars($msg['text']) ?>
    <button class="toast-close" onclick="dismissToast(this)"><i class="fa-solid fa-xmark"></i></button>
</div>
<?php endif; ?>
</div>

<div class="page-wrapper">

    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-left">
            <h1><i class="fa-solid fa-boxes-stacked" style="color:var(--red); margin-right:8px;"></i>Inventory</h1>
            <p>Manage stock levels and ingredient catalog</p>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="stats-row">
        <div class="stat-card">
            <div class="stat-icon brown"><i class="fa-solid fa-layer-group"></i></div>
            <div>
                <h3><?= $total_count ?></h3>
                <p>Total Items</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon red"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div>
                <h3 style="color:<?= $low_stock_count > 0 ? '#EF4444' : 'inherit' ?>"><?= $low_stock_count ?></h3>
                <p>Low Stock Alerts</p>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green"><i class="fa-solid fa-circle-check"></i></div>
            <div>
                <h3 style="color:#059669"><?= $total_count - $low_stock_count ?></h3>
                <p>Items Available</p>
            </div>
        </div>
    </div>

    <!-- Main Layout -->
    <div class="main-layout">

        <!-- ADD FORM -->
        <div class="form-panel">
            <div class="form-panel-header">
                <h2><i class="fa-solid fa-plus-circle"></i> Add New Item</h2>
                <p>Fill out all fields to create a new inventory entry</p>
            </div>
            <div class="form-body">
                <form method="POST" novalidate id="addForm">

                    <div class="field">
                        <label>Item Name <span style="color:var(--red)">*</span></label>
                        <input type="text" name="ingredient_name" placeholder="e.g. Premium Espresso Beans" required autocomplete="off">
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label>Category <span style="color:var(--red)">*</span></label>
                            <select name="category" required>
                                <option value="">-- Select --</option>
                                <option value="consumable">Consumable</option>
                                <option value="packaging">Packaging</option>
                            </select>
                        </div>
                        <div class="field">
                            <label>Unit <span style="color:var(--red)">*</span></label>
                            <select name="unit" required>
                                <option value="">-- Select --</option>
                                <option value="grams">Grams</option>
                                <option value="ml">ML</option>
                                <option value="pieces">Pieces</option>
                                <option value="cups">Cups</option>
                            </select>
                        </div>
                    </div>

                    <div class="field-row">
                        <div class="field">
                            <label>Opening Stock</label>
                            <input type="number" name="quantity" min="0" step="0.01" placeholder="0.00">
                            <span class="field-hint">Current quantity on hand</span>
                        </div>
                        <div class="field">
                            <label>Alert Level</label>
                            <input type="number" name="min_stock_level" min="0" step="0.01" placeholder="0.00">
                            <span class="field-hint">Trigger low stock warning</span>
                        </div>
                    </div>

                    <button type="submit" name="add_ingredient" class="btn-submit">
                        <i class="fa-solid fa-plus"></i> Add Item to Inventory
                    </button>
                </form>
            </div>
        </div>

        <!-- TABLE -->
        <div class="table-panel">
            <div class="toolbar">
                <h2>All Items <span style="color:var(--brown-mid); font-weight:600; font-size:.85rem;">&nbsp;(<?= $total_count ?>)</span></h2>
                <div class="toolbar-controls">
                    <div class="search-box">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="searchInput" placeholder="Search items…" oninput="filterTable()">
                    </div>
                    <select class="filter-select" id="categoryFilter" onchange="filterTable()">
                        <option value="">All Categories</option>
                        <option value="consumable">Consumable</option>
                        <option value="packaging">Packaging</option>
                    </select>
                    <select class="filter-select" id="statusFilter" onchange="filterTable()">
                        <option value="">All Status</option>
                        <option value="low">Low Stock</option>
                        <option value="ok">Available</option>
                    </select>
                </div>
            </div>

            <?php if (count($all_items) > 0): ?>
            <div class="table-scroll">
                <table id="inventoryTable">
                    <thead>
                        <tr>
                            <th>Item Name</th>
                            <th>Category</th>
                            <th>Stock Level</th>
                            <th>Alert At</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($all_items as $item):
                        $qty = floatval($item['quantity']);
                        $min = floatval($item['min_stock_level']);
                        $is_low = $qty <= $min;
                        // Calculate fill percentage: cap at 100%, 0 min = always full
                        $pct = ($min > 0) ? min(100, round(($qty / ($min * 2)) * 100)) : ($qty > 0 ? 100 : 0);
                        $fill_class = $is_low ? 'fill-low' : ($pct < 70 ? 'fill-warn' : 'fill-ok');
                        $status_class = $is_low ? 'badge-low' : 'badge-ok';
                        $status_label = $is_low ? 'Low Stock' : 'Available';
                        $status_icon  = $is_low ? 'fa-triangle-exclamation' : 'fa-circle-check';
                        $category_key = strtolower($item['category']) === 'drinks' ? 'consumable' : strtolower($item['category']);
                        $display_category = $category_key === 'consumable' ? 'Consumable' : ucfirst($item['category']);
                    ?>
                    <tr data-name="<?= strtolower(htmlspecialchars($item['ingredient_name'])) ?>"
                        data-category="<?= htmlspecialchars($category_key) ?>"
                        data-status="<?= $is_low ? 'low' : 'ok' ?>">
                        <td>
                            <span class="item-name"><?= htmlspecialchars($item['ingredient_name']) ?></span>
                            <div class="item-unit"><?= htmlspecialchars($item['unit']) ?></div>
                        </td>
                        <td><span class="badge badge-cat"><?= htmlspecialchars($display_category) ?></span></td>
                        <td>
                            <div class="qty-value <?= $is_low ? 'qty-low' : 'qty-ok' ?>">
                                <?= number_format($qty, 2) ?>
                            </div>
                            <div class="stock-bar-wrap" style="margin-top:5px;">
                                <div class="stock-bar">
                                    <div class="stock-bar-fill <?= $fill_class ?>" style="width:<?= $pct ?>%"></div>
                                </div>
                            </div>
                        </td>
                        <td style="color:var(--brown-mid); font-size:.85rem; font-weight:600;">
                            <?= number_format($min, 2) ?>
                        </td>
                        <td><span class="badge <?= $status_class ?>"><i class="fa-solid <?= $status_icon ?>"></i> <?= $status_label ?></span></td>
                        <td>
                            <div class="actions">
                                <button class="btn-icon btn-edit-icon" title="Update stock"
                                    onclick="openEdit(<?= intval($item['id']) ?>, <?= $qty ?>, <?= $min ?>, '<?= addslashes(htmlspecialchars($item['ingredient_name'])) ?>')">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </button>
                                <a href="admin_inventory.php?delete=<?= intval($item['id']) ?>"
                                   class="btn-icon btn-delete-icon" title="Delete item"
                                   onclick="return confirmDelete('<?= addslashes(htmlspecialchars($item['ingredient_name'])) ?>')">
                                    <i class="fa-solid fa-trash-can"></i>
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <div id="noResults">
                    <i class="fa-solid fa-filter" style="margin-right:6px;"></i>No items match your filters.
                    <button onclick="clearFilters()" style="margin-left:10px; background:none; border:none; color:var(--red); cursor:pointer; font-weight:700;">Clear filters</button>
                </div>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon"><i class="fa-solid fa-box-open"></i></div>
                <p>No inventory items yet.<br>Add your first item using the form.</p>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /main-layout -->
</div><!-- /page-wrapper -->

<!-- EDIT MODAL -->
<div class="modal-backdrop" id="editModal" role="dialog" aria-modal="true">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fa-solid fa-pen-to-square" style="margin-right:7px;"></i>Update Stock</h3>
            <button class="modal-close" onclick="closeEdit()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <form method="POST" id="editForm">
                <input type="hidden" id="editId" name="ingredient_id">
                <div class="modal-item-name" id="editItemName">
                    <i class="fa-solid fa-cube" style="color:var(--red);"></i>
                    <span id="editItemNameText">Item</span>
                </div>
                <div class="modal-field">
                    <label>New Quantity</label>
                    <input type="number" id="editQty" name="new_quantity" min="0" step="0.01" required>
                </div>
                <div class="modal-field">
                    <label>Low Stock Alert Level</label>
                    <input type="number" id="editMin" name="min_stock_level" min="0" step="0.01" required>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-modal-cancel" onclick="closeEdit()">Cancel</button>
            <button type="button" class="btn-modal-save" onclick="document.getElementById('editForm').querySelector('[name=update_inventory]').click()">
                <i class="fa-solid fa-floppy-disk"></i> Save Changes
                <button name="update_inventory" form="editForm" style="display:none;"></button>
            </button>
        </div>
    </div>
</div>

<script>
// ── Toast auto-dismiss ──
document.querySelectorAll('.toast').forEach(t => {
    setTimeout(() => dismissToast(t.querySelector('.toast-close')), 4500);
});
function dismissToast(btn) {
    const t = btn.closest('.toast');
    if (!t) return;
    t.style.animation = 'slideOut .3s ease forwards';
    setTimeout(() => t.remove(), 300);
}

// ── Modal ──
function openEdit(id, qty, min, name) {
    document.getElementById('editId').value = id;
    document.getElementById('editQty').value = qty;
    document.getElementById('editMin').value = min;
    document.getElementById('editItemNameText').textContent = name;
    document.getElementById('editModal').classList.add('open');
    document.getElementById('editQty').focus();
}
function closeEdit() {
    document.getElementById('editModal').classList.remove('open');
}
document.getElementById('editModal').addEventListener('click', function(e) {
    if (e.target === this) closeEdit();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeEdit(); });

// ── Delete confirm ──
function confirmDelete(name) {
    return confirm('Are you sure you want to delete "' + name + '"?\nThis action cannot be undone.');
}

// ── Table filter ──
function filterTable() {
    const search   = document.getElementById('searchInput').value.toLowerCase().trim();
    const category = document.getElementById('categoryFilter').value.toLowerCase();
    const status   = document.getElementById('statusFilter').value;

    const rows = document.querySelectorAll('#inventoryTable tbody tr');
    let visible = 0;

    rows.forEach(row => {
        const name     = row.dataset.name     || '';
        const rowCat   = row.dataset.category || '';
        const rowStat  = row.dataset.status   || '';

        const matchSearch   = !search   || name.includes(search);
        const matchCategory = !category || rowCat === category;
        const matchStatus   = !status   || rowStat === status;

        if (matchSearch && matchCategory && matchStatus) {
            row.style.display = '';
            visible++;
        } else {
            row.style.display = 'none';
        }
    });

    document.getElementById('noResults').style.display = visible === 0 ? 'block' : 'none';
}
function clearFilters() {
    document.getElementById('searchInput').value = '';
    document.getElementById('categoryFilter').value = '';
    document.getElementById('statusFilter').value = '';
    filterTable();
}

// ── Form validation ──
document.getElementById('addForm').addEventListener('submit', function(e) {
    const name = this.querySelector('[name=ingredient_name]').value.trim();
    const cat  = this.querySelector('[name=category]').value;
    const unit = this.querySelector('[name=unit]').value;
    if (!name || !cat || !unit) {
        e.preventDefault();
        alert('Please fill in the Item Name, Category, and Unit fields.');
    }
});
</script>

<?php include 'admin_chatbot.php'; ?>
<?php include 'admin_footer.php'; ?>
</body>
</html>