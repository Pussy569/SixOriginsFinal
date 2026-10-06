<?php

if (!function_exists('getOrderIngredientsUsage')) {
    function getOrderIngredientsUsage($conn, $total_products_string) {
        $usage = [];

        if ($total_products_string === null || trim($total_products_string) === '') {
            return $usage;
        }

        // ✅ Split on '||' (same delimiter used by cart.php / orders.php /
        // admin_orders.php), not ',' — a Preference/Extras tag can contain
        // commas of its own, so comma-splitting cuts items in the wrong place.
        $items = array_filter(array_map('trim', explode('||', $total_products_string)), function ($p) {
            return $p !== '';
        });

        if (empty($items)) {
            return $usage;
        }

        // Cache product-name -> product_id lookups within this call so an
        // order with multiple lines of the same product doesn't re-query.
        $product_cache = [];

        foreach ($items as $item) {
            // ✅ Strip any [Preference: ...] / [Extras: ...] tags first, same
            // as parseOrderProducts() in admin_orders.php. Without this the
            // qty/size regex below (which expects the line to end right after
            // "(qty x size)") never matches, because real lines end with
            // trailing tag text like "[Extras: Extra Espresso Shot (+₱25.00)]".
            $base_line = trim(preg_replace('/\s*\[(Preference|Extras):\s*.*?\]/i', '', $item));

            // Match "Product Name (Qty x Size)"
            if (!preg_match('/^(.*)\(\s*(\d+)\s*x\s*(.+?)\s*\)\s*$/i', $base_line, $m)) {
                continue;
            }

            $prod_name   = trim($m[1]);
            $qty_ordered = intval($m[2]);
            $size_name   = trim($m[3]);

            if ($prod_name === '' || $qty_ordered <= 0) {
                continue;
            }

            // Resolve product_id (cached per call)
            if (!array_key_exists($prod_name, $product_cache)) {
                $p_stmt = $conn->prepare("SELECT id FROM `products` WHERE name = ?");
                if (!$p_stmt) {
                    $product_cache[$prod_name] = null;
                    continue;
                }
                $p_stmt->bind_param("s", $prod_name);
                $p_stmt->execute();
                $p_row = $p_stmt->get_result()->fetch_assoc();
                $p_stmt->close();
                $product_cache[$prod_name] = $p_row ? intval($p_row['id']) : null;
            }

            $product_id = $product_cache[$prod_name];
            if ($product_id === null) {
                continue; // product may have been deleted since the order was placed
            }

            // Pull its linked ingredients / packaging recipe
            $ing_stmt = $conn->prepare(
                "SELECT i.ingredient_name, i.unit, i.category, pi.quantity_used
                 FROM `product_ingredients` pi
                 JOIN `inventory` i ON pi.ingredient_id = i.id
                 WHERE pi.product_id = ?
                 UNION ALL
                 SELECT i.ingredient_name, i.unit, i.category, psi.quantity_used
                 FROM `product_size_ingredients` psi
                 JOIN `product_sizes` ps ON psi.product_size_id = ps.id
                 JOIN `inventory` i ON psi.ingredient_id = i.id
                 WHERE ps.product_id = ? AND ps.size = ?"
            );
            if (!$ing_stmt) {
                continue;
            }
            $ing_stmt->bind_param("iis", $product_id, $product_id, $size_name);
            $ing_stmt->execute();
            $ing_result = $ing_stmt->get_result();

            while ($ing_row = $ing_result->fetch_assoc()) {
                $key  = $ing_row['ingredient_name'] . '|' . $ing_row['unit'];
                $used = floatval($ing_row['quantity_used']) * $qty_ordered;

                if (!isset($usage[$key])) {
                    $usage[$key] = [
                        'name'     => $ing_row['ingredient_name'],
                        'unit'     => $ing_row['unit'],
                        'category' => $ing_row['category'],
                        'total'    => 0,
                    ];
                }
                $usage[$key]['total'] += $used;
            }
            $ing_stmt->close();
        }

        // Sort alphabetically for consistent display
        usort($usage, function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        return array_values($usage);
    }
}