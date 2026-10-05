<?php
include 'config.php';

$admin_id = $_SESSION['admin_id'];

if(!isset($admin_id)){
   header('location:login.php');
   exit;
}

// Get export type
$export_type = isset($_GET['type']) ? $_GET['type'] : 'pdf';

// --- Fetch Data ---
// Top 5 Best Sellers
$top_products = mysqli_query($conn, "
    SELECT 
        SUBSTRING_INDEX(SUBSTRING_INDEX(total_products, ',', numbers.n), ',', -1) as product_name,
        COUNT(*) as total_sold
    FROM orders
    JOIN (SELECT 1 as n UNION SELECT 2 UNION SELECT 3 UNION SELECT 4 UNION SELECT 5) numbers ON (LENGTH(total_products) - LENGTH(REPLACE(total_products, ',', '')) >= numbers.n - 1)
    WHERE payment_status = 'completed'
    GROUP BY product_name
    ORDER BY total_sold DESC
    LIMIT 5
") or die(mysqli_error($conn));

$best_products = [];
while($p = mysqli_fetch_assoc($top_products)) {
    $product = trim($p['product_name']);
    $product = preg_replace('/\s*\(\d+\s*x\s*[A-Za-z0-9]+\)\s*$/i', '', $product);
    $best_products[] = [
        'name' => $product,
        'sold' => (int)$p['total_sold']
    ];
}

// Sales per Day 7-DAYS
$sales_data = [];
for ($i = 6; $i >= 0; $i--) {
   $day = date('Y-m-d', strtotime("-$i days"));
   $result = mysqli_query($conn, "SELECT SUM(total_price) as total FROM `orders` WHERE payment_status = 'completed' AND DATE(placed_on) = '$day' ");
   $row = mysqli_fetch_assoc($result);
   $sales_data[] = [
       'date' => date('M d', strtotime($day)),
       'amount' => $row && $row['total'] ? floatval($row['total']) : 0
   ];
}

// Orders by Status
$order_statuses = ['pending', 'accepted', 'preparing', 'out for delivery', 'completed', 'cancelled'];
$status_data = [];
foreach ($order_statuses as $status) {
   $count = mysqli_num_rows(mysqli_query($conn, "SELECT id FROM `orders` WHERE payment_status = '$status' "));
   $status_data[] = [
       'status' => ucfirst($status),
       'count' => $count
   ];
}

// Low Stock Products
$low_stock = mysqli_query($conn, "
   SELECT p.name, s.size, s.stock 
   FROM product_sizes s 
   JOIN products p ON s.product_id = p.id 
   WHERE s.stock <= 5 
   ORDER BY s.stock ASC 
   LIMIT 5
") or die(mysqli_error($conn));

$low_stock_data = [];
while($l = mysqli_fetch_assoc($low_stock)) {
    $low_stock_data[] = $l;
}

// KPI Metrics
$kpi = [];
$result = mysqli_query($conn, "SELECT SUM(total_price) as total_revenue FROM orders WHERE payment_status = 'completed'");
$row = mysqli_fetch_assoc($result);
$kpi['total_revenue'] = $row && $row['total_revenue'] ? floatval($row['total_revenue']) : 0;

$today = date('Y-m-d');
$result = mysqli_query($conn, "SELECT COUNT(*) as orders_today FROM orders WHERE payment_status = 'completed' AND DATE(placed_on) = '$today'");
$row = mysqli_fetch_assoc($result);
$kpi['orders_today'] = $row && $row['orders_today'] ? intval($row['orders_today']) : 0;

$result = mysqli_query($conn, "SELECT SUM(discount_used) as total_discount FROM orders WHERE payment_status = 'completed'");
$row = mysqli_fetch_assoc($result);
$kpi['total_discount'] = $row && $row['total_discount'] ? floatval($row['total_discount']) : 0;

$result = mysqli_query($conn, "SELECT COUNT(*) as total_orders FROM orders");
$row = mysqli_fetch_assoc($result);
$kpi['total_orders'] = $row && $row['total_orders'] ? intval($row['total_orders']) : 0;

// --- Export Handler ---
if($export_type === 'pdf') {
    // Check if TCPDF exists
    if(!file_exists('vendor/autoload.php')) {
        // Fallback: Generate HTML that can be printed as PDF
        generateHTMLPDF($kpi, $best_products, $sales_data, $status_data, $low_stock_data);
    } else {
        // Use TCPDF
        require_once('vendor/autoload.php');
        generateTCPDFReport($kpi, $best_products, $sales_data, $status_data, $low_stock_data);
    }
} else if($export_type === 'excel') {
    generateExcelReport($kpi, $best_products, $sales_data, $status_data, $low_stock_data);
}

// --- PDF Generation Function (HTML Fallback) ---
function generateHTMLPDF($kpi, $best_products, $sales_data, $status_data, $low_stock_data) {
    $timestamp = date('Y-m-d H:i:s');
    $filename = 'Analytics_Report_' . date('Y-m-d_Hi') . '.html';
    
    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Analytics Report</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                line-height: 1.6;
                color: #333;
                background: #f5f5f5;
            }
            .pdf-container {
                max-width: 900px;
                margin: 0 auto;
                background: white;
                padding: 40px;
                box-shadow: 0 0 20px rgba(0,0,0,0.1);
            }
            .header {
                border-bottom: 4px solid #C6453E;
                padding-bottom: 30px;
                margin-bottom: 30px;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .header-left h1 {
                font-size: 2.5em;
                color: #5E1F13;
                margin-bottom: 5px;
            }
            .header-left p {
                color: #664C47;
                font-size: 1.1em;
            }
            .header-right {
                text-align: right;
                color: #666;
            }
            .header-right .date {
                font-size: 0.9em;
                color: #999;
            }
            .header-right .badge {
                display: inline-block;
                background: #C6453E;
                color: white;
                padding: 8px 16px;
                border-radius: 20px;
                margin-top: 10px;
                font-weight: bold;
                font-size: 0.9em;
            }

            /* KPI Cards */
            .kpi-section {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 20px;
                margin-bottom: 40px;
                page-break-inside: avoid;
            }
            .kpi-card {
                background: linear-gradient(135deg, #FFF5F0 0%, #FFFBF7 100%);
                border: 2px solid #F0E6D8;
                border-left: 5px solid #C6453E;
                padding: 20px;
                border-radius: 8px;
                text-align: center;
            }
            .kpi-card h3 {
                font-size: 0.9em;
                color: #664C47;
                text-transform: uppercase;
                letter-spacing: 1px;
                margin-bottom: 10px;
                font-weight: 700;
            }
            .kpi-card .value {
                font-size: 1.8em;
                color: #C6453E;
                font-weight: 900;
                margin-bottom: 5px;
            }
            .kpi-card .subtext {
                font-size: 0.85em;
                color: #999;
            }

            /* Section Headers */
            .section-header {
                font-size: 1.6em;
                color: #5E1F13;
                border-bottom: 3px solid #C6453E;
                padding-bottom: 12px;
                margin-top: 40px;
                margin-bottom: 25px;
                font-weight: 900;
                page-break-inside: avoid;
            }

            /* Tables */
            table {
                width: 100%;
                border-collapse: collapse;
                margin-bottom: 20px;
                page-break-inside: avoid;
            }
            table th {
                background: #C6453E;
                color: white;
                padding: 15px;
                text-align: left;
                font-weight: 700;
                font-size: 0.95em;
            }
            table td {
                padding: 12px 15px;
                border-bottom: 1px solid #f0e6d8;
            }
            table tr:nth-child(even) {
                background: #FFF9F6;
            }
            table tr:hover {
                background: #FFF2E0;
            }

            /* Stats Row */
            .stats-row {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 20px;
                margin-bottom: 30px;
            }

            /* Empty State */
            .empty-state {
                background: #FFF9F6;
                border: 2px dashed #C6453E;
                padding: 30px;
                text-align: center;
                color: #999;
                border-radius: 8px;
            }

            /* Footer */
            .footer {
                border-top: 2px solid #f0e6d8;
                padding-top: 20px;
                margin-top: 40px;
                text-align: center;
                color: #999;
                font-size: 0.9em;
            }

            /* Page Break */
            .page-break {
                page-break-after: always;
                margin: 40px 0;
            }

            /* Print Styles */
            @media print {
                body { background: white; }
                .pdf-container { box-shadow: none; max-width: 100%; }
                .page-break { page-break-after: always; }
            }

            @media (max-width: 900px) {
                .kpi-section { grid-template-columns: repeat(2, 1fr); }
                .stats-row { grid-template-columns: 1fr; }
            }
        </style>
    </head>
    <body>
        <div class="pdf-container">
            <!-- Header -->
            <div class="header">
                <div class="header-left">
                    <h1>Analytics Report</h1>
                    <p>Six Origins Cafe</p>
                </div>
                <div class="header-right">
                    <div class="date">Generated: <?php echo $timestamp; ?></div>
                    <div class="badge">MONTHLY REPORT</div>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="kpi-section">
                <div class="kpi-card">
                    <h3>Total Revenue</h3>
                    <div class="value">₱<?php echo number_format($kpi['total_revenue'], 2); ?></div>
                    <div class="subtext">Completed Orders</div>
                </div>
                <div class="kpi-card">
                    <h3>Total Orders</h3>
                    <div class="value"><?php echo $kpi['total_orders']; ?></div>
                    <div class="subtext">All Time</div>
                </div>
                <div class="kpi-card">
                    <h3>Orders Today</h3>
                    <div class="value"><?php echo $kpi['orders_today']; ?></div>
                    <div class="subtext">Completed Today</div>
                </div>
                <div class="kpi-card">
                    <h3>Discounts Used</h3>
                    <div class="value">₱<?php echo number_format($kpi['total_discount'], 2); ?></div>
                    <div class="subtext">Total Savings</div>
                </div>
            </div>

            <!-- Top 5 Best Sellers -->
            <div class="section-header">Top 5 Best Sellers</div>
            <table>
                <thead>
                    <tr>
                        <th style="width: 10%;">#</th>
                        <th style="width: 70%;">Product Name</th>
                        <th style="width: 20%; text-align: right;">Units Sold</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    if(count($best_products) > 0):
                        foreach($best_products as $idx => $product): 
                    ?>
                    <tr>
                        <td style="font-weight: bold; color: #C6453E;"><?php echo $idx + 1; ?></td>
                        <td><?php echo htmlspecialchars($product['name']); ?></td>
                        <td style="text-align: right; font-weight: 700;"><?php echo $product['sold']; ?></td>
                    </tr>
                    <?php 
                        endforeach;
                    else:
                    ?>
                    <tr>
                        <td colspan="3" style="text-align: center; color: #999;">No sales data available</td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- 7-Day Sales Trend -->
            <div class="section-header">7-Day Sales Trend</div>
            <table>
                <thead>
                    <tr>
                        <th style="width: 50%;">Date</th>
                        <th style="width: 50%; text-align: right;">Sales Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($sales_data as $sale): ?>
                    <tr>
                        <td><?php echo $sale['date']; ?></td>
                        <td style="text-align: right; font-weight: 700; color: #C6453E;">₱<?php echo number_format($sale['amount'], 2); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="page-break"></div>

            <!-- Orders by Status -->
            <div class="section-header">Orders by Status</div>
            <table>
                <thead>
                    <tr>
                        <th style="width: 70%;">Status</th>
                        <th style="width: 30%; text-align: right;">Count</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($status_data as $status): ?>
                    <tr>
                        <td>
                            <?php 
                            $badge_colors = [
                                'Pending' => '#ea580c',
                                'Accepted' => '#D4A574',
                                'Preparing' => '#0369a1',
                                'Out for delivery' => '#b45309',
                                'Completed' => '#C6453E',
                                'Cancelled' => '#D97E6A'
                            ];
                            $color = $badge_colors[$status['status']] ?? '#666';
                            ?>
                            <span style="display: inline-block; background: <?php echo $color; ?>; color: white; padding: 5px 12px; border-radius: 6px; font-size: 0.9em; font-weight: bold;">
                                <?php echo $status['status']; ?>
                            </span>
                        </td>
                        <td style="text-align: right; font-weight: 700; font-size: 1.1em;"><?php echo $status['count']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- Low Stock Alert -->
            <div class="section-header">Low Stock Alert</div>
            <?php if(count($low_stock_data) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th style="width: 50%;">Product Name</th>
                        <th style="width: 25%;">Size</th>
                        <th style="width: 25%; text-align: right;">Current Stock</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($low_stock_data as $stock): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($stock['name']); ?></td>
                        <td style="text-align: center;"><?php echo strtoupper(htmlspecialchars($stock['size'])); ?></td>
                        <td style="text-align: right;">
                            <span style="background: #FFE8E5; color: #C6453E; padding: 4px 8px; border-radius: 4px; font-weight: bold;">
                                <?php echo $stock['stock']; ?> units
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state">
                All products are well stocked!
            </div>
            <?php endif; ?>

            <!-- Footer -->
            <div class="footer">
                <p>This report was automatically generated on <?php echo $timestamp; ?></p>
                <p>© 2026 Six Origins Cafe — All Rights Reserved</p>
            </div>
        </div>

        <script>
            // Auto-print when opened
            window.onload = function() {
                window.print();
            };
        </script>
    </body>
    </html>
    <?php
    $html = ob_get_clean();
    
    // Output as HTML file
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $html;
    exit;
}

// --- TCPDF Generation ---
function generateTCPDFReport($kpi, $best_products, $sales_data, $status_data, $low_stock_data) {
    require_once('vendor/autoload.php');
    
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_PAGE_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(TRUE, 15);
    $pdf->AddPage();
    
    $timestamp = date('Y-m-d H:i:s');
    
    // Header
    $html = '
    <style>
        body { font-family: helvetica; }
        .header { border-bottom: 3px solid #C6453E; padding-bottom: 15px; margin-bottom: 20px; }
        .header h1 { font-size: 28px; color: #5E1F13; margin: 0; }
        .header p { color: #664C47; margin: 5px 0 0 0; }
        .timestamp { font-size: 10px; color: #999; }
        
        .kpi-table { width: 100%; margin: 20px 0; }
        .kpi-cell { 
            border: 1px solid #F0E6D8;
            border-left: 4px solid #C6453E;
            padding: 12px;
            text-align: center;
            background: #FFFBF7;
        }
        .kpi-label { font-size: 9px; color: #664C47; font-weight: bold; text-transform: uppercase; }
        .kpi-value { font-size: 16px; color: #C6453E; font-weight: bold; margin: 5px 0; }
        
        .section-title { font-size: 14px; color: #5E1F13; font-weight: bold; border-bottom: 2px solid #C6453E; padding-bottom: 8px; margin: 20px 0 10px 0; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
        th { background: #C6453E; color: white; padding: 8px; text-align: left; font-size: 10px; font-weight: bold; }
        td { padding: 8px; border-bottom: 1px solid #f0e6d8; font-size: 10px; }
        tr:nth-child(even) { background: #FFF9F6; }
    </style>
    
    <div class="header">
        <h1>Analytics Report</h1>
        <p>Six Origins Cafe</p>
        <div class="timestamp">Generated: ' . $timestamp . '</div>
    </div>
    
    <table class="kpi-table">
        <tr>
            <td class="kpi-cell">
                <div class="kpi-label">Total Revenue</div>
                <div class="kpi-value">₱' . number_format($kpi['total_revenue'], 2) . '</div>
            </td>
            <td class="kpi-cell">
                <div class="kpi-label">Total Orders</div>
                <div class="kpi-value">' . $kpi['total_orders'] . '</div>
            </td>
            <td class="kpi-cell">
                <div class="kpi-label">Orders Today</div>
                <div class="kpi-value">' . $kpi['orders_today'] . '</div>
            </td>
            <td class="kpi-cell">
                <div class="kpi-label">Discounts</div>
                <div class="kpi-value">₱' . number_format($kpi['total_discount'], 2) . '</div>
            </td>
        </tr>
    </table>
    
    <div class="section-title">Top 5 Best Sellers</div>
    <table>
        <tr>
            <th style="width: 15%;">#</th>
            <th style="width: 65%;">Product Name</th>
            <th style="width: 20%; text-align: right;">Units Sold</th>
        </tr>';
    
    if(count($best_products) > 0) {
        foreach($best_products as $idx => $product) {
            $html .= '<tr>
                <td style="font-weight: bold; color: #C6453E;">' . ($idx + 1) . '</td>
                <td>' . htmlspecialchars($product['name']) . '</td>
                <td style="text-align: right; font-weight: bold;">' . $product['sold'] . '</td>
            </tr>';
        }
    } else {
        $html .= '<tr><td colspan="3" style="text-align: center; color: #999;">No sales data available</td></tr>';
    }
    
    $html .= '</table>
    
    <div class="section-title">7-Day Sales Trend</div>
    <table>
        <tr>
            <th style="width: 50%;">Date</th>
            <th style="width: 50%; text-align: right;">Sales Amount</th>
        </tr>';
    
    foreach($sales_data as $sale) {
        $html .= '<tr>
            <td>' . $sale['date'] . '</td>
            <td style="text-align: right; font-weight: bold; color: #C6453E;">₱' . number_format($sale['amount'], 2) . '</td>
        </tr>';
    }
    
    $html .= '</table>';
    
    $pdf->writeHTML($html, true, false, true, false, '');
    $pdf->AddPage();
    
    // Page 2 - Status and Low Stock
    $html2 = '
    <div class="section-title">Orders by Status</div>
    <table>
        <tr>
            <th style="width: 70%;">Status</th>
            <th style="width: 30%; text-align: right;">Count</th>
        </tr>';
    
    foreach($status_data as $status) {
        $html2 .= '<tr>
            <td>' . $status['status'] . '</td>
            <td style="text-align: right; font-weight: bold;">' . $status['count'] . '</td>
        </tr>';
    }
    
    $html2 .= '</table>
    
    <div class="section-title">Low Stock Alert</div>';
    
    if(count($low_stock_data) > 0) {
        $html2 .= '<table>
            <tr>
                <th style="width: 50%;">Product</th>
                <th style="width: 25%;">Size</th>
                <th style="width: 25%; text-align: right;">Stock</th>
            </tr>';
        
        foreach($low_stock_data as $stock) {
            $html2 .= '<tr>
                <td>' . htmlspecialchars($stock['name']) . '</td>
                <td>' . strtoupper($stock['size']) . '</td>
                <td style="text-align: right; font-weight: bold; color: #C6453E;">' . $stock['stock'] . ' units</td>
            </tr>';
        }
        $html2 .= '</table>';
    } else {
        $html2 .= '<p style="text-align: center; color: #999; padding: 20px;">All products are well stocked!</p>';
    }
    
    $html2 .= '<p style="border-top: 1px solid #f0e6d8; padding-top: 15px; margin-top: 30px; text-align: center; font-size: 9px; color: #999;">
        © 2026 Six Origins Cafe — All Rights Reserved
    </p>';
    
    $pdf->writeHTML($html2, true, false, true, false, '');
    
    $filename = 'Analytics_Report_' . date('Y-m-d_Hi') . '.pdf';
    $pdf->Output($filename, 'D');
    exit;
}

// --- Excel/CSV Generation ---
function generateExcelReport($kpi, $best_products, $sales_data, $status_data, $low_stock_data) {
    $timestamp = date('Y-m-d H:i:s');
    $filename = 'Analytics_Report_' . date('Y-m-d_Hi') . '.csv';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // BOM for Excel UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Header
    fputcsv($output, ['ANALYTICS REPORT - Six Origins Cafe']);
    fputcsv($output, ['Generated: ' . $timestamp]);
    fputcsv($output, []);
    
    // KPI Metrics
    fputcsv($output, ['KEY PERFORMANCE INDICATORS']);
    fputcsv($output, ['Metric', 'Value']);
    fputcsv($output, ['Total Revenue', '₱' . number_format($kpi['total_revenue'], 2)]);
    fputcsv($output, ['Total Orders', $kpi['total_orders']]);
    fputcsv($output, ['Orders Today', $kpi['orders_today']]);
    fputcsv($output, ['Total Discounts', '₱' . number_format($kpi['total_discount'], 2)]);
    fputcsv($output, []);
    
    // Top 5 Best Sellers
    fputcsv($output, ['TOP 5 BEST SELLERS']);
    fputcsv($output, ['Rank', 'Product Name', 'Units Sold']);
    foreach($best_products as $idx => $product) {
        fputcsv($output, [$idx + 1, $product['name'], $product['sold']]);
    }
    fputcsv($output, []);
    
    // 7-Day Sales
    fputcsv($output, ['7-DAY SALES TREND']);
    fputcsv($output, ['Date', 'Sales Amount']);
    foreach($sales_data as $sale) {
        fputcsv($output, [$sale['date'], '₱' . number_format($sale['amount'], 2)]);
    }
    fputcsv($output, []);
    
    // Orders by Status
    fputcsv($output, ['ORDERS BY STATUS']);
    fputcsv($output, ['Status', 'Order Count']);
    foreach($status_data as $status) {
        fputcsv($output, [$status['status'], $status['count']]);
    }
    fputcsv($output, []);
    
    // Low Stock
    fputcsv($output, ['LOW STOCK ALERT']);
    fputcsv($output, ['Product Name', 'Size', 'Current Stock']);
    foreach($low_stock_data as $stock) {
        fputcsv($output, [$stock['name'], strtoupper($stock['size']), $stock['stock'] . ' units']);
    }
    
    fclose($output);
    exit;
}
?>
