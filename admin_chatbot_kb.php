<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'config.php';

$admin_id = $_SESSION['admin_id'] ?? null;
if (!$admin_id) {
    header('location:login.php');
    exit;
}

// Add knowledge entry
if (isset($_POST['add_knowledge'])) {
    $keywords = mysqli_real_escape_string($conn, $_POST['keywords']);
    $response = mysqli_real_escape_string($conn, $_POST['response']);
    $response_type = mysqli_real_escape_string($conn, $_POST['response_type']);
    $priority = max(1, min(10, intval($_POST['priority'])));

    mysqli_query($conn, "INSERT INTO chatbot_knowledge (keywords, response, response_type, priority) 
                        VALUES ('$keywords', '$response', '$response_type', $priority)");

    include 'admin_log_activity.php';
    log_admin_activity($admin_id, 'Add Chatbot Knowledge', 'Added KB entry: ' . substr($_POST['keywords'], 0, 30));

    header('location:admin_chatbot_kb.php?msg=added');
    exit;
}

// Update knowledge entry
if (isset($_POST['update_knowledge'])) {
    $id = intval($_POST['kb_id']);
    $keywords = mysqli_real_escape_string($conn, $_POST['keywords']);
    $response = mysqli_real_escape_string($conn, $_POST['response']);
    $response_type = mysqli_real_escape_string($conn, $_POST['response_type']);
    $priority = max(1, min(10, intval($_POST['priority'])));
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    mysqli_query($conn, "UPDATE chatbot_knowledge SET keywords='$keywords', response='$response', 
                        response_type='$response_type', priority=$priority, is_active=$is_active WHERE id=$id");

    include 'admin_log_activity.php';
    log_admin_activity($admin_id, 'Update Chatbot Knowledge', 'Updated KB entry: ' . substr($_POST['keywords'], 0, 30));

    header('location:admin_chatbot_kb.php?msg=updated');
    exit;
}

// Delete knowledge entry
if (isset($_GET['delete'])) {
    $id = intval($_GET['delete']);

    $kb_res = mysqli_query($conn, "SELECT keywords FROM chatbot_knowledge WHERE id=$id");
    $kb = $kb_res ? mysqli_fetch_assoc($kb_res) : null;

    mysqli_query($conn, "DELETE FROM chatbot_knowledge WHERE id=$id");

    include 'admin_log_activity.php';
    log_admin_activity($admin_id, 'Delete Chatbot Knowledge', 'Deleted KB entry: ' . substr($kb['keywords'] ?? '', 0, 30));

    header('location:admin_chatbot_kb.php?msg=deleted');
    exit;
}

// Get all knowledge entries
$kb_list = mysqli_query($conn, "SELECT * FROM chatbot_knowledge ORDER BY priority DESC, created_at DESC");
$total_entries = mysqli_num_rows($kb_list);
$active_entries = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM chatbot_knowledge WHERE is_active = 1"));
$inactive_entries = $total_entries - $active_entries;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Chatbot Knowledge Base — Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" />
    <link rel="icon" type="image/png" href="images/logos.png">
    <style>
        /* =====================================================
           Everything below is prefixed with "kb-" and scoped so it
           can NOT affect (or be affected by) admin_header.php
           ===================================================== */
        :root {
            --kb-border: #F0E6D8;
            --kb-red-dark: #B83A34;
            --kb-green: #2E7D32;
            --kb-blue: #0369a1;
            --kb-shadow: 0 8px 24px rgba(94, 31, 19, 0.08);
            --kb-shadow-lg: 0 20px 50px rgba(94, 31, 19, 0.25);
            --kb-radius: 16px;
            --kb-transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body {
            background: linear-gradient(135deg, #FFFAF5 0%, #FFF2E0 100%);
            color: #5E1F13;
            min-height: 100vh;
        }

        .kb-page,
        .kb-page *,
        .kb-modal-overlay,
        .kb-modal-overlay * {
            box-sizing: border-box;
            font-family: 'Montserrat', system-ui, -apple-system, "Segoe UI", Roboto, Arial;
        }

        .kb-page {
            max-width: 1200px;
            margin: 32px auto;
            padding: 0 20px 60px;
            color: #5E1F13;
        }

        .kb-page h1, .kb-page h3, .kb-page p,
        .kb-modal-overlay h2 { margin: 0; }

        /* ---------- Header ---------- */
        .kb-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
            margin-bottom: 26px;
        }

        .kb-header h1 {
            font-size: 2rem;
            font-weight: 900;
            letter-spacing: -0.5px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #5E1F13;
        }

        .kb-title-icon {
            width: 46px;
            height: 46px;
            border-radius: 14px;
            background: linear-gradient(135deg, #C6453E, var(--kb-red-dark));
            color: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            box-shadow: 0 6px 16px rgba(198, 69, 62, 0.3);
            flex-shrink: 0;
        }

        .kb-sub {
            color: #664C47;
            font-weight: 500;
            margin-top: 6px;
            font-size: 0.98rem;
        }

        /* ---------- Buttons ---------- */
        .kb-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 12px 22px;
            background: linear-gradient(135deg, #C6453E 0%, var(--kb-red-dark) 100%);
            color: #fff;
            border: none;
            border-radius: 12px;
            font-weight: 800;
            font-size: 0.92rem;
            cursor: pointer;
            text-decoration: none;
            transition: var(--kb-transition);
            box-shadow: 0 4px 12px rgba(198, 69, 62, 0.22);
        }

        .kb-btn:hover {
            background: linear-gradient(135deg, #5E1F13 0%, #3D1608 100%);
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(94, 31, 19, 0.25);
        }

        .kb-btn:active { transform: scale(0.98); }

        .kb-btn-secondary {
            background: #fff;
            color: #5E1F13;
            border: 1.5px solid var(--kb-border);
            box-shadow: none;
        }

        .kb-btn-secondary:hover {
            background: #FFF2E0;
            color: #5E1F13;
            border-color: #C6453E;
            box-shadow: none;
        }

        /* ---------- Toast ---------- */
        .kb-toast {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 18px;
            border-radius: 12px;
            margin-bottom: 20px;
            background: rgba(76, 175, 80, 0.12);
            border: 1px solid rgba(76, 175, 80, 0.3);
            color: var(--kb-green);
            font-weight: 700;
            font-size: 0.93rem;
            animation: kbSlideDown 0.3s ease-out;
            transition: opacity 0.4s, transform 0.4s;
        }

        .kb-toast.kb-hide { opacity: 0; transform: translateY(-8px); }

        @keyframes kbSlideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ---------- Stats ---------- */
        .kb-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }

        .kb-stat {
            background: #fff;
            border: 1.5px solid var(--kb-border);
            border-radius: 14px;
            padding: 16px 18px;
            display: flex;
            align-items: center;
            gap: 14px;
            transition: var(--kb-transition);
        }

        .kb-stat:hover {
            border-color: #C6453E;
            box-shadow: var(--kb-shadow);
            transform: translateY(-2px);
        }

        .kb-stat-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }

        .kb-stat-icon.total    { background: rgba(198, 69, 62, 0.1);    color: #C6453E; }
        .kb-stat-icon.active   { background: rgba(76, 175, 80, 0.12);   color: var(--kb-green); }
        .kb-stat-icon.inactive { background: rgba(160, 130, 109, 0.14); color: #664C47; }

        .kb-stat-label {
            font-size: 0.75rem;
            color: #664C47;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .kb-stat-value {
            font-size: 1.7rem;
            font-weight: 900;
            line-height: 1.1;
            margin-top: 2px;
        }

        /* ---------- Main Card ---------- */
        .kb-card {
            background: #fff;
            border: 1.5px solid var(--kb-border);
            border-radius: var(--kb-radius);
            box-shadow: var(--kb-shadow);
            overflow: hidden;
        }

        .kb-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
            padding: 18px 22px;
            border-bottom: 1.5px solid var(--kb-border);
            background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
        }

        .kb-search {
            position: relative;
            flex: 1;
            min-width: 220px;
            max-width: 420px;
        }

        .kb-search i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #664C47;
            font-size: 0.9rem;
        }

        .kb-search input {
            width: 100%;
            padding: 11px 14px 11px 40px;
            border: 1.5px solid var(--kb-border);
            border-radius: 12px;
            font-size: 0.93rem;
            background: #fff;
            transition: var(--kb-transition);
            color: #5E1F13;
        }

        .kb-search input:focus {
            outline: none;
            border-color: #C6453E;
            box-shadow: 0 0 0 3px rgba(198, 69, 62, 0.12);
        }

        .kb-filters {
            display: flex;
            gap: 6px;
            background: #FFF2E0;
            padding: 4px;
            border-radius: 12px;
        }

        .kb-filter-btn {
            border: none;
            background: transparent;
            padding: 8px 14px;
            border-radius: 9px;
            font-weight: 700;
            font-size: 0.82rem;
            color: #664C47;
            cursor: pointer;
            transition: var(--kb-transition);
        }

        .kb-filter-btn:hover { color: #5E1F13; }

        .kb-filter-btn.active {
            background: #fff;
            color: #C6453E;
            box-shadow: 0 2px 6px rgba(94, 31, 19, 0.1);
        }

        /* ---------- Table ---------- */
        .kb-table-wrap { width: 100%; overflow-x: auto; }

        .kb-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.93rem;
        }

        .kb-table thead th {
            background: #FFFAF5;
            color: #664C47;
            padding: 13px 18px;
            text-align: left;
            font-weight: 800;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            border-bottom: 1.5px solid var(--kb-border);
            white-space: nowrap;
        }

        .kb-table tbody td {
            padding: 16px 18px;
            border-bottom: 1px solid var(--kb-border);
            vertical-align: middle;
            color: #5E1F13;
        }

        .kb-table tbody tr { transition: background 0.2s; }
        .kb-table tbody tr:hover { background: #FFFAF5; }
        .kb-table tbody tr:last-child td { border-bottom: none; }

        .kb-col-keywords { width: 28%; }
        .kb-col-response { width: 32%; }
        .kb-col-type     { width: 10%; }
        .kb-col-priority { width: 10%; }
        .kb-col-status   { width: 9%; }
        .kb-table thead th.kb-col-actions { width: 11%; text-align: right; }

        .kb-chips { display: flex; flex-wrap: wrap; gap: 6px; }

        .kb-chip {
            background: #FFF2E0;
            color: #5E1F13;
            border: 1px solid var(--kb-border);
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .kb-chip.more {
            background: rgba(198, 69, 62, 0.1);
            color: #C6453E;
            border-color: rgba(198, 69, 62, 0.2);
        }

        .kb-response-text {
            color: #6b5a56;
            font-size: 0.9rem;
            line-height: 1.5;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }

        .kb-type-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.78rem;
            font-weight: 700;
            color: #664C47;
            white-space: nowrap;
        }

        .kb-priority { display: flex; flex-direction: column; gap: 5px; min-width: 70px; }
        .kb-priority-num { font-weight: 800; font-size: 0.88rem; }
        .kb-priority-num span { color: #664C47; font-weight: 600; font-size: 0.78rem; }

        .kb-priority-bar {
            height: 6px;
            background: #FFF2E0;
            border-radius: 999px;
            overflow: hidden;
        }

        .kb-priority-bar div {
            height: 100%;
            background: linear-gradient(90deg, #E8A19C, #C6453E);
            border-radius: 999px;
        }

        .kb-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .kb-badge::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: currentColor;
        }

        .kb-badge.active   { background: rgba(76, 175, 80, 0.12);   color: var(--kb-green); }
        .kb-badge.inactive { background: rgba(160, 130, 109, 0.15); color: #664C47; }

        .kb-actions { display: flex; gap: 8px; justify-content: flex-end; }

        .kb-icon-btn {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            border: 1.5px solid var(--kb-border);
            background: #fff;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            text-decoration: none;
            font-size: 0.85rem;
            padding: 0;
            transition: var(--kb-transition);
        }

        .kb-icon-btn.edit   { color: var(--kb-blue); }
        .kb-icon-btn.delete { color: #D97E6A; }

        .kb-icon-btn.edit:hover   { background: var(--kb-blue); color: #fff; border-color: var(--kb-blue); }
        .kb-icon-btn.delete:hover { background: #D97E6A; color: #fff; border-color: #D97E6A; }

        .kb-empty, .kb-no-results {
            text-align: center;
            padding: 56px 24px;
            color: #664C47;
        }

        .kb-empty i, .kb-no-results i {
            font-size: 3rem;
            color: #C6453E;
            opacity: 0.45;
            margin-bottom: 14px;
        }

        .kb-empty h3, .kb-no-results h3 { font-size: 1.1rem; color: #5E1F13; margin-bottom: 6px; }
        .kb-empty p, .kb-no-results p { font-weight: 500; font-size: 0.92rem; margin-bottom: 18px; }
        .kb-no-results { display: none; }

        /* ---------- Modal ---------- */
        .kb-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(61, 22, 8, 0.55);
            backdrop-filter: blur(3px);
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            z-index: 2000;
        }

        .kb-modal-overlay.open { display: flex; animation: kbFadeIn 0.2s ease; }

        @keyframes kbFadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes kbPopIn  { from { opacity: 0; transform: translateY(16px) scale(0.97); } to { opacity: 1; transform: none; } }

        .kb-modal {
            background: #fff;
            width: 100%;
            max-width: 560px;
            max-height: 92vh;
            overflow-y: auto;
            border-radius: 20px;
            box-shadow: var(--kb-shadow-lg);
            animation: kbPopIn 0.25s ease;
            color: #5E1F13;
        }

        .kb-modal-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 22px 26px;
            border-bottom: 1.5px solid var(--kb-border);
            position: sticky;
            top: 0;
            background: #fff;
            z-index: 1;
        }

        .kb-modal-head h2 {
            font-size: 1.2rem;
            font-weight: 900;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .kb-modal-head h2 i { color: #C6453E; }

        .kb-close {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            border: none;
            background: #FFF2E0;
            color: #5E1F13;
            cursor: pointer;
            font-size: 1rem;
            transition: var(--kb-transition);
        }

        .kb-close:hover { background: #C6453E; color: #fff; }

        .kb-modal-body { padding: 24px 26px 8px; }

        .kb-group { margin-bottom: 18px; }

        .kb-row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

        .kb-modal label.kb-label {
            display: block;
            font-weight: 700;
            margin-bottom: 7px;
            font-size: 0.88rem;
            color: #5E1F13;
        }

        .kb-label i { color: #C6453E; margin-right: 4px; width: 14px; text-align: center; }

        .kb-input {
            width: 100%;
            padding: 12px 14px;
            border: 1.5px solid var(--kb-border);
            border-radius: 12px;
            font-size: 0.93rem;
            background: #fff;
            color: #5E1F13;
            transition: var(--kb-transition);
        }

        .kb-input:focus {
            outline: none;
            border-color: #C6453E;
            box-shadow: 0 0 0 3px rgba(198, 69, 62, 0.12);
        }

        textarea.kb-input { min-height: 120px; resize: vertical; line-height: 1.5; }

        .kb-hint {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            margin-top: 6px;
            color: #664C47;
            font-weight: 500;
            font-size: 0.78rem;
        }

        .kb-toggle-row {
            display: none;
            align-items: center;
            justify-content: space-between;
            padding: 14px 16px;
            background: #FFFAF5;
            border: 1.5px solid var(--kb-border);
            border-radius: 12px;
            margin-bottom: 18px;
        }

        .kb-toggle-row.show { display: flex; }

        .kb-t-title { font-weight: 800; font-size: 0.9rem; }
        .kb-t-sub { font-size: 0.78rem; color: #664C47; font-weight: 500; margin-top: 2px; }

        .kb-switch { position: relative; width: 48px; height: 26px; flex-shrink: 0; display: inline-block; }
        .kb-switch input { opacity: 0; width: 0; height: 0; position: absolute; }

        .kb-slider {
            position: absolute;
            inset: 0;
            background: #D8CFC6;
            border-radius: 999px;
            cursor: pointer;
            transition: var(--kb-transition);
        }

        .kb-slider::before {
            content: "";
            position: absolute;
            width: 20px;
            height: 20px;
            left: 3px;
            top: 3px;
            background: #fff;
            border-radius: 50%;
            transition: var(--kb-transition);
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.2);
        }

        .kb-switch input:checked + .kb-slider { background: #C6453E; }
        .kb-switch input:checked + .kb-slider::before { transform: translateX(22px); }

        .kb-modal-foot {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            padding: 18px 26px 24px;
        }

        /* ---------- Responsive ---------- */
        @media (max-width: 900px) {
            .kb-stats { grid-template-columns: 1fr; }
            .kb-col-type, .kb-cell-type { display: none; }
        }

        @media (max-width: 720px) {
            .kb-page { padding: 0 14px 48px; margin-top: 20px; }
            .kb-header h1 { font-size: 1.5rem; }
            .kb-header .kb-btn { width: 100%; }
            .kb-toolbar { padding: 14px; }
            .kb-search { max-width: none; }
            .kb-filters { width: 100%; }
            .kb-filter-btn { flex: 1; padding: 8px 6px; }

            .kb-table thead { display: none; }
            .kb-table, .kb-table tbody, .kb-table tr, .kb-table td { display: block; width: 100%; }

            .kb-table tbody tr {
                padding: 14px 16px;
                border-bottom: 1px solid var(--kb-border);
            }

            .kb-table tbody td {
                padding: 6px 0;
                border: none;
            }

            .kb-table td.kb-cell-type { display: block; }

            .kb-table tbody td[data-label]::before {
                content: attr(data-label);
                display: block;
                font-size: 0.68rem;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: 0.6px;
                color: #664C47;
                margin-bottom: 4px;
            }

            .kb-actions { justify-content: flex-start; margin-top: 6px; }
            .kb-row-2 { grid-template-columns: 1fr; }
            .kb-modal-foot .kb-btn { flex: 1; }
        }
    </style>
</head>
<body>

<?php include 'admin_header.php'; ?>

<div class="kb-page">

    <!-- Header -->
    <div class="kb-header">
        <div>
            <h1><span class="kb-title-icon"><i class="fa-solid fa-robot"></i></span> Chatbot Knowledge Base</h1>
            <div class="kb-sub">Train your chatbot by managing FAQ entries and responses</div>
        </div>
        <button type="button" class="kb-btn" onclick="kbOpenModal()">
            <i class="fa-solid fa-plus"></i> Add New Entry
        </button>
    </div>

    <!-- Toast -->
    <?php if (isset($_GET['msg'])): ?>
        <?php
            $toast = '';
            if ($_GET['msg'] == 'added')       $toast = 'Knowledge entry added successfully!';
            elseif ($_GET['msg'] == 'updated') $toast = 'Knowledge entry updated successfully!';
            elseif ($_GET['msg'] == 'deleted') $toast = 'Knowledge entry deleted successfully!';
        ?>
        <?php if ($toast): ?>
            <div class="kb-toast" id="kbToast">
                <i class="fa-solid fa-circle-check"></i> <?php echo $toast; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Stats -->
    <div class="kb-stats">
        <div class="kb-stat">
            <div class="kb-stat-icon total"><i class="fa-solid fa-database"></i></div>
            <div>
                <div class="kb-stat-label">Total Entries</div>
                <div class="kb-stat-value"><?php echo $total_entries; ?></div>
            </div>
        </div>
        <div class="kb-stat">
            <div class="kb-stat-icon active"><i class="fa-solid fa-circle-check"></i></div>
            <div>
                <div class="kb-stat-label">Active</div>
                <div class="kb-stat-value"><?php echo $active_entries; ?></div>
            </div>
        </div>
        <div class="kb-stat">
            <div class="kb-stat-icon inactive"><i class="fa-solid fa-circle-pause"></i></div>
            <div>
                <div class="kb-stat-label">Inactive</div>
                <div class="kb-stat-value"><?php echo $inactive_entries; ?></div>
            </div>
        </div>
    </div>

    <!-- Entries -->
    <div class="kb-card">
        <?php if ($total_entries > 0): ?>

            <div class="kb-toolbar">
                <div class="kb-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="kbSearch" placeholder="Search keywords or responses..." oninput="kbApplyFilters()">
                </div>
                <div class="kb-filters">
                    <button type="button" class="kb-filter-btn active" onclick="kbSetFilter('all', this)">All</button>
                    <button type="button" class="kb-filter-btn" onclick="kbSetFilter('active', this)">Active</button>
                    <button type="button" class="kb-filter-btn" onclick="kbSetFilter('inactive', this)">Inactive</button>
                </div>
            </div>

            <div class="kb-table-wrap">
                <table class="kb-table" id="kbTable">
                    <thead>
                        <tr>
                            <th class="kb-col-keywords">Keywords</th>
                            <th class="kb-col-response">Response</th>
                            <th class="kb-col-type">Type</th>
                            <th class="kb-col-priority">Priority</th>
                            <th class="kb-col-status">Status</th>
                            <th class="kb-col-actions">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($kb = mysqli_fetch_assoc($kb_list)): ?>
                            <?php
                                $keyword_arr = array_filter(array_map('trim', explode(',', $kb['keywords'])));
                                $shown = array_slice($keyword_arr, 0, 4);
                                $extra = count($keyword_arr) - count($shown);
                                $priority = intval($kb['priority']);
                                $is_active = !empty($kb['is_active']);
                            ?>
                            <tr class="kb-row" data-status="<?php echo $is_active ? 'active' : 'inactive'; ?>">
                                <td data-label="Keywords">
                                    <div class="kb-chips kb-search-kw" title="<?php echo htmlspecialchars($kb['keywords']); ?>">
                                        <?php foreach ($shown as $kw): ?>
                                            <span class="kb-chip"><?php echo htmlspecialchars($kw); ?></span>
                                        <?php endforeach; ?>
                                        <?php if ($extra > 0): ?>
                                            <span class="kb-chip more">+<?php echo $extra; ?> more</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td data-label="Response">
                                    <div class="kb-response-text" title="<?php echo htmlspecialchars($kb['response']); ?>">
                                        <?php echo htmlspecialchars($kb['response']); ?>
                                    </div>
                                </td>
                                <td data-label="Type" class="kb-cell-type">
                                    <span class="kb-type-tag">
                                        <i class="fa-solid fa-<?php echo $kb['response_type'] === 'suggestion' ? 'list-ul' : 'comment-dots'; ?>"></i>
                                        <?php echo $kb['response_type'] === 'suggestion' ? 'Suggestion' : 'Text'; ?>
                                    </span>
                                </td>
                                <td data-label="Priority">
                                    <div class="kb-priority">
                                        <div class="kb-priority-num"><?php echo $priority; ?><span>/10</span></div>
                                        <div class="kb-priority-bar"><div style="width: <?php echo max(0, min(100, $priority * 10)); ?>%"></div></div>
                                    </div>
                                </td>
                                <td data-label="Status">
                                    <span class="kb-badge <?php echo $is_active ? 'active' : 'inactive'; ?>">
                                        <?php echo $is_active ? 'Active' : 'Inactive'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="kb-actions">
                                        <button type="button" class="kb-icon-btn edit" title="Edit entry"
                                            data-id="<?php echo intval($kb['id']); ?>"
                                            data-keywords="<?php echo htmlspecialchars($kb['keywords'], ENT_QUOTES); ?>"
                                            data-response="<?php echo htmlspecialchars($kb['response'], ENT_QUOTES); ?>"
                                            data-type="<?php echo htmlspecialchars($kb['response_type'], ENT_QUOTES); ?>"
                                            data-priority="<?php echo $priority; ?>"
                                            data-active="<?php echo $is_active ? 1 : 0; ?>"
                                            onclick="kbEditEntry(this)">
                                            <i class="fa-solid fa-pen"></i>
                                        </button>
                                        <a href="admin_chatbot_kb.php?delete=<?php echo intval($kb['id']); ?>" class="kb-icon-btn delete" title="Delete entry"
                                           onclick="return confirm('Are you sure you want to delete this entry? This action cannot be undone.');">
                                            <i class="fa-solid fa-trash"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>

                <div class="kb-no-results" id="kbNoResults">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <h3>No matching entries</h3>
                    <p>Try a different search word or filter.</p>
                </div>
            </div>

        <?php else: ?>
            <div class="kb-empty">
                <i class="fa-solid fa-inbox"></i>
                <h3>No knowledge base entries yet</h3>
                <p>Create your first entry to start training the chatbot.</p>
                <button type="button" class="kb-btn" onclick="kbOpenModal()"><i class="fa-solid fa-plus"></i> Add First Entry</button>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add / Edit Modal -->
<div class="kb-modal-overlay" id="kbModal" onclick="if(event.target === this) kbCloseModal()">
    <div class="kb-modal" role="dialog" aria-modal="true" aria-labelledby="kbModalTitle">
        <div class="kb-modal-head">
            <h2 id="kbModalTitle"><i class="fa-solid fa-circle-plus"></i> <span id="kbModalTitleText">Add New Entry</span></h2>
            <button type="button" class="kb-close" onclick="kbCloseModal()" aria-label="Close"><i class="fa-solid fa-xmark"></i></button>
        </div>

        <form method="POST" id="kbForm">
            <div class="kb-modal-body">
                <div class="kb-group">
                    <label class="kb-label" for="kbKeywords"><i class="fa-solid fa-key"></i> Keywords</label>
                    <input type="text" class="kb-input" name="keywords" id="kbKeywords" placeholder="e.g., hello, hi, greetings" required>
                    <div class="kb-hint"><span>Separate keywords with commas. The reply triggers when a user uses any of them.</span></div>
                </div>

                <div class="kb-group">
                    <label class="kb-label" for="kbResponse"><i class="fa-solid fa-message"></i> Response Message</label>
                    <textarea class="kb-input" name="response" id="kbResponse" placeholder="Enter the chatbot response..." required></textarea>
                    <div class="kb-hint"><span>Keep it friendly and helpful!</span><span id="kbCharCount">0 characters</span></div>
                </div>

                <div class="kb-row-2">
                    <div class="kb-group">
                        <label class="kb-label" for="kbType"><i class="fa-solid fa-layer-group"></i> Response Type</label>
                        <select class="kb-input" name="response_type" id="kbType" required>
                            <option value="text">Text Message</option>
                            <option value="suggestion">Suggestion / Options</option>
                        </select>
                    </div>
                    <div class="kb-group">
                        <label class="kb-label" for="kbPriority"><i class="fa-solid fa-star"></i> Priority (1-10)</label>
                        <input type="number" class="kb-input" name="priority" id="kbPriority" min="1" max="10" value="5" required>
                    </div>
                </div>
                <div class="kb-hint" style="margin-top: -8px; margin-bottom: 18px;">
                    <span>Higher priority = more likely to match. Use 8-10 for important responses.</span>
                </div>

                <div class="kb-toggle-row" id="kbActiveRow">
                    <div>
                        <div class="kb-t-title">Entry is active</div>
                        <div class="kb-t-sub">Inactive entries are ignored by the chatbot.</div>
                    </div>
                    <label class="kb-switch">
                        <input type="checkbox" name="is_active" id="kbIsActive" checked>
                        <span class="kb-slider"></span>
                    </label>
                </div>
            </div>

            <div class="kb-modal-foot">
                <button type="button" class="kb-btn kb-btn-secondary" onclick="kbCloseModal()">Cancel</button>
                <button type="submit" name="add_knowledge" id="kbSubmitBtn" class="kb-btn">
                    <i class="fa-solid fa-plus"></i> <span id="kbSubmitText">Add Entry</span>
                </button>
            </div>
        </form>
    </div>
</div>

<?php include 'admin_footer.php'; ?>

<script>
const kbModalEl   = document.getElementById('kbModal');
const kbFormEl    = document.getElementById('kbForm');
const kbSubmitEl  = document.getElementById('kbSubmitBtn');
const kbResponseEl = document.getElementById('kbResponse');
let kbCurrentFilter = 'all';

function kbUpdateCharCount() {
    document.getElementById('kbCharCount').textContent = kbResponseEl.value.length + ' characters';
}
kbResponseEl.addEventListener('input', kbUpdateCharCount);

function kbSetMode(editing) {
    document.getElementById('kbModalTitleText').textContent = editing ? 'Edit Entry' : 'Add New Entry';
    document.querySelector('#kbModalTitle i').className = editing ? 'fa-solid fa-pen' : 'fa-solid fa-circle-plus';
    document.getElementById('kbSubmitText').textContent = editing ? 'Save Changes' : 'Add Entry';
    kbSubmitEl.querySelector('i').className = editing ? 'fa-solid fa-floppy-disk' : 'fa-solid fa-plus';
    kbSubmitEl.setAttribute('name', editing ? 'update_knowledge' : 'add_knowledge');
    document.getElementById('kbActiveRow').classList.toggle('show', editing);

    const hidden = kbFormEl.querySelector('input[name="kb_id"]');
    if (!editing && hidden) hidden.remove();
}

function kbOpenModal() {
    kbFormEl.reset();
    kbSetMode(false);
    kbUpdateCharCount();
    kbModalEl.classList.add('open');
    document.body.style.overflow = 'hidden';
    setTimeout(() => document.getElementById('kbKeywords').focus(), 50);
}

function kbCloseModal() {
    kbModalEl.classList.remove('open');
    document.body.style.overflow = '';
}

function kbEditEntry(btn) {
    const d = btn.dataset;
    kbFormEl.reset();
    kbSetMode(true);

    document.getElementById('kbKeywords').value = d.keywords;
    kbResponseEl.value = d.response;
    document.getElementById('kbType').value = d.type;
    document.getElementById('kbPriority').value = d.priority;
    document.getElementById('kbIsActive').checked = d.active === '1';

    let hidden = kbFormEl.querySelector('input[name="kb_id"]');
    if (!hidden) {
        hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'kb_id';
        kbFormEl.appendChild(hidden);
    }
    hidden.value = d.id;

    kbUpdateCharCount();
    kbModalEl.classList.add('open');
    document.body.style.overflow = 'hidden';
}

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && kbModalEl.classList.contains('open')) kbCloseModal();
});

function kbSetFilter(filter, btn) {
    kbCurrentFilter = filter;
    document.querySelectorAll('.kb-filter-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    kbApplyFilters();
}

function kbApplyFilters() {
    const searchEl = document.getElementById('kbSearch');
    if (!searchEl) return;

    const q = searchEl.value.toLowerCase().trim();
    let visible = 0;

    document.querySelectorAll('.kb-row').forEach(row => {
        const text = (row.querySelector('.kb-search-kw').getAttribute('title') + ' ' +
                      row.querySelector('.kb-response-text').getAttribute('title')).toLowerCase();
        const matchSearch = text.includes(q);
        const matchStatus = kbCurrentFilter === 'all' || row.dataset.status === kbCurrentFilter;

        if (matchSearch && matchStatus) {
            row.style.display = '';
            visible++;
        } else {
            row.style.display = 'none';
        }
    });

    document.getElementById('kbNoResults').style.display = visible === 0 ? 'block' : 'none';
}

// Auto-hide the success message
const kbToastEl = document.getElementById('kbToast');
if (kbToastEl) {
    setTimeout(() => kbToastEl.classList.add('kb-hide'), 3500);
    setTimeout(() => kbToastEl.remove(), 4000);
}

// Clean ?msg= from the URL so a refresh doesn't show the message again
if (window.location.search.includes('msg=')) {
    window.history.replaceState({}, '', window.location.pathname);
}
</script>

</body>
</html>