<?php
// inventory_functions.php
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

        $parsed[] = ['name' => $name, 'qty' => $qty, 'size' => trim($m[3])];
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
    $size_ing_stmt = $conn->prepare(
        "SELECT i.id AS ingredient_id, i.ingredient_name, i.unit, psi.quantity_used
         FROM product_size_ingredients psi
         JOIN product_sizes ps ON psi.product_size_id = ps.id
         JOIN inventory i ON psi.ingredient_id = i.id
         WHERE ps.product_id = ? AND ps.size = ?"
    );
    if (!$prod_stmt || !$ing_stmt || !$size_ing_stmt) {
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

        $rows = $ing_res->fetch_all(MYSQLI_ASSOC);

        $size_ing_stmt->bind_param("is", $product_id, $line['size']);
        $size_ing_stmt->execute();
        $rows = array_merge($rows, $size_ing_stmt->get_result()->fetch_all(MYSQLI_ASSOC));

        foreach ($rows as $row) {
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
    $size_ing_stmt->close();

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

?>