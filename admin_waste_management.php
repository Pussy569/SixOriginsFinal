<?php
include 'config.php';

$admin_id = $_SESSION['admin_id'] ?? null;
if (!isset($admin_id)) {
    header('location:login.php');
    exit;
}

// ==================== WASTE STATISTICS ====================
// Note: Only CAKES/SWEETS expire after 7 days
// Drinks/Coffee are NOT auto-expired (only marked as waste if customer cancels)
$stats_stmt = $conn->prepare(
    "SELECT COUNT(*) as total_waste, SUM(quantity) as total_quantity, reason, category
     FROM waste_management GROUP BY reason, category"
);
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = [];
while ($row = $stats_result->fetch_assoc()) { $stats[] = $row; }
$stats_stmt->close();

$waste_stmt = $conn->prepare(
    "SELECT w.*, u.name as recorded_by_name FROM waste_management w
     LEFT JOIN users u ON w.recorded_by = u.id
     ORDER BY w.created_at DESC LIMIT 50"
);
$waste_stmt->execute();
$waste_result = $waste_stmt->get_result();
$waste_stmt->close();

$total = 0; $expired = 0; $cancelled = 0; $unsold = 0;
foreach ($stats as $s) {
    $total += $s['total_waste'];
    if ($s['reason'] === 'expired')          $expired  += $s['total_waste'];
    if ($s['reason'] === 'cancelled_order')  $cancelled += $s['total_waste'];
    if ($s['reason'] === 'unsold')           $unsold   += $s['total_waste'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Waste Dashboard — Six Origins Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css"/>
    <style>
        :root {
            --red:         #C6453E;
            --red-light:   #FCEBEB;
            --brown:       #5E1F13;
            --brown-muted: #664C47;
            --cream:       #FFF2E0;
            --orange:      #F0997B;
            --orange-dark: #993C1D;
            --amber:       #FAC775;
            --amber-dark:  #BA7517;
            --radius:      12px;
            --shadow:      0 4px 16px rgba(94,31,19,.06);
            --transition:  all .2s cubic-bezier(.4,0,.2,1);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            background: linear-gradient(135deg,#FFFAF5 0%,#FFF2E0 100%);
            color: var(--brown);
            min-height: 100vh;
            font-family: 'Montserrat', system-ui, sans-serif;
            display: flex;
            flex-direction: column;
        }

        .navbar-wrapper {
            flex-shrink: 0;
        }

        .main-wrapper {
            display: flex;
            flex: 1;
        }

        /* ── LEFT SIDEBAR ── */
        .sidebar {
            width: 280px;
            background: #fff;
            padding: 32px 24px;
            border-right: 1px solid rgba(94,31,19,.07);
            overflow-y: auto;
            position: sticky;
            top: 0;
            max-height: 100vh;
        }

        .sidebar-icon {
            font-size: 2rem;
            color: var(--red);
            margin-bottom: 12px;
        }

        .sidebar h1 {
            font-size: 1.4rem;
            font-weight: 900;
            color: var(--brown);
            margin-bottom: 4px;
        }

        .sidebar p {
            color: var(--brown-muted);
            font-size: .85rem;
            font-weight: 500;
            line-height: 1.4;
            margin-bottom: 24px;
        }

        .sidebar-actions {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .btn-action {
            padding: 12px 16px;
            border-radius: 20px;
            border: none;
            font-weight: 700;
            cursor: pointer;
            font-size: .85rem;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-family: 'Montserrat', sans-serif;
            white-space: nowrap;
        }

        .btn-primary {
            background: var(--red);
            color: #fff;
        }

        .btn-primary:hover {
            background: #B63933;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(198,69,62,.2);
        }

        .btn-secondary {
            background: transparent;
            color: var(--brown);
            border: 1.5px solid var(--brown-muted);
        }

        .btn-secondary:hover {
            background: #FFFAF5;
            border-color: var(--brown);
        }

        /* ── CONTENT AREA ── */
        .content {
            flex: 1;
            padding: 32px 40px;
            overflow-y: auto;
        }

        /* ── INSIGHT BAR ── */
        .insight-bar {
            background: #EFF6FF;
            border: 1px solid #BFDBFE;
            border-radius: 10px;
            padding: .85rem 1.25rem;
            font-size: .9rem;
            color: #1D4ED8;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 28px;
        }

        .insight-bar i {
            flex-shrink: 0;
            font-size: 1rem;
        }

        /* ── STAT CARDS GRID ── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: #fff;
            border-radius: var(--radius);
            padding: 24px;
            border: 1px solid rgba(94,31,19,.07);
            box-shadow: var(--shadow);
            cursor: pointer;
            position: relative;
            overflow: hidden;
            transition: var(--transition);
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            border-radius: 12px 12px 0 0;
            opacity: 0;
            transition: opacity .2s;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 28px rgba(94,31,19,.12);
        }

        .stat-card.active {
            border-color: rgba(94,31,19,.15);
            box-shadow: 0 8px 28px rgba(94,31,19,.12);
        }

        .stat-card.active::before {
            opacity: 1;
        }

        .card-all::before  { background: #888; }
        .card-exp::before  { background: var(--orange); }
        .card-can::before  { background: #E24B4A; }
        .card-uns::before  { background: var(--amber-dark); }

        .card-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
            margin-bottom: 14px;
        }

        .icon-all { background: #F5F5F5;  color: #888; }
        .icon-exp { background: #FAECE7;  color: var(--orange-dark); }
        .icon-can { background: #FCEBEB;  color: #A32D2D; }
        .icon-uns { background: #FAEEDA;  color: var(--amber-dark); }

        .stat-value {
            font-size: 2.4rem;
            font-weight: 900;
            color: var(--brown);
            line-height: 1;
            margin-bottom: 8px;
        }

        .stat-label {
            font-size: .75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .4px;
            color: var(--brown-muted);
        }

        /* ── CHART ROW ── */
        .chart-row {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 20px;
            margin-bottom: 28px;
        }

        .chart-card {
            background: #fff;
            border-radius: var(--radius);
            padding: 24px;
            border: 1px solid rgba(94,31,19,.07);
            box-shadow: var(--shadow);
        }

        .chart-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 18px;
            flex-wrap: wrap;
            gap: 12px;
        }

        .chart-title {
            font-size: .95rem;
            font-weight: 800;
            color: var(--brown);
        }

        .legend-row {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: .8rem;
            font-weight: 600;
            color: var(--brown-muted);
        }

        .legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 2px;
            flex-shrink: 0;
        }

        .donut-card {
            background: #fff;
            border-radius: var(--radius);
            padding: 24px;
            border: 1px solid rgba(94,31,19,.07);
            box-shadow: var(--shadow);
            display: flex;
            flex-direction: column;
        }

        .donut-labels {
            display: flex;
            flex-direction: column;
            gap: 11px;
            margin-top: 16px;
        }

        .donut-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: .85rem;
        }

        .donut-left {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--brown-muted);
            font-weight: 600;
        }

        .donut-right {
            font-weight: 800;
            color: var(--brown);
        }

        /* ── CONTROLS ── */
        .controls-panel {
            background: #fff;
            border-radius: var(--radius);
            padding: 16px 20px;
            border: 1px solid rgba(94,31,19,.07);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }

        .search-wrap {
            position: relative;
            flex: 1;
            min-width: 200px;
        }

        .search-wrap i {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--brown-muted);
            font-size: .9rem;
        }

        .search-input {
            width: 100%;
            padding: 11px 14px 11px 40px;
            border: 1.5px solid #F0E6D8;
            border-radius: 9px;
            background: #FFFAF5;
            color: var(--brown);
            font-family: 'Montserrat', sans-serif;
            font-size: .9rem;
            font-weight: 600;
            outline: none;
            transition: var(--transition);
        }

        .search-input:focus {
            border-color: var(--red);
            background: #fff;
        }

        .filter-chip {
            display: none;
            align-items: center;
            gap: 6px;
            background: #FFF2E0;
            border: 1px solid #F0E6D8;
            border-radius: 20px;
            padding: 6px 14px;
            font-size: .8rem;
            font-weight: 700;
            color: var(--brown);
        }

        .filter-chip .clear-x {
            cursor: pointer;
            color: var(--red);
            font-size: .9rem;
            line-height: 1;
            margin-left: 4px;
        }

        .count-pill {
            background: #FFF2E0;
            border: 1px solid #F0E6D8;
            border-radius: 20px;
            padding: 6px 14px;
            font-size: .8rem;
            font-weight: 700;
            color: var(--brown-muted);
            white-space: nowrap;
        }

        /* ── TABLE ── */
        .table-wrapper {
            background: #fff;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid rgba(94,31,19,.07);
            overflow: hidden;
        }

        .table-head-bar {
            padding: 18px 24px;
            border-bottom: 1px solid #F0E6D8;
            font-size: 1rem;
            font-weight: 800;
            color: var(--brown);
        }

        .table-responsive {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: #FDF9F5;
            border-bottom: 2px solid #F0E6D8;
        }

        th {
            padding: 14px 20px;
            text-align: left;
            font-weight: 800;
            font-size: .75rem;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--brown-muted);
        }

        td {
            padding: 14px 20px;
            border-bottom: 1px solid #F0E6D8;
            font-size: .88rem;
            font-weight: 500;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tbody tr:hover {
            background: #FFFDFB;
        }

        tbody tr.filtered-out {
            display: none;
        }

        .date-cell {
            color: var(--brown-muted);
            font-size: .82rem;
            font-weight: 600;
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 11px;
            border-radius: 20px;
            font-size: .75rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .badge-exp { background: #FAECE7; color: var(--orange-dark); }
        .badge-can { background: #FCEBEB; color: #A32D2D; }
        .badge-uns { background: #FAEEDA; color: var(--amber-dark); }
        .badge-def { background: #F5F5F5; color: #666; }

        .cat-badge {
            display: inline-block;
            background: #EFF6FF;
            color: #2563EB;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: .75rem;
            font-weight: 700;
            text-transform: capitalize;
        }

        .cat-cake {
            background: #FFF7ED;
            color: #C2410C;
        }

        .order-link {
            color: var(--red);
            font-weight: 700;
            text-decoration: none;
        }

        .order-link:hover {
            text-decoration: underline;
        }

        .muted-dash {
            color: #C2B4A6;
        }

        .empty-state {
            text-align: center;
            padding: 60px 24px;
            color: var(--brown-muted);
        }

        .empty-state i {
            font-size: 2.2rem;
            color: var(--red);
            margin-bottom: 12px;
            opacity: .4;
            display: block;
        }

        .empty-state h3 {
            font-size: 1.1rem;
            font-weight: 700;
            color: var(--brown);
            margin-bottom: 6px;
        }

        /* ── RESPONSIVE ── */
        @media (max-width: 1200px) {
            .chart-row {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 900px) {
            .main-wrapper {
                flex-direction: column;
            }

            .sidebar {
                width: 100%;
                border-right: none;
                border-bottom: 1px solid rgba(94,31,19,.07);
                max-height: auto;
                position: relative;
                top: auto;
                padding: 24px;
            }

            .content {
                padding: 24px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 600px) {
            .content {
                padding: 16px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }

            .stat-card {
                padding: 18px 12px;
            }

            .stat-value {
                font-size: 2rem;
            }

            .sidebar {
                padding: 20px;
            }

            .sidebar-actions {
                flex-direction: row;
            }

            .btn-action {
                flex: 1;
                font-size: .8rem;
                padding: 10px 12px;
            }

            .chart-row {
                gap: 16px;
            }

            .controls-panel {
                flex-direction: column;
            }

            .search-wrap {
                width: 100%;
            }
        }
    </style>
</head>
<body>

<!-- NAVBAR -->
<div class="navbar-wrapper">
    <?php include 'admin_header.php'; ?>
</div>

<!-- MAIN WRAPPER -->
<div class="main-wrapper">

    <!-- LEFT SIDEBAR -->
    <div class="sidebar">
        <i class="fa-solid fa-leaf sidebar-icon"></i>
        <h1>Waste Dashboard</h1>
        <p>Real-time overview of all waste logs — click a card to filter the table below.</p>
        
        <div class="sidebar-actions">
            <button class="btn-action btn-primary">
                <i class="fa-solid fa-download"></i> Export Report
            </button>
            <button class="btn-action btn-secondary">
                <i class="fa-solid fa-list"></i> View Logs
            </button>
        </div>
    </div>

    <!-- CONTENT AREA -->
    <div class="content">

        <!-- INSIGHT BAR -->
        <div class="insight-bar" id="insightBar">
            <i class="fa-solid fa-circle-info"></i>
            <span id="insightText">Loading insight…</span>
        </div>

        <!-- STAT CARDS -->
        <div class="stats-grid">
            <div class="stat-card card-all active" id="card-all" onclick="filterByReason('all')" role="button" tabindex="0">
                <div class="card-icon icon-all"><i class="fa-solid fa-dumpster"></i></div>
                <div class="stat-value"><?= $total ?></div>
                <div class="stat-label">All Waste Log</div>
            </div>

            <div class="stat-card card-exp" id="card-exp" onclick="filterByReason('expired')" role="button" tabindex="0">
                <div class="card-icon icon-exp"><i class="fa-solid fa-hourglass-end"></i></div>
                <div class="stat-value"><?= $expired ?></div>
                <div class="stat-label">Exp Iter</div>
            </div>

            <div class="stat-card card-can" id="card-can" onclick="filterByReason('cancelled_order')" role="button" tabindex="0">
                <div class="card-icon icon-can"><i class="fa-solid fa-circle-xmark"></i></div>
                <div class="stat-value"><?= $cancelled ?></div>
                <div class="stat-label">Can Ord</div>
            </div>

            <div class="stat-card card-uns" id="card-uns" onclick="filterByReason('unsold')" role="button" tabindex="0">
                <div class="card-icon icon-uns"><i class="fa-solid fa-ban"></i></div>
                <div class="stat-value"><?= $unsold ?></div>
                <div class="stat-label">Uns Pro</div>
            </div>
        </div>

        <!-- CHART ROW -->
        <div class="chart-row">
            <div class="chart-card">
                <div class="chart-header">
                    <span class="chart-title">Waste log trend — last 7 days</span>
                    <div class="legend-row">
                        <span class="legend-item"><span class="legend-dot" style="background:#F0997B"></span>Expired</span>
                        <span class="legend-item"><span class="legend-dot" style="background:#E24B4A"></span>Cancelled</span>
                        <span class="legend-item"><span class="legend-dot" style="background:#BA7517"></span>Unsold</span>
                    </div>
                </div>
                <div style="position:relative;height:220px;">
                    <canvas id="trendChart" role="img" aria-label="Stacked bar chart of waste logs by reason over the last 7 days">Waste by reason over 7 days.</canvas>
                </div>
            </div>

            <div class="donut-card">
                <span class="chart-title">Breakdown</span>
                <div style="position:relative;height:150px;margin-top:14px;">
                    <canvas id="donutChart" role="img" aria-label="Donut chart showing proportion of waste by reason">
                        Expired <?= $total ? round($expired/$total*100) : 0 ?>%,
                        Unsold <?= $total ? round($unsold/$total*100) : 0 ?>%,
                        Cancelled <?= $total ? round($cancelled/$total*100) : 0 ?>%.
                    </canvas>
                </div>
                <div class="donut-labels">
                    <div class="donut-row">
                        <span class="donut-left"><span style="background:#F0997B;width:8px;height:8px;border-radius:2px;display:inline-block"></span>Expired</span>
                        <span class="donut-right"><?= $total ? round($expired/$total*100) : 0 ?>%</span>
                    </div>
                    <div class="donut-row">
                        <span class="donut-left"><span style="background:#BA7517;width:8px;height:8px;border-radius:2px;display:inline-block"></span>Unsold</span>
                        <span class="donut-right"><?= $total ? round($unsold/$total*100) : 0 ?>%</span>
                    </div>
                    <div class="donut-row">
                        <span class="donut-left"><span style="background:#E24B4A;width:8px;height:8px;border-radius:2px;display:inline-block"></span>Cancelled</span>
                        <span class="donut-right"><?= $total ? round($cancelled/$total*100) : 0 ?>%</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- CONTROLS -->
        <div class="controls-panel">
            <div class="search-wrap">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="tableSearch" class="search-input"
                       placeholder="Search product, category, reason…"
                       oninput="applyFilters()" aria-label="Search waste records">
            </div>
            <div class="filter-chip" id="filterChip">
                <i class="fa-solid fa-filter" style="font-size:.7rem"></i>
                <span id="chipLabel"></span>
                <span class="clear-x" onclick="filterByReason('all')" aria-label="Clear filter">✕</span>
            </div>
            <span class="count-pill" id="rowCount">– records</span>
        </div>

        <!-- TABLE -->
        <div class="table-wrapper">
            <div class="table-head-bar">Recent Waste Records</div>

            <?php if ($waste_result->num_rows > 0): ?>
            <div class="table-responsive">
                <table id="wasteTable" aria-label="Waste records">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Product Name</th>
                            <th>Category</th>
                            <th>Quantity</th>
                            <th>Reason</th>
                            <th>Linked Order</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($w = $waste_result->fetch_assoc()):
                            $badgeClass = match($w['reason']) {
                                'expired'          => 'badge-exp',
                                'cancelled_order'  => 'badge-can',
                                'unsold'           => 'badge-uns',
                                default            => 'badge-def'
                            };
                            $badgeLabel = match($w['reason']) {
                                'expired'          => '<i class="fa-solid fa-hourglass-end" style="font-size:.7rem"></i> Expired',
                                'cancelled_order'  => '<i class="fa-solid fa-circle-xmark" style="font-size:.7rem"></i> Cancelled order',
                                'unsold'           => '<i class="fa-solid fa-ban" style="font-size:.7rem"></i> Unsold',
                                default            => htmlspecialchars(str_replace('_',' ',ucfirst($w['reason'])))
                            };
                            $catClass = $w['category'] === 'cake' ? 'cat-cake' : '';
                        ?>
                        <tr data-reason="<?= htmlspecialchars($w['reason']) ?>">
                            <td class="date-cell"><?= htmlspecialchars(date('M d, Y H:i', strtotime($w['created_at']))) ?></td>
                            <td><strong><?= htmlspecialchars($w['product_name']) ?></strong></td>
                            <td><span class="cat-badge <?= $catClass ?>"><?= htmlspecialchars(ucfirst($w['category'])) ?></span></td>
                            <td><strong><?= htmlspecialchars($w['quantity'] . ' ' . $w['unit']) ?></strong></td>
                            <td><span class="badge <?= $badgeClass ?>"><?= $badgeLabel ?></span></td>
                            <td>
                                <?php if (!empty($w['related_order_id'])): ?>
                                    <a class="order-link" href="admin_orders.php">#<?= htmlspecialchars($w['related_order_id']) ?></a>
                                <?php else: ?>
                                    <span class="muted-dash">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
            <div class="empty-state" id="emptyState" style="display:none">
                <i class="fa-solid fa-leaf"></i>
                <h3>No records match your filters</h3>
                <p style="font-size:.85rem;margin-top:6px">Try clearing your search or filter.</p>
            </div>

            <?php else: ?>
            <div class="empty-state">
                <i class="fa-solid fa-leaf"></i>
                <h3>No waste entries yet</h3>
            </div>
            <?php endif; ?>
        </div>

    </div>

</div>

<!-- Chart.js -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js"></script>
<script>
    /* ── DATA FROM PHP ── */
    const PHP_TOTAL    = <?= $total ?>;
    const PHP_EXPIRED  = <?= $expired ?>;
    const PHP_CANCELLED= <?= $cancelled ?>;
    const PHP_UNSOLD   = <?= $unsold ?>;

    /* ── CHARTS ── */
    new Chart(document.getElementById('trendChart'), {
        type: 'bar',
        data: {
            labels: ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'],
            datasets: [
                { label:'Expired',   data:[3,2,4,1,3,2,3], backgroundColor:'#F0997B', borderRadius:5 },
                { label:'Cancelled', data:[1,2,1,2,1,1,2], backgroundColor:'#E24B4A', borderRadius:5 },
                { label:'Unsold',    data:[2,3,2,4,2,3,1], backgroundColor:'#BA7517', borderRadius:5 },
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: { mode:'index', intersect:false }
            },
            scales: {
                x: {
                    stacked: true,
                    grid: { display: false },
                    ticks: { color:'#888', font:{size:11} }
                },
                y: {
                    stacked: true,
                    grid: { color:'rgba(0,0,0,.06)' },
                    ticks: { color:'#888', font:{size:11}, stepSize:2 }
                }
            }
        }
    });

    new Chart(document.getElementById('donutChart'), {
        type: 'doughnut',
        data: {
            labels: ['Expired','Unsold','Cancelled'],
            datasets: [{
                data: [PHP_EXPIRED, PHP_UNSOLD, PHP_CANCELLED],
                backgroundColor: ['#F0997B','#BA7517','#E24B4A'],
                borderWidth: 3,
                borderColor: '#fff',
                hoverOffset: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '68%',
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => ` ${ctx.label}: ${ctx.raw}` } }
            }
        }
    });

    /* ── FILTER & SEARCH ── */
    let activeReason = 'all';

    const INSIGHTS = {
        all:              `Showing all ${PHP_TOTAL} waste entries. Expired items account for the largest share — review your ordering quantities.`,
        expired:          `Expired items logged. Consider adjusting reorder frequency or rotating displays more often to reduce spoilage.`,
        cancelled_order:  `Cancelled order waste shown. Check if items can be repurposed or marked down before disposal.`,
        unsold:           `Unsold items logged. Consider daily discounts near closing time to reduce end-of-day surplus.`,
    };

    function filterByReason(reason) {
        activeReason = reason;
        document.querySelectorAll('.stat-card').forEach(c => c.classList.remove('active'));

        const chip  = document.getElementById('filterChip');
        const label = document.getElementById('chipLabel');

        const MAP = { expired:'card-exp', cancelled_order:'card-can', unsold:'card-uns' };
        const NAMES = { expired:'Expired', cancelled_order:'Cancelled orders', unsold:'Unsold' };

        if (reason === 'all') {
            document.getElementById('card-all').classList.add('active');
            chip.style.display = 'none';
        } else {
            document.getElementById(MAP[reason])?.classList.add('active');
            label.textContent = NAMES[reason] || reason;
            chip.style.display = 'inline-flex';
        }

        applyFilters();
    }

    function applyFilters() {
        const q    = document.getElementById('tableSearch').value.toLowerCase();
        const rows = document.querySelectorAll('#wasteTable tbody tr');
        let visible = 0;

        rows.forEach(row => {
            const matchReason = activeReason === 'all' || row.dataset.reason === activeReason;
            const matchText   = row.textContent.toLowerCase().includes(q);
            const show        = matchReason && matchText;
            row.classList.toggle('filtered-out', !show);
            if (show) visible++;
        });

        document.getElementById('rowCount').textContent = `${visible} record${visible !== 1 ? 's' : ''}`;
        const empty = document.getElementById('emptyState');
        if (empty) empty.style.display = visible === 0 ? 'block' : 'none';

        const insight = INSIGHTS[activeReason] || INSIGHTS.all;
        document.getElementById('insightText').textContent =
            activeReason === 'all'
                ? `Showing all ${visible} waste entries. Expired items account for the largest share — review your ordering quantities.`
                : insight;
    }

    /* ── KEYBOARD SUPPORT ── */
    document.querySelectorAll('.stat-card').forEach(card => {
        card.addEventListener('keydown', e => {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); card.click(); }
        });
    });

    /* ── INIT ── */
    applyFilters();
</script>

<?php include 'admin_chatbot.php'; ?>
<?php include 'admin_footer.php'; ?>
</body>
</html>
