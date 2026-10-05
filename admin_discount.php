<?php
/**
 * SIX ORIGINS CAFE - DISCOUNT MANAGEMENT SYSTEM
 * Version: 3.1.0
 * Theme: Premium Dark Brown & Crimson Coffee
 */

include 'config.php';

// Admin Authentication Check
$admin_id = $_SESSION['admin_id'] ?? null;
if (!$admin_id) {
    header('location:login.php');
    exit;
}

// Enable Strict Error Reporting for Debugging
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$message = [];

/**
 * LOGIC: DISCOUNT CODE GENERATION
 * Generates a unique 8-character MD5-based alphanumeric code
 */
if (isset($_POST['generate_discount'])) {
    $amount = intval($_POST['amount']);
    $valid_until = $_POST['valid_until'];

    // Input Validation
    if (!$amount || !$valid_until) {
        $message[] = ['type' => 'error', 'text' => 'All fields are mandatory for code generation.'];
    } else {
        // Business Logic: Generate highly unique promotional code
        $code = strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
        $status = 'active';

        try {
            $stmt = $conn->prepare("INSERT INTO `discounts` (`code`, `amount`, `status`, `valid_until`) VALUES (?, ?, ?, ?)");
            if (!$stmt) {
                throw new Exception("Database Preparation Error: " . $conn->error);
            }
            
            $stmt->bind_param("siss", $code, $amount, $status, $valid_until);
            $stmt->execute();
            $stmt->close();
            
            $message[] = [
                'type' => 'success', 
                'text' => "Promotion active! Code <strong>$code</strong> (Value: $amount%) has been stored."
            ];
        } catch (Exception $e) {
            $message[] = ['type' => 'error', 'text' => 'Transaction failed: ' . $e->getMessage()];
        }
    }
}

/**
 * LOGIC: STATUS AUTO-CLEANUP
 * Automatically marks codes as expired if today's date exceeds the valid_until date
 */
$today = date('Y-m-d');
mysqli_query($conn, "UPDATE `discounts` SET status = 'expired' WHERE valid_until < '$today' AND status = 'active'");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Discounts Manager — Six Origins Admin</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100..900;1,100..900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" />
    <link rel="icon" type="image/png" href="images/logos.png">
    <style>
        /**
         * CORE DESIGN TOKENS
         * Based on Six Origins Cafe Branding Guidelines
         */
        :root {
            /* Palette */
            --primary-red: #C6453E;
            --primary-crimson: #B83A34;
            --dark-brown: #5E1F13;
            --deep-coffee: #3D1608;
            --gray-brown: #664C47;
            --light-cream: #FFF2E0;
            --soft-cream: #FFF2E0; /* was referenced but never defined */
            --vanilla-white: #FFFAF5;
            --pure-white: #FFFFFF;
            
            /* UI States */
            --success-bg: #E8F5E9;
            --success-text: #2E7D32;
            --error-bg: #FFEBEE;
            --error-text: #C62828;
            --warning-bg: #FFF3E0;
            --warning-text: #E65100;

            /* Surface & Glassmorphism */
            --border-tan: #F0E6D8;
            --shadow-soft: 0 8px 30px rgba(94, 31, 19, 0.06);
            --shadow-heavy: 0 15px 45px rgba(94, 31, 19, 0.12);
            --glass-bg: rgba(255, 255, 255, 0.7);
            
            /* Sizing */
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 20px;
            --radius-xl: 30px;
            
            /* Motion */
            --transition-fast: 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            --transition-smooth: 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* RESET SYSTEM */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Montserrat', sans-serif;
            -webkit-font-smoothing: antialiased;
        }

        html {
            scroll-behavior: smooth;
            -webkit-text-size-adjust: 100%;
        }

        body {
            min-height: 100vh;
            background: linear-gradient(160deg, var(--vanilla-white) 0%, var(--light-cream) 100%);
            background-attachment: fixed;
            color: var(--dark-brown);
            overflow-x: hidden;
            display: flex;
            flex-direction: column;
        }

        /* LAYOUT COMPONENTS */
        .admin-main-wrapper {
            max-width: 1400px;
            margin: 0 auto;
            padding: clamp(16px, 4vw, 40px) clamp(12px, 3vw, 24px) clamp(48px, 8vw, 80px);
            width: 100%;
            flex: 1;
        }

        /* HEADER ANIMATIONS */
        @keyframes revealDown {
            from { opacity: 0; transform: translateY(-30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes revealUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        @keyframes floatEffect {
            0% { transform: translateY(0); }
            50% { transform: translateY(-5px); }
            100% { transform: translateY(0); }
        }

        /* TOP NAVIGATION SECTION */
        .dashboard-header {
            margin-bottom: clamp(20px, 4vw, 40px);
            animation: revealDown 0.6s var(--transition-smooth);
        }

        .nav-breadcrumb {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px 20px;
            background: var(--pure-white);
            padding: clamp(16px, 3vw, 20px) clamp(16px, 3vw, 30px);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-soft);
            border: 1px solid var(--border-tan);
        }

        .brand-path {
            min-width: 0;
        }

        .brand-path h1 {
            font-size: clamp(1.3rem, 3.6vw, 1.8rem);
            font-weight: 900;
            color: var(--dark-brown);
            display: flex;
            align-items: center;
            gap: 12px;
            line-height: 1.15;
        }

        .brand-path p {
            color: var(--gray-brown);
            font-size: clamp(0.8rem, 1.8vw, 0.9rem);
            font-weight: 600;
            margin-top: 4px;
        }

        .back-link {
            background: var(--primary-red);
            color: var(--pure-white);
            padding: 12px 24px;
            border-radius: var(--radius-md);
            text-decoration: none;
            font-weight: 800;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition-smooth);
            box-shadow: 0 4px 15px rgba(198, 69, 62, 0.2);
            white-space: nowrap;
        }

        .back-link:hover {
            background: var(--deep-coffee);
            transform: translateX(-5px);
            box-shadow: 0 6px 20px rgba(61, 22, 8, 0.3);
        }

        /* MESSAGE ALERTS */
        .alert-system {
            margin-bottom: 30px;
        }

        .toast-msg {
            padding: 18px 25px;
            border-radius: var(--radius-md);
            margin-bottom: 12px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 15px;
            border-left: 6px solid var(--primary-red);
            background: var(--pure-white);
            box-shadow: var(--shadow-soft);
            animation: revealUp 0.4s var(--transition-smooth);
            overflow-wrap: anywhere;
        }

        .toast-msg i { flex-shrink: 0; }

        .toast-msg.error { border-left-color: var(--error-text); color: var(--error-text); background: var(--error-bg); }
        .toast-msg.success { border-left-color: var(--success-text); color: var(--success-text); background: var(--success-bg); }

        /* GRID SYSTEM */
        .mgmt-grid {
            display: grid;
            grid-template-columns: minmax(320px, 400px) minmax(0, 1fr);
            gap: clamp(18px, 2.4vw, 30px);
            align-items: start;
        }

        .mgmt-grid > * { min-width: 0; }

        /* FORM COMPONENT */
        .control-panel {
            display: flex;
            flex-direction: column;
            gap: 25px;
            position: sticky;
            top: 30px;
        }

        .stat-card-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        .mini-stat {
            background: var(--pure-white);
            padding: 20px;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-tan);
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            transition: var(--transition-smooth);
            min-width: 0;
        }

        .mini-stat .icon-box {
            width: 50px;
            height: 50px;
            background: var(--light-cream);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-red);
            font-size: 1.2rem;
            margin-bottom: 10px;
        }

        /* markup uses <h3>, original CSS only targeted h4 */
        .mini-stat h3,
        .mini-stat h4 { font-size: 1.6rem; font-weight: 900; color: var(--dark-brown); }
        .mini-stat p { font-size: 0.75rem; font-weight: 700; color: var(--gray-brown); text-transform: uppercase; letter-spacing: 1px; }

        .card-surface {
            background: var(--pure-white);
            border-radius: var(--radius-lg);
            padding: clamp(20px, 3.4vw, 35px);
            box-shadow: var(--shadow-soft);
            border: 1px solid var(--border-tan);
            animation: revealUp 0.6s var(--transition-smooth) 0.1s backwards;
        }

        .card-surface h2,
        .data-display-panel h2 {
            font-size: clamp(1.2rem, 2.6vw, 1.5rem);
            font-weight: 900;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .data-display-panel h2 { margin-bottom: 0; }

        .card-surface h2 i,
        .data-display-panel h2 i { color: var(--primary-red); }

        .input-group { margin-bottom: 20px; }
        .input-group label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 800;
            font-size: 0.85rem;
            margin-bottom: 10px;
            text-transform: uppercase;
            color: var(--gray-brown);
        }

        .field-container { position: relative; }
        .field-container i {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--primary-red);
            pointer-events: none;
        }

        .form-control {
            width: 100%;
            min-width: 0;
            padding: 15px 15px 15px 45px;
            border-radius: var(--radius-md);
            border: 2px solid var(--border-tan);
            font-size: 1rem;
            font-weight: 600;
            color: var(--dark-brown);
            background: var(--vanilla-white);
            transition: var(--transition-fast);
            -webkit-appearance: none;
            appearance: none;
        }

        .form-control:focus { outline: none; border-color: var(--primary-red); background: var(--pure-white); }

        /* PERCENTAGE SLIDER */
        .range-wrapper {
            background: var(--soft-cream);
            padding: 20px;
            border-radius: var(--radius-md);
            border: 1px dashed var(--primary-red);
            text-align: center;
        }

        .range-display {
            font-size: clamp(2rem, 6vw, 2.5rem);
            font-weight: 900;
            color: var(--primary-red);
            margin-bottom: 10px;
        }

        .range-display span {
            display: inline-block;
            transition: transform 0.1s ease;
        }

        input[type="range"] {
            width: 100%;
            accent-color: var(--primary-red);
            cursor: pointer;
            /* bigger touch target */
            height: 28px;
        }

        /* TABLE COMPONENT */
        .data-display-panel {
            background: var(--pure-white);
            border-radius: var(--radius-lg);
            padding: clamp(16px, 3.4vw, 35px);
            box-shadow: var(--shadow-soft);
            border: 1px solid var(--border-tan);
            animation: revealUp 0.6s var(--transition-smooth) 0.2s backwards;
        }

        .table-overflow {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: var(--radius-md);
            border: 1px solid var(--border-tan);
            margin-top: 20px;
        }

        .discounts-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 640px;
        }

        .discounts-table thead th {
            background: var(--primary-red);
            color: var(--pure-white);
            padding: 20px;
            text-align: left;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-weight: 800;
            white-space: nowrap;
        }

        .discounts-table tbody td {
            padding: 18px 20px;
            border-bottom: 1px solid var(--border-tan);
            font-weight: 600;
        }

        .discounts-table tbody tr:hover { background: var(--vanilla-white); }

        .code-pill {
            background: var(--light-cream);
            padding: 8px 12px;
            border-radius: 8px;
            font-family: monospace;
            font-size: 1rem;
            font-weight: 800;
            color: var(--deep-coffee);
            border: 1px solid var(--border-tan);
            display: inline-flex;
            align-items: center;
            gap: 10px;
        }

        .code-pill i { padding: 4px; }

        .status-pill {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 50px;
            font-size: 0.7rem;
            font-weight: 900;
            text-transform: uppercase;
        }

        .pill-active { background: var(--success-bg); color: var(--success-text); }
        .pill-expired { background: #F5F5F5; color: #757575; }

        .action-row { display: flex; gap: 8px; }

        .action-icon-btn {
            width: 38px;
            height: 38px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            transition: var(--transition-fast);
        }

        .btn-edit { background: #E3F2FD; color: #1976D2; }
        .btn-delete { background: #FFEBEE; color: #D32F2F; }

        .btn-edit:hover { background: #1976D2; color: white; }
        .btn-delete:hover { background: #D32F2F; color: white; }

        /* FOOTER SPACER */
        .footer-offset { margin-top: auto; }

        /* Hover lift only where a real hover exists (no sticky hover on touch) */
        @media (hover: hover) {
            .mini-stat:hover { border-color: var(--primary-red); transform: translateY(-5px); }
        }

        @media (prefers-reduced-motion: reduce) {
            * { animation: none !important; transition: none !important; }
        }

        /* RESPONSIVE DESIGN */
        @media (max-width: 1100px) {
            .mgmt-grid { grid-template-columns: minmax(0, 1fr); }
            .control-panel {
                position: static;
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(min(100%, 320px), 1fr));
                align-items: start;
            }
            /* stats sit above the form on one row; keep them together */
            .stat-card-row { grid-column: 1 / -1; }
        }

        /* Table -> cards */
        @media (max-width: 760px) {
            .table-overflow {
                overflow: visible;
                border: none;
                border-radius: 0;
                margin-top: 16px;
            }

            .discounts-table,
            .discounts-table thead,
            .discounts-table tbody,
            .discounts-table tr,
            .discounts-table td {
                display: block;
                width: 100%;
                min-width: 0;
            }

            /* visually hidden, still available to screen readers */
            .discounts-table thead {
                position: absolute;
                width: 1px;
                height: 1px;
                overflow: hidden;
                clip: rect(0 0 0 0);
                white-space: nowrap;
            }

            .discounts-table tbody {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(min(100%, 280px), 1fr));
                gap: 14px;
            }

            .discounts-table tbody tr {
                background: var(--pure-white);
                border: 1px solid var(--border-tan);
                border-radius: var(--radius-md);
                padding: 14px 16px;
                box-shadow: 0 4px 14px rgba(94, 31, 19, 0.05);
            }

            .discounts-table tbody tr:hover { background: var(--pure-white); }

            .discounts-table tbody td {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 12px;
                padding: 10px 0;
                border-bottom: 1px dashed var(--border-tan);
                text-align: right;
            }

            .discounts-table tbody td:last-child { border-bottom: none; padding-bottom: 0; }

            .discounts-table tbody td::before {
                content: attr(data-label);
                flex-shrink: 0;
                text-align: left;
                font-size: 0.7rem;
                font-weight: 800;
                letter-spacing: 0.5px;
                text-transform: uppercase;
                color: var(--gray-brown);
            }

            .discounts-table tbody td.empty-row {
                display: block;
                padding: 50px 0 !important;
                text-align: center;
                border-bottom: none;
            }

            .discounts-table tbody td.empty-row::before { display: none; }

            .discounts-table tbody tr:has(td.empty-row) {
                grid-column: 1 / -1;
                border: none;
                box-shadow: none;
                padding: 0;
            }

            .action-icon-btn { width: 42px; height: 42px; }
        }

        @media (max-width: 600px) {
            .nav-breadcrumb {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }

            .brand-path h1 { justify-content: center; }

            .back-link { justify-content: center; }

            .stat-card-row { grid-template-columns: 1fr 1fr; gap: 12px; }

            .mini-stat { padding: 16px 10px; }

            .control-panel { gap: 18px; }

            .toast-msg { padding: 14px 16px; font-size: 0.9rem; }

            .range-wrapper { padding: 14px; }
        }

        @media (max-width: 360px) {
            .stat-card-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<?php include 'admin_header.php'; ?>

<main class="admin-main-wrapper">

    <header class="dashboard-header">
        <div class="nav-breadcrumb">
            <div class="brand-path">
                <h1><i class="fa-solid fa-ticket-alt"></i> Discount Vouchers</h1>
                <p>Manage and generate promotional store codes</p>
            </div>
            <a href="admin_page.php" class="back-link">
                <i class="fa-solid fa-house"></i>
                Dashboard
            </a>
        </div>
    </header>

    <?php if (!empty($message)): ?>
        <section class="alert-system">
            <?php foreach ($message as $msg): ?>
                <div class="toast-msg <?php echo $msg['type']; ?>">
                    <i class="fa-solid <?php echo $msg['type'] == 'success' ? 'fa-circle-check' : 'fa-circle-xmark'; ?>"></i>
                    <span><?php echo $msg['text']; ?></span>
                </div>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <div class="mgmt-grid">
        
        <aside class="control-panel">
            
            <div class="stat-card-row">
                <div class="mini-stat">
                    <div class="icon-box"><i class="fa-solid fa-bolt"></i></div>
                    <h3>
                        <?php 
                            $q_active = mysqli_query($conn, "SELECT count(*) as total FROM discounts WHERE status = 'active'");
                            echo mysqli_fetch_assoc($q_active)['total'];
                        ?>
                    </h3>
                    <p>Live Codes</p>
                </div>
                <div class="mini-stat">
                    <div class="icon-box" style="color:var(--gray-brown)"><i class="fa-solid fa-clock-rotate-left"></i></div>
                    <h3>
                        <?php 
                            $q_expired = mysqli_query($conn, "SELECT count(*) as total FROM discounts WHERE status = 'expired'");
                            echo mysqli_fetch_assoc($q_expired)['total'];
                        ?>
                    </h3>
                    <p>Archived</p>
                </div>
            </div>

            <section class="card-surface">
                <h2><i class="fa-solid fa-plus-circle"></i> Generate Code</h2>
                <form action="" method="POST" id="discountForm">
                    
                    <div class="input-group">
                        <label>Discount Power</label>
                        <div class="range-wrapper">
                            <div class="range-display"><span id="valLabel">10</span>%</div>
                            <input type="range" name="amount" id="amountRange" min="5" max="95" step="5" value="10">
                        </div>
                    </div>

                    <div class="input-group">
                        <label for="valid_until">Expirations Date</label>
                        <div class="field-container">
                            <i class="fa-solid fa-calendar-alt"></i>
                            <input type="date" name="valid_until" id="valid_until" class="form-control" required min="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>

                    <button type="submit" name="generate_discount" class="back-link" style="width:100%; border:none; padding:18px; justify-content:center; font-size:1.1rem; cursor:pointer;">
                        <i class="fa-solid fa-wand-sparkles"></i>
                        Generate Voucher
                    </button>

                </form>
            </section>
        </aside>

        <section class="data-display-panel">
            <h2><i class="fa-solid fa-layer-group"></i> Active Promotions</h2>
            
            <div class="table-overflow">
                <table class="discounts-table">
                    <thead>
                        <tr>
                            <th>Voucher Code</th>
                            <th>Value</th>
                            <th>Status</th>
                            <th>Valid Thru</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                            $list_sql = "SELECT * FROM `discounts` ORDER BY id DESC";
                            $list_res = mysqli_query($conn, $list_sql);
                            if(mysqli_num_rows($list_res) > 0):
                                while($row = mysqli_fetch_assoc($list_res)):
                                    $is_expired = $row['status'] == 'expired';
                        ?>
                        <tr>
                            <td data-label="Voucher Code">
                                <div class="code-pill">
                                    <?php echo $row['code']; ?>
                                    <i class="fa-solid fa-copy" style="cursor:pointer; font-size:0.8rem; opacity:0.5;" onclick="copyToClipboard('<?php echo $row['code']; ?>')"></i>
                                </div>
                            </td>
                            <td data-label="Value"><strong style="color:var(--primary-red); font-size:1.1rem;"><?php echo $row['amount']; ?>% OFF</strong></td>
                            <td data-label="Status">
                                <span class="status-pill <?php echo $is_expired ? 'pill-expired' : 'pill-active'; ?>">
                                    <?php echo $row['status']; ?>
                                </span>
                            </td>
                            <td data-label="Valid Thru" style="color:var(--gray-brown); font-size:0.9rem;">
                                <?php echo date('M d, Y', strtotime($row['valid_until'])); ?>
                            </td>
                            <td data-label="Actions">
                                <div class="action-row">
                                    <a href="edit_discount.php?id=<?php echo $row['id']; ?>" class="action-icon-btn btn-edit" title="Edit">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </a>
                                    <a href="delete_discount.php?id=<?php echo $row['id']; ?>" class="action-icon-btn btn-delete" onclick="return confirm('Archive this discount code?')" title="Delete">
                                        <i class="fa-solid fa-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endwhile; else: ?>
                        <tr>
                            <td class="empty-row" colspan="5" style="text-align:center; padding:100px 0; color:var(--gray-brown);">
                                <i class="fa-solid fa-face-frown" style="font-size:3rem; margin-bottom:20px; display:block; opacity:0.3;"></i>
                                No active discount codes found in database.
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

    </div>
</main>

<div class="footer-offset"></div>
<?php include 'admin_footer.php'; ?>

<script>
    /**
     * DYNAMIC UI HANDLERS
     */
    const rangeInput = document.getElementById('amountRange');
    const labelOutput = document.getElementById('valLabel');

    if(rangeInput) {
        rangeInput.addEventListener('input', (e) => {
            labelOutput.innerText = e.target.value;
            // Visual feedback: grow the label on change
            labelOutput.style.transform = "scale(1.2)";
            setTimeout(() => labelOutput.style.transform = "scale(1)", 100);
        });
    }

    /**
     * UTILITY: COPY TO CLIPBOARD
     */
    function copyToClipboard(text) {
        navigator.clipboard.writeText(text).then(() => {
            alert("Code " + text + " copied to clipboard!");
        });
    }

    /**
     * AUTO-DISMISS ALERTS
     */
    document.addEventListener('DOMContentLoaded', () => {
        const alerts = document.querySelectorAll('.toast-msg');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.transition = "all 0.5s ease";
                alert.style.opacity = "0";
                alert.style.transform = "translateY(-10px)";
                setTimeout(() => alert.remove(), 500);
            }, 5000);
        });
    });
</script>

</body>
</html>