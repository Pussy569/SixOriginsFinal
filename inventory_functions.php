<?php
// inventory_functions.php
// Place in includes/ or project root and require where needed.
// Requires $conn (mysqli) to be available when called.

/**
 * ---------------------------------------------------------------
 * NOTE ON SCHEMA (IMPORTANT)
 * ---------------------------------------------------------------
 * This app stores order contents as a text string on the order row:
 *   orders.total_products = "Latte (2 x Medium) [Preference: Oat Milk]||Croissant (1 x Regular)"
 * (items separated by '||', with optional trailing [Preference: ...] /
 * [Extras: ...] tags on each item) and the recipe linking a product to
 * inventory items lives in:
 *   product_ingredients (product_id, ingredient_id, quantity_used)
 * joined against:
 *   inventory (id, ingredient_name, unit, category, quantity, min_stock_level)
 *
 * This is the SAME data source order_ingredients_helper.php uses for the
 * "Ingredients Used" preview on admin_orders.php, so deduction now matches
 * exactly what's displayed to the admin.
 *
 * ---------------------------------------------------------------
 */

/**
 * Parse an order's `total_products` string, e.g.
 *   "Latte (2 x Medium) [Preference: Oat Milk]||Croissant (1 x Regular)"
 * into [['name' => 'Latte', 'qty' => 2], ['name' => 'Croissant', 'qty' => 1]]
 *
 * ✅ FIXED: items are separated with '||' (not ','), because a single item's
 * [Preference: ...] / [Extras: ...] note can itself contain commas — splitting
 * on ',' cuts lines in the wrong place. Any trailing [Preference:]/[Extras:]
 * tags are stripped before the "(Qty x Size)" match, so the regex actually
 * matches the line instead of silently skipping it.
 *
 * Same parsing rule as order_ingredients_helper.php's getOrderIngredientsUsage()
 * so deduction always matches what's shown on the admin_orders.php card.
 */
function parseOrderProductLines($total_products_string) {
    $parsed = [];
    if (empty($total_products_string)) return $parsed;

    $lines = explode('||', $total_products_string);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        // Strip trailing [Preference: ...] / [Extras: ...] tags so the
        // "(Qty x Size)" pattern below matches the underlying product line.
        $line = trim(preg_replace('/\s*\[(Preference|Extras):\s*.*?\]/i', '', $line));
        if ($line === '') continue;

        if (!preg_match('/^(.*)\(\s*(\d+)\s*x\s*(.+?)\s*\)\s*$/i', $line, $m)) continue;

        $name = trim($m[1]);
        $qty  = intval($m[2]);
        if ($name === '' || $qty <= 0) continue;

        $parsed[] = ['name' => $name, 'qty' => $qty];
    }
    return $parsed;
}

/**
 * Given an order_id, sum up every ingredient/packaging item needed across
 * all products in that order, using product_ingredients + inventory.
 * Returns: ingredient_id => ['qty'=>float total, 'unit'=>str, 'name'=>str]
 */
function buildIngredientTotalsForOrder($conn, $order_id) {
    $totals = [];

    $order_stmt = $conn->prepare("SELECT total_products FROM orders WHERE id = ?");
    if (!$order_stmt) throw new Exception("DB prepare error (orders): " . $conn->error);
    $order_stmt->bind_param("i", $order_id);
    $order_stmt->execute();
    $order_row = $order_stmt->get_result()->fetch_assoc();
    $order_stmt->close();

    if (!$order_row || empty($order_row['total_products'])) {
        return $totals;
    }

    $product_lines = parseOrderProductLines($order_row['total_products']);
    if (count($product_lines) === 0) return $totals;

    $prod_stmt = $conn->prepare("SELECT id FROM products WHERE name = ?");
    $ing_stmt  = $conn->prepare(
        "SELECT i.id AS ingredient_id, i.ingredient_name, i.unit, pi.quantity_used
         FROM product_ingredients pi
         JOIN inventory i ON pi.ingredient_id = i.id
         WHERE pi.product_id = ?"
    );
    if (!$prod_stmt || !$ing_stmt) {
        throw new Exception("DB prepare error (products/product_ingredients): " . $conn->error);
    }

    foreach ($product_lines as $line) {
        $prod_stmt->bind_param("s", $line['name']);
        $prod_stmt->execute();
        $prod_row = $prod_stmt->get_result()->fetch_assoc();
        if (!$prod_row) continue; // product may have been deleted/renamed since order was placed

        $product_id = intval($prod_row['id']);

        $ing_stmt->bind_param("i", $product_id);
        $ing_stmt->execute();
        $ing_res = $ing_stmt->get_result();

        while ($row = $ing_res->fetch_assoc()) {
            $ing_id   = intval($row['ingredient_id']);
            $per_unit = floatval($row['quantity_used']);
            $unit     = $row['unit'] ?? '';
            $name     = $row['ingredient_name'];
            $total    = $per_unit * $line['qty'];

            if (!isset($totals[$ing_id])) {
                $totals[$ing_id] = ['qty' => 0.0, 'unit' => $unit, 'name' => $name];
            }
            $totals[$ing_id]['qty'] += $total;
        }
    }

    $prod_stmt->close();
    $ing_stmt->close();

    return $totals;
}

/**
 * Deduct inventory when an order is marked COMPLETED.
 * Returns ['success' => bool, 'items' => [display strings], 'message' => string on failure]
 */
function deductInventoryForOrder($conn, $order_id, $admin_id = null) {
    $results = ['success' => false, 'items' => [], 'message' => ''];

    try {
        $to_deduct = buildIngredientTotalsForOrder($conn, $order_id);
    } catch (Exception $e) {
        $results['message'] = $e->getMessage();
        return $results;
    }

    if (count($to_deduct) === 0) {
        $results['success'] = true;
        $results['items'][] = 'No ingredients/packaging linked to the product(s) in this order';
        return $results;
    }

    $conn->begin_transaction();
    try {
        $getInv = $conn->prepare("SELECT quantity FROM inventory WHERE id = ? FOR UPDATE");
        $updInv = $conn->prepare("UPDATE inventory SET quantity = ? WHERE id = ?");
        $logIns = $conn->prepare(
            "INSERT INTO inventory_usage_log (order_id, ingredient_id, quantity_deducted, unit, usage_type, notes)
             VALUES (?, ?, ?, ?, 'order', ?)"
        );
        if (!$getInv || !$updInv || !$logIns) {
            throw new Exception("DB prepare error (deduct): " . $conn->error);
        }

        foreach ($to_deduct as $ing_id => $info) {
            $deduct_qty = (float)$info['qty'];
            $unit = $info['unit'];
            $name = $info['name'];

            $getInv->bind_param("i", $ing_id);
            $getInv->execute();
            $invrow = $getInv->get_result()->fetch_assoc();
            $current = $invrow ? floatval($invrow['quantity']) : 0.0;

            $new_qty = $current - $deduct_qty;
            $short = false;
            if ($new_qty < 0) {
                $short = true;
                $new_qty = 0;
            }

            $updInv->bind_param("di", $new_qty, $ing_id);
            if (!$updInv->execute()) {
                throw new Exception("Failed to update inventory for {$name}: " . $updInv->error);
            }

            $note = 'Deducted for order #' . $order_id . ($admin_id ? " by admin_id {$admin_id}" : '');
            $logIns->bind_param("iidss", $order_id, $ing_id, $deduct_qty, $unit, $note);
            if (!$logIns->execute()) {
                throw new Exception("Failed to write inventory log for {$name}: " . $logIns->error);
            }

            $results['items'][] = $name . ': ' . number_format($deduct_qty, 2) . ' ' . $unit
                . ($short ? ' (⚠️ stock ran out, capped at 0)' : '');
        }

        $getInv->close();
        $updInv->close();
        $logIns->close();

        $conn->commit();
        $results['success'] = true;
        return $results;

    } catch (Exception $e) {
        $conn->rollback();
        $results['success'] = false;
        $results['message'] = $e->getMessage();
        return $results;
    }
}

/**
 * Restore inventory for a cancelled order that had already been completed
 * (inverse of deductInventoryForOrder).
 * Returns ['success' => bool, 'items' => [display strings], 'message' => string on failure]
 */
function restoreInventoryForOrder($conn, $order_id, $admin_id = null) {
    $results = ['success' => false, 'items' => [], 'message' => ''];

    try {
        $to_restore = buildIngredientTotalsForOrder($conn, $order_id);
    } catch (Exception $e) {
        $results['message'] = $e->getMessage();
        return $results;
    }

    if (count($to_restore) === 0) {
        $results['success'] = true;
        $results['items'][] = 'No ingredients/packaging linked to the product(s) in this order';
        return $results;
    }

    $conn->begin_transaction();
    try {
        $getInv = $conn->prepare("SELECT quantity FROM inventory WHERE id = ? FOR UPDATE");
        $updInv = $conn->prepare("UPDATE inventory SET quantity = ? WHERE id = ?");
        $logIns = $conn->prepare(
            "INSERT INTO inventory_usage_log (order_id, ingredient_id, quantity_deducted, unit, usage_type, notes)
             VALUES (?, ?, ?, ?, 'restoration', ?)"
        );
        if (!$getInv || !$updInv || !$logIns) {
            throw new Exception("DB prepare error (restore): " . $conn->error);
        }

        foreach ($to_restore as $ing_id => $info) {
            $restore_qty = (float)$info['qty'];
            $unit = $info['unit'];
            $name = $info['name'];

            $getInv->bind_param("i", $ing_id);
            $getInv->execute();
            $invrow = $getInv->get_result()->fetch_assoc();
            $current = $invrow ? floatval($invrow['quantity']) : 0.0;
            $new_qty = $current + $restore_qty;

            $updInv->bind_param("di", $new_qty, $ing_id);
            if (!$updInv->execute()) {
                throw new Exception("Failed to update inventory for {$name}: " . $updInv->error);
            }

            $note = 'Restored for cancelled order #' . $order_id . ($admin_id ? " by admin_id {$admin_id}" : '');
            $logIns->bind_param("iidss", $order_id, $ing_id, $restore_qty, $unit, $note);
            if (!$logIns->execute()) {
                throw new Exception("Failed to write inventory log for {$name}: " . $logIns->error);
            }

            $results['items'][] = $name . ': ' . number_format($restore_qty, 2) . ' ' . $unit;
        }

        $getInv->close();
        $updInv->close();
        $logIns->close();

        $conn->commit();
        $results['success'] = true;
        return $results;

    } catch (Exception $e) {
        $conn->rollback();
        $results['success'] = false;
        $results['message'] = $e->getMessage();
        return $results;
    }
}

/**
 * Add waste record for a cancelled order that had already been completed.
 * Returns ['success' => bool, 'message' => string, 'count' => int]
 */
function addWasteRecord($conn, $order_id, $admin_id = null) {
    $order_stmt = $conn->prepare("SELECT * FROM `orders` WHERE id = ?");
    if (!$order_stmt) throw new Exception("DB prepare error: " . $conn->error);
    $order_stmt->bind_param("i", $order_id);
    $order_stmt->execute();
    $order_result = $order_stmt->get_result();
    $order = $order_result->fetch_assoc();
    $order_stmt->close();

    if (!$order) return ['success' => false, 'message' => 'Order not found', 'count' => 0];

    // ✅ FIXED: items are separated with '||' (not ','), and each item may have
    // a trailing [Preference: ...] / [Extras: ...] tag that's stripped here so
    // the waste log stores a clean product name rather than the raw tagged line.
    $product_lines = explode('||', $order['total_products']);
    $waste_count = 0;
    $admin_id_val = $admin_id ?? 0;

    $waste_stmt = $conn->prepare(
        "INSERT INTO waste_management (product_name, category, quantity, unit, reason, related_order_id, notes, recorded_by)
         VALUES (?, ?, 1, 'unit', ?, ?, ?, ?)"
    );
    if (!$waste_stmt) throw new Exception("DB prepare error (waste_management): " . $conn->error);

    // Placeholder order: product_name(s), category(s), reason(s), related_order_id(i), notes(s), recorded_by(i)
    foreach ($product_lines as $raw_line) {
        $product_name = trim(preg_replace('/\s*\[(Preference|Extras):\s*.*?\]/i', '', trim($raw_line)));
        if ($product_name === '') continue;

        $category = (strpos(strtolower($product_name), 'cake') !== false ||
                     strpos(strtolower($product_name), 'pastry') !== false) ? 'cake' : 'drink';
        $reason = 'cancelled_order';
        $notes = "Order #{$order_id} cancelled after completion";

        $waste_stmt->bind_param("sssisi", $product_name, $category, $reason, $order_id, $notes, $admin_id_val);
        if ($waste_stmt->execute()) {
            $waste_count++;
        }
    }

    $waste_stmt->close();

    return ['success' => true, 'message' => 'Waste record added', 'count' => $waste_count];
}
?>