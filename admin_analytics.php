<?php
include 'config.php';

$admin_id = $_SESSION['admin_id'];

if(!isset($admin_id)){
   header('location:login.php');
   exit;
}

// --- Top 5 Best Sellers Query (FIXED - Parse total_products field) ---
// Note: we no longer GROUP BY / LIMIT in SQL because the raw product_name
// still contains extra info like "(1 x Regular) [Preference: ...] [Extras: ...]".
// We clean the name first in PHP, THEN aggregate + rank, so items that only
// differ by size/preference/extras are correctly merged into one bar.
$top_products = mysqli_query($conn, "
    SELECT 
        SUBSTRING_INDEX(SUBSTRING_INDEX(total_products, ',', numbers.n), ',', -1) as product_name
    FROM orders
    JOIN (SELECT 1 as n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) numbers ON (LENGTH(total_products) - LENGTH(REPLACE(total_products, ',', '')) >= numbers.n - 1)
    WHERE payment_status = 'completed'
") or die(mysqli_error($conn));

$product_counts = [];
while($p = mysqli_fetch_assoc($top_products)) {
    $product = trim($p['product_name']);

    // Strip EVERYTHING from the first "(" or "[" onward.
    // This removes "(1 x Regular)", "[Preference: Hot / Iced: Iced]",
    // "[Extras: Extra Espresso Shot (+₱25.00)]", etc. in one go,
    // instead of only matching a trailing "(N x Size)" pattern.
    $product = preg_replace('/\s*[\(\[].*$/u', '', $product);
    $product = trim($product);

    if ($product === '') {
        continue;
    }

    if (!isset($product_counts[$product])) {
        $product_counts[$product] = 0;
    }
    $product_counts[$product]++;
}

// Sort by units sold, descending, then take top 5
arsort($product_counts);
$top5 = array_slice($product_counts, 0, 5, true);

$best_products_labels = array_keys($top5);
$best_products_data = array_values($top5);

// --- Sales per Day 7-DAYS ---
$sales_labels = [];
$sales_totals = [];
for ($i = 6; $i >= 0; $i--) {
   $day = date('Y-m-d', strtotime("-$i days"));
   $sales_labels[] = date('M d', strtotime($day));
   $result = mysqli_query($conn, "SELECT SUM(total_price) as total FROM `orders` WHERE payment_status = 'completed' AND DATE(placed_on) = '$day' ");
   $row = mysqli_fetch_assoc($result);
   $sales_totals[] = $row && $row['total'] ? floatval($row['total']) : 0;
}

// --- Orders by Status Pie Data ---
$order_statuses = ['pending', 'accepted', 'preparing', 'out for delivery', 'completed', 'cancelled'];
$status_counts = [];
foreach ($order_statuses as $status) {
   $count = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM `orders` WHERE payment_status = '$status' "));
   $status_counts[] = $count;
}

// --- Low Stock Products (top 5) ---
$low_stock = mysqli_query($conn, "
   SELECT p.name, s.size, s.stock 
   FROM product_sizes s 
   JOIN products p ON s.product_id = p.id 
   WHERE s.stock <= 5 AND s.is_active = 1
   ORDER BY s.stock ASC 
   LIMIT 5
") or die(mysqli_error($conn));

// --- KPI Metrics ---
/* 1. Total Revenue (completed) */
$kpi = [];
$result = mysqli_query($conn, "SELECT SUM(total_price) as total_revenue FROM orders WHERE payment_status = 'completed'");
$row = mysqli_fetch_assoc($result);
$kpi['total_revenue'] = $row && $row['total_revenue'] ? floatval($row['total_revenue']) : 0;

/* 2. Orders Today (completed) */
$today = date('Y-m-d');
$result = mysqli_query($conn, "SELECT COUNT(*) as orders_today FROM orders WHERE payment_status = 'completed' AND DATE(placed_on) = '$today'");
$row = mysqli_fetch_assoc($result);
$kpi['orders_today'] = $row && $row['orders_today'] ? intval($row['orders_today']) : 0;

/* 3. Total Discount Used (completed) */
$result = mysqli_query($conn, "SELECT SUM(discount_used) as total_discount FROM orders WHERE payment_status = 'completed'");
$row = mysqli_fetch_assoc($result);
$kpi['total_discount'] = $row && $row['total_discount'] ? floatval($row['total_discount']) : 0;

/* 4. Total Orders */
$result = mysqli_query($conn, "SELECT COUNT(*) as total_orders FROM orders");
$row = mysqli_fetch_assoc($result);
$kpi['total_orders'] = $row && $row['total_orders'] ? intval($row['total_orders']) : 0;

?>
<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="utf-8">
   <meta name="viewport" content="width=device-width,initial-scale=1">
   <title>Six Origins Cafe — Analytics</title>
   <link rel="preconnect" href="https://fonts.googleapis.com">
   <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
   <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" />
   <link rel="icon" type="image/png" href="images/logos.png">
   <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
   <style>
  
      :root {
        --primary-red: #C6453E;
        --dark-brown: #5E1F13;
        --gray-brown: #664C47;
        --light-cream: #FFF2E0;
        --white: #FFFFFF;
        --radius: 16px;
        --shadow: 0 8px 24px rgba(94, 31, 19, 0.08);
        --shadow-hover: 0 12px 36px rgba(94, 31, 19, 0.12);
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        --max-width: 1400px;
      }

      * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
      }

      html {
        -webkit-text-size-adjust: 100%;
      }

      body {
        background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%);
        color: var(--dark-brown);
        font-family: 'Montserrat', system-ui, -apple-system, "Segoe UI", Roboto, Arial;
        min-height: 100vh;
        overflow-x: hidden;
      }

      .an-page {
        max-width: var(--max-width);
        margin: 0 auto;
        padding: clamp(16px, 4vw, 40px) clamp(12px, 3vw, 24px) clamp(48px, 8vw, 80px);
        width: 100%;
      }

      /* ---------- Header ---------- */
      .an-header {
        margin-bottom: clamp(20px, 4vw, 40px);
        animation: anSlideDown 0.5s ease;
      }

      .an-header-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px 20px;
        flex-wrap: wrap;
      }

      .an-title {
        display: flex;
        align-items: center;
        gap: clamp(10px, 2vw, 16px);
        min-width: 0;
      }

      .an-title h1 {
        font-size: clamp(1.6rem, 4.5vw, 2.8rem);
        font-weight: 900;
        color: var(--dark-brown);
        letter-spacing: -1px;
        line-height: 1.1;
        margin: 0;
      }

      .an-title i {
        font-size: clamp(1.8rem, 4.5vw, 3rem);
        color: var(--primary-red);
        animation: anPulse 2s infinite;
        flex-shrink: 0;
      }

      .an-subtitle {
        color: var(--gray-brown);
        font-size: clamp(0.85rem, 2vw, 1rem);
        font-weight: 500;
        margin-top: 6px;
      }

      .an-actions {
        display: flex;
        gap: 12px;
        align-items: center;
        flex-wrap: wrap;
      }

      .an-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        padding: 14px 24px;
        background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
        color: #fff;
        border: none;
        border-radius: var(--radius);
        font-weight: 800;
        font-family: inherit;
        cursor: pointer;
        text-decoration: none;
        box-shadow: 0 4px 12px rgba(198, 69, 62, 0.2);
        transition: var(--transition);
        font-size: 0.95rem;
        white-space: nowrap;
      }

      .an-btn:hover {
        background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(94, 31, 19, 0.3);
      }

      .an-btn-secondary {
        background: linear-gradient(135deg, #664C47 0%, #5E1F13 100%);
      }

      .an-btn-secondary:hover {
        background: linear-gradient(135deg, #5E1F13 0%, #4a1810 100%);
      }

      .export-dropdown {
        position: relative;
        display: inline-block;
      }

      .export-menu {
        display: none;
        position: absolute;
        right: 0;
        background-color: white;
        min-width: 200px;
        box-shadow: 0 8px 16px rgba(0, 0, 0, 0.2);
        z-index: 20;
        border-radius: 8px;
        overflow: hidden;
        top: 100%;
        margin-top: 8px;
      }

      .export-menu a {
        color: var(--dark-brown);
        padding: 16px 20px;
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 600;
        transition: var(--transition);
        border-bottom: 1px solid #f0e6d8;
      }

      .export-menu a:last-child {
        border-bottom: none;
      }

      .export-menu a:hover {
        background-color: var(--light-cream);
        padding-left: 24px;
      }

      .export-menu i {
        color: var(--primary-red);
        width: 16px;
      }

      .export-dropdown.active .export-menu {
        display: block;
      }

      /* ---------- KPI Grid ---------- */
      .kpi-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr));
        gap: clamp(12px, 2vw, 20px);
        margin-bottom: clamp(20px, 4vw, 40px);
        animation: anFadeIn 0.6s ease 0.1s both;
      }

      .kpi-card {
        background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
        border-radius: var(--radius);
        padding: clamp(16px, 3vw, 28px);
        box-shadow: var(--shadow);
        border: 1.5px solid #F0E6D8;
        transition: var(--transition);
        position: relative;
        overflow: hidden;
        min-width: 0;
      }

      .kpi-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, var(--primary-red), #B83A34);
      }

      .kpi-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 14px;
      }

      .kpi-label {
        font-size: clamp(0.72rem, 1.6vw, 0.9rem);
        font-weight: 800;
        color: var(--gray-brown);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        display: flex;
        align-items: center;
        gap: 8px;
      }

      .kpi-label i {
        font-size: 1.2rem;
        color: var(--primary-red);
      }

      .kpi-value {
        font-size: clamp(1.6rem, 3.2vw, 2.5rem);
        font-weight: 900;
        color: var(--primary-red);
        line-height: 1.1;
        margin-bottom: 8px;
        overflow-wrap: anywhere;
      }

      .kpi-change {
        font-size: 0.85rem;
        color: var(--gray-brown);
        font-weight: 600;
      }

      /* ---------- Analytics Grid ---------- */
      .analytics-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(min(100%, 440px), 1fr));
        gap: clamp(14px, 2.4vw, 24px);
        margin-bottom: clamp(14px, 2.4vw, 24px);
        animation: anFadeIn 0.6s ease 0.2s both;
      }

      /* min-width:0 lets grid children shrink so Chart.js canvases never overflow */
      .analytics-grid > * {
        min-width: 0;
      }

      .chart-panel,
      .low-stock-panel {
        background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
        border-radius: var(--radius);
        padding: clamp(16px, 3vw, 28px);
        box-shadow: var(--shadow);
        border: 1.5px solid #F0E6D8;
        transition: var(--transition);
        position: relative;
        display: flex;
        flex-direction: column;
        overflow: hidden;
      }

      .chart-panel::before,
      .low-stock-panel::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 4px;
        background: linear-gradient(90deg, var(--primary-red), #B83A34);
      }

      .low-stock-panel::before {
        background: linear-gradient(90deg, #D97E6A, #C25A52);
      }

      .chart-header {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 18px;
        padding-bottom: 14px;
        border-bottom: 2px solid #F0E6D8;
      }

      .chart-header i {
        font-size: 1.4rem;
        color: var(--primary-red);
        flex-shrink: 0;
      }

      .chart-header h3 {
        font-size: clamp(1rem, 2.2vw, 1.25rem);
        font-weight: 900;
        color: var(--dark-brown);
        margin: 0;
        letter-spacing: -0.5px;
      }

      .chart-container {
        position: relative;
        flex: 1;
        width: 100%;
        height: clamp(260px, 42vw, 340px);
        min-height: 0;
      }

      /* ---------- Low Stock ---------- */
      .low-stock-list {
        list-style: none;
        padding: 0;
        margin: 0;
      }

      .low-stock-item {
        background: linear-gradient(135deg, rgba(198,69,62,0.08) 0%, rgba(255,242,224,0.1) 100%);
        border-left: 4px solid var(--primary-red);
        border-radius: 12px;
        padding: 14px 16px;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 10px 12px;
        transition: var(--transition);
      }

      .low-stock-item:last-child {
        margin-bottom: 0;
      }

      .low-stock-info {
        display: flex;
        align-items: center;
        gap: 12px;
        flex: 1 1 200px;
        min-width: 0;
      }

      .low-stock-info i {
        font-size: 1.3rem;
        color: var(--primary-red);
        flex-shrink: 0;
      }

      .low-stock-name {
        font-weight: 800;
        color: var(--dark-brown);
        font-size: 1rem;
        min-width: 0;
        overflow-wrap: anywhere;
      }

      .low-stock-size {
        background: #fff2e0;
        padding: 4px 10px;
        border-radius: 6px;
        font-weight: 700;
        font-size: 0.8rem;
        color: var(--gray-brown);
        margin-left: auto;
        flex-shrink: 0;
      }

      .low-stock-badge {
        background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
        color: #fff;
        padding: 6px 12px;
        border-radius: 8px;
        font-weight: 800;
        font-size: 0.85rem;
        white-space: nowrap;
      }

      .empty-state {
        text-align: center;
        padding: 40px 20px;
        color: var(--gray-brown);
      }

      .empty-state i {
        font-size: 2.5rem;
        margin-bottom: 16px;
        color: var(--primary-red);
        opacity: 0.6;
      }

      .empty-state p {
        font-size: 1rem;
        font-weight: 600;
      }

      /* Hover lift only on devices that actually hover (avoids sticky hover on touch) */
      @media (hover: hover) {
        .kpi-card:hover {
          box-shadow: var(--shadow-hover);
          transform: translateY(-8px);
          border-color: var(--primary-red);
        }

        .chart-panel:hover,
        .low-stock-panel:hover {
          box-shadow: var(--shadow-hover);
          transform: translateY(-4px);
        }

        .low-stock-item:hover {
          background: linear-gradient(135deg, rgba(198,69,62,0.12) 0%, rgba(255,242,224,0.15) 100%);
          transform: translateX(4px);
        }
      }

      /* ---------- Animations (an- prefixed so they can't clash with the header's) ---------- */
      @keyframes anSlideDown {
        from { opacity: 0; transform: translateY(-20px); }
        to   { opacity: 1; transform: translateY(0); }
      }

      @keyframes anFadeIn {
        from { opacity: 0; transform: translateY(20px); }
        to   { opacity: 1; transform: translateY(0); }
      }

      @keyframes anPulse {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.05); }
      }

      @media (prefers-reduced-motion: reduce) {
        .an-header, .an-title i, .kpi-grid, .analytics-grid { animation: none !important; }
        .an-btn, .kpi-card, .chart-panel, .low-stock-panel, .low-stock-item { transition: none !important; }
      }

      /* ---------- Responsive ---------- */
      @media (max-width: 768px) {
        .an-header-top {
          flex-direction: column;
          align-items: stretch;
        }

        .an-actions {
          width: 100%;
        }

        .an-actions > .an-btn,
        .an-actions > .export-dropdown {
          flex: 1 1 0;
          min-width: 0;
        }

        .export-dropdown .an-btn {
          width: 100%;
        }

        /* Menu spans the dropdown's width and stays inside the viewport */
        .export-menu {
          left: 0;
          right: 0;
          min-width: 0;
        }

        .export-menu a:hover {
          padding-left: 20px;
        }

        .an-btn {
          padding: 13px 16px;
          font-size: 0.9rem;
        }
      }

      @media (max-width: 480px) {
        .an-actions {
          flex-direction: column;
          align-items: stretch;
        }

        .an-actions > .an-btn,
        .an-actions > .export-dropdown {
          flex: none;
          width: 100%;
        }

        .low-stock-size {
          margin-left: 0;
        }

        .low-stock-badge {
          margin-left: auto;
        }

        .chart-container {
          height: 280px;
        }
      }
   </style>
</head>
<body>

<?php include 'admin_header.php'; ?>

<div class="an-page">
    <!-- Header -->
    <div class="an-header">
      <div class="an-header-top">
        <div class="an-title">
          <i class="fa-solid fa-chart-pie"></i>
          <div>
            <h1>Analytics</h1>
            <p class="an-subtitle">Monitor your store performance in real-time</p>
          </div>
        </div>
        <div class="an-actions">
          <a href="admin_analytics.php" class="an-btn">
            <i class="fa-solid fa-sync-alt"></i> Refresh
          </a>
          
          <div class="export-dropdown" id="exportDropdown">
            <button class="an-btn an-btn-secondary" onclick="toggleExportMenu()">
              <i class="fa-solid fa-download"></i> Export Reports
            </button>
            <div class="export-menu">
              <a href="export_reports.php?type=pdf" target="_blank">
                <i class="fa-solid fa-file-pdf"></i> Export as PDF
              </a>
              <a href="export_reports.php?type=excel" target="_blank">
                <i class="fa-solid fa-file-excel"></i> Export as Excel
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- KPI Cards Grid -->
    <div class="kpi-grid">
      <div class="kpi-card">
        <div class="kpi-header">
          <div class="kpi-label">
            <i class="fa-solid fa-peso-sign"></i> Total Revenue
          </div>
        </div>
        <div class="kpi-value">₱<?php echo number_format($kpi['total_revenue'], 2); ?></div>
        <div class="kpi-change">From completed orders</div>
      </div>

      <div class="kpi-card">
        <div class="kpi-header">
          <div class="kpi-label">
            <i class="fa-solid fa-box-open"></i> Total Orders
          </div>
        </div>
        <div class="kpi-value"><?php echo $kpi['total_orders']; ?></div>
        <div class="kpi-change">All time</div>
      </div>

      <div class="kpi-card">
        <div class="kpi-header">
          <div class="kpi-label">
            <i class="fa-solid fa-calendar-day"></i> Orders Today
          </div>
        </div>
        <div class="kpi-value"><?php echo $kpi['orders_today']; ?></div>
        <div class="kpi-change">Completed orders</div>
      </div>

      <div class="kpi-card">
        <div class="kpi-header">
          <div class="kpi-label">
            <i class="fa-solid fa-tags"></i> Discounts Used
          </div>
        </div>
        <div class="kpi-value">₱<?php echo number_format($kpi['total_discount'], 2); ?></div>
        <div class="kpi-change">Total savings</div>
      </div>
    </div>

    <!-- Charts Grid -->
    <div class="analytics-grid">
      <!-- Top 5 Best Sellers -->
      <div class="chart-panel">
        <div class="chart-header">
          <i class="fa-solid fa-crown"></i>
          <h3>Top 5 Best Sellers</h3>
        </div>
        <div class="chart-container">
          <canvas id="bestSellersChart"></canvas>
        </div>
      </div>

      <!-- Orders by Status -->
      <div class="chart-panel">
        <div class="chart-header">
          <i class="fa-solid fa-clipboard-list"></i>
          <h3>Orders by Status</h3>
        </div>
        <div class="chart-container">
          <canvas id="ordersPieChart"></canvas>
        </div>
      </div>
    </div>

    <!-- Second Row -->
    <div class="analytics-grid">
      <!-- Sales Trend -->
      <div class="chart-panel">
        <div class="chart-header">
          <i class="fa-solid fa-chart-line"></i>
          <h3>Sales Trend (Last 7 Days)</h3>
        </div>
        <div class="chart-container">
          <canvas id="salesChart"></canvas>
        </div>
      </div>

      <!-- Low Stock Products -->
      <div class="low-stock-panel">
        <div class="chart-header">
          <i class="fa-solid fa-triangle-exclamation"></i>
          <h3>Low Stock Alert</h3>
        </div>
        <?php if(mysqli_num_rows($low_stock) > 0): ?>
          <ul class="low-stock-list">
            <?php while($l = mysqli_fetch_assoc($low_stock)): ?>
              <li class="low-stock-item">
                <div class="low-stock-info">
                  <i class="fa-solid fa-cube"></i>
                  <span class="low-stock-name"><?php echo htmlspecialchars($l['name']); ?></span>
                  <span class="low-stock-size"><?php echo strtoupper(htmlspecialchars($l['size'])); ?></span>
                </div>
                <span class="low-stock-badge">Stock: <?php echo intval($l['stock']); ?></span>
              </li>
            <?php endwhile; ?>
          </ul>
        <?php else: ?>
          <div class="empty-state">
            <i class="fa-solid fa-check-circle"></i>
            <p>All products are well stocked!</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
</div>

<script>
function toggleExportMenu() {
  const dropdown = document.getElementById('exportDropdown');
  dropdown.classList.toggle('active');
}

// Close menu when clicking outside
document.addEventListener('click', function(event) {
  const dropdown = document.getElementById('exportDropdown');
  if (!dropdown.contains(event.target)) {
    dropdown.classList.remove('active');
  }
});

// Truncates a label for display on the chart axis, but keeps the
// full original text available for the tooltip title.
function truncateLabel(label, maxLength) {
  if (typeof label !== 'string') return label;
  if (label.length <= maxLength) return label;
  return label.substring(0, maxLength - 1).trim() + '…';
}

document.addEventListener('DOMContentLoaded', function () {
    const bestSellersFullLabels = <?php echo json_encode($best_products_labels); ?>;
    const bestSellersData = <?php echo json_encode($best_products_data); ?>;

    const mqMobile = window.matchMedia('(max-width: 640px)');

    // ---------- Best Sellers Bar Chart ----------
    // Desktop: vertical bars. Mobile: horizontal bars so long product names stay readable.
    let bestSellersChart = null;
    function buildBestSellersChart() {
        const isMobile = mqMobile.matches;
        const shortLabels = bestSellersFullLabels.map(l => truncateLabel(l, isMobile ? 18 : 14));
        const valueAxis = {
            beginAtZero: true,
            ticks: {
                color: '#5E1F13',
                font: { weight: 700, size: isMobile ? 11 : 12 },
                precision: 0
            },
            grid: { color: 'rgba(240, 230, 216, 0.3)' }
        };
        const labelAxis = {
            ticks: {
                color: '#5E1F13',
                font: { weight: 700, size: isMobile ? 11 : 12 },
                autoSkip: false,
                maxRotation: isMobile ? 0 : 40,
                minRotation: 0
            },
            grid: { display: false }
        };

        if (bestSellersChart) bestSellersChart.destroy();

        bestSellersChart = new Chart(document.getElementById('bestSellersChart').getContext('2d'), {
            type: 'bar',
            data: {
                labels: shortLabels,
                datasets: [{
                    label: 'Units Sold',
                    data: bestSellersData,
                    backgroundColor: '#C6453E',
                    borderRadius: 10,
                    maxBarThickness: isMobile ? 28 : 50,
                    borderSkipped: false
                }]
            },
            options: {
                indexAxis: isMobile ? 'y' : 'x',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        backgroundColor: 'rgba(94, 31, 19, 0.8)',
                        padding: 12,
                        titleFont: { size: 14, weight: 'bold' },
                        bodyFont: { size: 13 },
                        displayColors: false,
                        callbacks: {
                            // Show the FULL product name in the tooltip title,
                            // even though the axis label is truncated.
                            title: function(items) {
                                const idx = items[0].dataIndex;
                                return bestSellersFullLabels[idx];
                            },
                            label: function(ctx) {
                                const v = isMobile ? ctx.parsed.x : ctx.parsed.y;
                                return v + ' units sold';
                            }
                        }
                    }
                },
                scales: isMobile
                    ? { x: valueAxis, y: labelAxis }
                    : { y: valueAxis, x: labelAxis }
            }
        });
    }
    buildBestSellersChart();

    // Rebuild when crossing the mobile breakpoint (rotate phone, resize window)
    if (mqMobile.addEventListener) {
        mqMobile.addEventListener('change', buildBestSellersChart);
    } else if (mqMobile.addListener) {
        mqMobile.addListener(buildBestSellersChart);
    }

    // ---------- Orders Status Doughnut ----------
    new Chart(document.getElementById('ordersPieChart').getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: ['Pending', 'Accepted', 'Preparing', 'Out for Delivery', 'Completed', 'Cancelled'],
            datasets: [{
                data: <?php echo json_encode($status_counts); ?>,
                backgroundColor: [
                    '#ea580c',
                    '#D4A574',
                    '#0369a1',
                    '#b45309',
                    '#C6453E',
                    '#D97E6A'
                ],
                borderColor: '#fff2e0',
                borderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'bottom',
                    labels: {
                        color: '#5E1F13',
                        font: { size: mqMobile.matches ? 11 : 12, weight: 600 },
                        padding: mqMobile.matches ? 10 : 15,
                        boxWidth: 12
                    }
                },
                tooltip: {
                    backgroundColor: 'rgba(94, 31, 19, 0.8)',
                    padding: 12,
                    callbacks: {
                        label: function(ctx) { return ctx.label + ': ' + ctx.parsed + ' orders'; }
                    }
                }
            },
            cutout: '70%'
        }
    });

    // ---------- Sales 7 days Line Chart ----------
    new Chart(document.getElementById('salesChart').getContext('2d'), {
        type: 'line',
        data: {
            labels: <?php echo json_encode($sales_labels); ?>,
            datasets: [{
                label: 'Sales (₱)',
                data: <?php echo json_encode($sales_totals); ?>,
                backgroundColor: 'rgba(198, 69, 62, 0.08)',
                borderColor: '#C6453E',
                borderWidth: 3,
                pointRadius: mqMobile.matches ? 5 : 8,
                pointHoverRadius: mqMobile.matches ? 7 : 10,
                pointBackgroundColor: '#5E1F13',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(94, 31, 19, 0.8)',
                    padding: 12,
                    callbacks: {
                        label: function(ctx) { return '₱' + ctx.parsed.y.toLocaleString(); }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        color: '#5E1F13',
                        font: { weight: 700, size: mqMobile.matches ? 11 : 12 },
                        callback: function(value) { return '₱' + value.toLocaleString(); }
                    },
                    grid: { color: 'rgba(240, 230, 216, 0.3)' }
                },
                x: {
                    ticks: {
                        color: '#5E1F13',
                        font: { weight: 700, size: mqMobile.matches ? 11 : 12 },
                        maxRotation: mqMobile.matches ? 45 : 0,
                        autoSkip: true,
                        maxTicksLimit: mqMobile.matches ? 4 : 7
                    },
                    grid: { display: false }
                }
            }
        }
    });
});
</script>
<?php include 'admin_chatbot.php'; ?>
<?php include 'admin_footer.php'; ?>
</body>
</html>