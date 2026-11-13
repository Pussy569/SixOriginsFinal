<?php

function loyalty_send_signup_verification(mysqli $conn, int $userId): bool
{
    require_once __DIR__ . '/site_customization.php';
    require_once dirname(__DIR__) . '/services/mail_helper.php';
    $stmt = $conn->prepare('SELECT name, email, email_verified FROM users WHERE id = ? AND user_type = \'user\' LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user || (int)$user['email_verified'] === 1) {
        return false;
    }

    $stmt = $conn->prepare('SELECT created_at FROM signup_email_verifications WHERE user_id = ? AND used = 0 ORDER BY id DESC LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $lastCode = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($lastCode && strtotime((string)$lastCode['created_at']) > time() - 60) {
        return false;
    }

    $invalidate = $conn->prepare('UPDATE signup_email_verifications SET used = 1 WHERE user_id = ? AND used = 0');
    $invalidate->bind_param('i', $userId);
    $invalidate->execute();
    $invalidate->close();

    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $codeHash = password_hash($code, PASSWORD_DEFAULT);
    $expiresAt = date('Y-m-d H:i:s', time() + 15 * 60);
    $insert = $conn->prepare('INSERT INTO signup_email_verifications (user_id, code_hash, expires_at) VALUES (?, ?, ?)');
    $insert->bind_param('iss', $userId, $codeHash, $expiresAt);
    $insert->execute();
    $insert->close();

    return send_signup_verification_mail((string)$user['name'], (string)$user['email'], $code, get_site_customization($conn));
}

function loyalty_get_settings(mysqli $conn): array
{
    $result = $conn->query('SELECT points_per_wallet_peso, points_voucher_cost, points_voucher_percent, points_voucher_minimum_spend, points_voucher_validity_days FROM loyalty_settings WHERE id = 1');
    $settings = $result->fetch_assoc();
    $result->free();
    if (!$settings) {
        throw new RuntimeException('Loyalty settings are missing. Apply the loyalty rewards database migration.');
    }

    return $settings;
}

function loyalty_issue_discount(
    mysqli $conn,
    int $userId,
    string $name,
    string $rewardType,
    float $amount,
    float $minimumSpend,
    ?int $freeProductId,
    int $validityDays,
    float $maxDiscountCap = 0
): int {
    if ($userId <= 0 || $validityDays < 1 || $validityDays > 365) {
        throw new InvalidArgumentException('A valid customer and voucher validity period are required.');
    }

    $discountType = $rewardType === 'percentage' ? 'percentage' : 'fixed';
    $storedRewardType = in_array($rewardType, ['free_shipping', 'free_product'], true) ? $rewardType : 'discount';
    $discountAmount = (int)round($amount);
    if ($rewardType === 'percentage' && ($discountAmount < 1 || $discountAmount > 100)) {
        throw new InvalidArgumentException('Percentage rewards must be between 1 and 100.');
    }
    if ($rewardType === 'fixed' && $discountAmount < 1) {
        throw new InvalidArgumentException('Fixed voucher rewards must be greater than zero.');
    }
    if ($rewardType === 'free_product' && ($freeProductId === null || $freeProductId <= 0)) {
        throw new InvalidArgumentException('A free-product reward must specify a product.');
    }
    if (!is_finite($maxDiscountCap) || $maxDiscountCap < 0 || $maxDiscountCap > 99999999.99) {
        throw new InvalidArgumentException('The maximum discount cap must be zero or a valid peso amount.');
    }
    if ($rewardType !== 'percentage') {
        $maxDiscountCap = 0;
    }

    $code = 'SO' . strtoupper(bin2hex(random_bytes(5)));
    $validUntil = date('Y-m-d', strtotime('+' . $validityDays . ' days'));
    $stmt = $conn->prepare(
        'INSERT INTO discounts (name, code, amount, type, reward_type, maximum_discount_cap, minimum_spend, free_product_id, status, valid_until, user_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'active\', ?, ?)'
    );
    $stmt->bind_param('ssissddisi', $name, $code, $discountAmount, $discountType, $storedRewardType, $maxDiscountCap, $minimumSpend, $freeProductId, $validUntil, $userId);
    $stmt->execute();
    $discountId = (int)$conn->insert_id;
    $stmt->close();

    $assign = $conn->prepare('INSERT INTO user_discounts (user_id, discount_id, used) VALUES (?, ?, 0)');
    $assign->bind_param('ii', $userId, $discountId);
    $assign->execute();
    $assign->close();

    return $discountId;
}

function loyalty_get_user_discount(mysqli $conn, int $userId, string $code): ?array
{
    $stmt = $conn->prepare(
        'SELECT d.* FROM user_discounts ud
         JOIN discounts d ON d.id = ud.discount_id
         WHERE ud.user_id = ? AND ud.used = 0 AND d.code = ? AND d.status = \'active\' AND d.valid_until >= CURDATE()
         LIMIT 1'
    );
    $stmt->bind_param('is', $userId, $code);
    $stmt->execute();
    $discount = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $discount ?: null;
}

function loyalty_calculate_discount(
    array $discount,
    float $subtotal,
    float $vat,
    float $deliveryFee,
    string $method,
    array $items
): float {
    if ($subtotal < (float)($discount['minimum_spend'] ?? 0)) {
        throw new RuntimeException('This voucher requires a minimum product spend of ₱' . number_format((float)$discount['minimum_spend'], 2) . '.');
    }

    $rewardType = (string)($discount['reward_type'] ?? 'discount');
    if ($rewardType === 'free_shipping') {
        if ($deliveryFee <= 0 || $method === 'pickup') {
            throw new RuntimeException('Free-delivery vouchers can only be used on a delivery order.');
        }
        return $deliveryFee;
    }
    if ($rewardType === 'free_product') {
        $productId = (int)($discount['free_product_id'] ?? 0);
        foreach ($items as $item) {
            if ((int)($item['product_id'] ?? 0) === $productId && (int)($item['quantity'] ?? $item['qty'] ?? 0) > 0) {
                $freeItemValue = (float)$item['price'] * 1.12;
                return min($subtotal + $vat + $deliveryFee, $freeItemValue + $deliveryFee);
            }
        }
        throw new RuntimeException('Add the free product shown on your voucher to your cart before using it.');
    }

    if (($discount['type'] ?? 'fixed') === 'percentage') {
        $maxDiscountCap = (float)($discount['maximum_discount_cap'] ?? 0);
        $amount = $subtotal * ((float)$discount['amount'] / 100);
        if ($maxDiscountCap > 0) {
            $amount = min($amount, $maxDiscountCap);
        }
    } else {
        $amount = (float)$discount['amount'];
    }
    return min(max(0, $amount), max(0, $subtotal));
}

function loyalty_award_campaigns(mysqli $conn, int $userId, string $triggerType, float $orderSpend = 0, int $itemCount = 0, ?int $sourceOrderId = null): array
{
    if (!in_array($triggerType, ['signup', 'order'], true)) {
        throw new InvalidArgumentException('Invalid voucher campaign trigger.');
    }

    $stmt = $conn->prepare('SELECT * FROM voucher_campaigns WHERE active = 1 AND trigger_type = ? ORDER BY id');
    $stmt->bind_param('s', $triggerType);
    $stmt->execute();
    $campaigns = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $selectedTiers = [];
    if ($triggerType === 'order') {
        foreach ($campaigns as $campaign) {
            $condition = (string)$campaign['condition_type'];
            $threshold = (float)$campaign['threshold_value'];
            $qualifies = ($condition === 'spend' && $orderSpend >= $threshold)
                || ($condition === 'products' && $itemCount >= (int)$threshold);
            if (!$qualifies) {
                continue;
            }
            if (!isset($selectedTiers[$condition]) || $threshold > (float)$selectedTiers[$condition]['threshold_value']) {
                $selectedTiers[$condition] = $campaign;
            }
        }
    }

    $awarded = [];
    foreach ($campaigns as $campaign) {
        $condition = (string)$campaign['condition_type'];
        $threshold = (float)$campaign['threshold_value'];
        if ($triggerType === 'order' && in_array($condition, ['spend', 'products'], true)
            && (int)($selectedTiers[$condition]['id'] ?? 0) !== (int)$campaign['id']) {
            continue;
        }
        if ($condition === 'spend' && $orderSpend < $threshold) {
            continue;
        }
        if ($condition === 'products' && $itemCount < (int)$threshold) {
            continue;
        }
        if ($triggerType === 'signup' && $condition !== 'none') {
            continue;
        }

        $conn->begin_transaction();
        try {
            $discountId = loyalty_issue_discount(
                $conn,
                $userId,
                (string)$campaign['name'],
                (string)$campaign['reward_type'],
                (float)$campaign['reward_amount'],
                (float)$campaign['minimum_spend'],
                isset($campaign['free_product_id']) ? (int)$campaign['free_product_id'] : null,
                (int)$campaign['validity_days'],
                (float)($campaign['max_discount_cap'] ?? 0)
            );
            $claim = $conn->prepare(
                'INSERT INTO voucher_claims (campaign_id, user_id, discount_id, source_order_id, status)
                 VALUES (?, ?, ?, ?, \'assigned\')'
            );
            $claim->bind_param('iiii', $campaign['id'], $userId, $discountId, $sourceOrderId);
            $claim->execute();
            $claim->close();
            $conn->commit();
            $awarded[] = ['name' => (string)$campaign['name'], 'code' => ''];
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            if ($e->getCode() === 1062) {
                $claimed = $conn->prepare('SELECT 1 FROM voucher_claims WHERE campaign_id = ? AND user_id = ? LIMIT 1');
                $claimed->bind_param('ii', $campaign['id'], $userId);
                $claimed->execute();
                $alreadyClaimed = $claimed->get_result()->num_rows === 1;
                $claimed->close();
                if ($alreadyClaimed) {
                    continue;
                }
            }
            throw $e;
        } catch (Throwable $e) {
            $conn->rollback();
            throw $e;
        }
    }

    return $awarded;
}

function loyalty_award_order_vouchers(mysqli $conn, int $orderId): array
{
    $stmt = $conn->prepare('SELECT user_id, subtotal_amount, item_count FROM orders WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $orderId);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$order || !ctype_digit((string)$order['user_id'])) {
        return [];
    }

    return loyalty_award_campaigns(
        $conn,
        (int)$order['user_id'],
        'order',
        (float)$order['subtotal_amount'],
        (int)$order['item_count'],
        $orderId
    );
}

function loyalty_revoke_order_vouchers(mysqli $conn, int $orderId): int
{
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            'UPDATE voucher_claims SET status = \'revoked\'
             WHERE source_order_id = ? AND status = \'assigned\''
        );
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $revoked = $stmt->affected_rows;
        $stmt->close();

        $stmt = $conn->prepare(
            'UPDATE user_discounts ud
             JOIN voucher_claims vc ON vc.discount_id = ud.discount_id
             SET ud.used = 1
             WHERE vc.source_order_id = ? AND vc.status = \'revoked\' AND ud.used = 0'
        );
        $stmt->bind_param('i', $orderId);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
        return $revoked;
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }
}

function loyalty_get_daily_state(mysqli $conn, int $userId): array
{
    $today = date('Y-m-d');
    $stmt = $conn->prepare(
        'SELECT id, name, starts_on, DATEDIFF(?, starts_on) + 1 AS day_number
         FROM loyalty_reward_cycles
         WHERE published = 1 AND starts_on <= ? AND DATE_ADD(starts_on, INTERVAL 6 DAY) >= ?
         ORDER BY starts_on DESC, id DESC LIMIT 1'
    );
    $stmt->bind_param('sss', $today, $today, $today);
    $stmt->execute();
    $cycle = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$cycle) {
        return ['available' => false];
    }

    $dayNumber = (int)$cycle['day_number'];
    $stmt = $conn->prepare(
        'SELECT day_number, title, reward_type, points_amount, voucher_type, voucher_amount, minimum_spend, free_product_id, validity_days
         FROM loyalty_reward_days WHERE cycle_id = ? AND day_number = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $cycle['id'], $dayNumber);
    $stmt->execute();
    $reward = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$reward) {
        return ['available' => false];
    }

    $stmt = $conn->prepare('SELECT id FROM daily_reward_claims WHERE user_id = ? AND cycle_id = ? AND claim_date = ? LIMIT 1');
    $stmt->bind_param('iis', $userId, $cycle['id'], $today);
    $stmt->execute();
    $claimed = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    $stmt = $conn->prepare('SELECT day_number, claim_date FROM daily_reward_claims WHERE user_id = ? AND cycle_id = ? ORDER BY day_number');
    $stmt->bind_param('ii', $userId, $cycle['id']);
    $stmt->execute();
    $history = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return [
        'available' => true,
        'cycle_id' => (int)$cycle['id'],
        'cycle_name' => (string)$cycle['name'],
        'day_number' => $dayNumber,
        'reward' => $reward,
        'claimed' => $claimed,
        'history' => $history,
    ];
}

function loyalty_claim_daily_reward(mysqli $conn, int $userId, ?string $gameType = null): array
{
    $state = loyalty_get_daily_state($conn, $userId);
    if (empty($state['available'])) {
        throw new RuntimeException('There is no active daily reward cycle today.');
    }
    if (!empty($state['claimed'])) {
        throw new RuntimeException('You have already claimed today’s reward.');
    }

    $reward = $state['reward'];
    if ($reward['reward_type'] === 'mystery' && !in_array($gameType, ['spin', 'scratch'], true)) {
        throw new RuntimeException('Choose the spin wheel or scratch card to reveal your reward.');
    }
    if ($reward['reward_type'] !== 'mystery' && $gameType !== null) {
        throw new RuntimeException('The selected game is not available for today’s reward.');
    }

    $conn->begin_transaction();
    try {
        $lock = $conn->prepare('SELECT points_balance FROM users WHERE id = ? FOR UPDATE');
        $lock->bind_param('i', $userId);
        $lock->execute();
        $user = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$user) {
            throw new RuntimeException('Your account could not be found.');
        }

        $rewardType = (string)$reward['reward_type'];
        $rewardAmount = (float)$reward['voucher_amount'];
        $minimumSpend = (float)$reward['minimum_spend'];
        $freeProductId = isset($reward['free_product_id']) ? (int)$reward['free_product_id'] : null;
        $rewardName = (string)$reward['title'];
        $pointsAwarded = 0;

        if ($rewardType === 'mystery') {
            $stmt = $conn->prepare('SELECT title, reward_type, voucher_type, reward_amount, minimum_spend, free_product_id, validity_days, weight FROM loyalty_reward_prizes WHERE cycle_id = ? AND weight > 0');
            $stmt->bind_param('i', $state['cycle_id']);
            $stmt->execute();
            $prizes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $totalWeight = array_sum(array_map(static fn(array $prize): int => (int)$prize['weight'], $prizes));
            if ($totalWeight < 1) {
                throw new RuntimeException('The daily mystery rewards are not configured. Please contact the Head Admin.');
            }
            $draw = random_int(1, $totalWeight);
            foreach ($prizes as $prize) {
                $draw -= (int)$prize['weight'];
                if ($draw <= 0) {
                    $rewardType = (string)$prize['reward_type'];
                    $reward['voucher_type'] = $prize['voucher_type'];
                    $rewardAmount = (float)$prize['reward_amount'];
                    $minimumSpend = (float)$prize['minimum_spend'];
                    $freeProductId = isset($prize['free_product_id']) ? (int)$prize['free_product_id'] : null;
                    $rewardName = (string)$prize['title'];
                    $reward['validity_days'] = $prize['validity_days'];
                    break;
                }
            }
        }

        $discountId = null;
        if ($rewardType === 'points') {
            $pointsAwarded = (int)round($rewardAmount > 0 ? $rewardAmount : ($reward['points_amount'] ?? 0));
            if ($pointsAwarded < 1) {
                $pointsAwarded = (int)$reward['points_amount'];
            }
            $update = $conn->prepare('UPDATE users SET points_balance = points_balance + ? WHERE id = ?');
            $update->bind_param('ii', $pointsAwarded, $userId);
            $update->execute();
            $update->close();

            $reference = 'daily:' . $state['cycle_id'] . ':' . $userId . ':' . date('Y-m-d');
            $ledger = $conn->prepare('INSERT INTO points_transactions (user_id, points_delta, reason, reference_key) VALUES (?, ?, \'daily_reward\', ?)');
            $ledger->bind_param('iis', $userId, $pointsAwarded, $reference);
            $ledger->execute();
            $ledger->close();
        } else {
            $voucherType = $rewardType === 'voucher' ? (string)($reward['voucher_type'] ?? 'fixed') : $rewardType;
            $discountId = loyalty_issue_discount(
                $conn,
                $userId,
                $rewardName,
                $voucherType,
                $rewardAmount,
                $minimumSpend,
                $freeProductId,
                (int)$reward['validity_days']
            );
        }

        $claim = $conn->prepare(
            'INSERT INTO daily_reward_claims (user_id, cycle_id, day_number, claim_date, game_type, reward_type, points_awarded, discount_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $today = date('Y-m-d');
        $claim->bind_param('iiisssii', $userId, $state['cycle_id'], $state['day_number'], $today, $gameType, $rewardType, $pointsAwarded, $discountId);
        $claim->execute();
        $claim->close();
        $conn->commit();

        return ['title' => $rewardName, 'type' => $rewardType, 'points' => $pointsAwarded];
    } catch (Throwable $e) {
        $conn->rollback();
        if ($e instanceof mysqli_sql_exception && $e->getCode() === 1062) {
            throw new RuntimeException('You have already claimed today’s reward.');
        }
        throw $e;
    }
}

function loyalty_redeem_wallet_points(mysqli $conn, int $userId, int $points, string $reference): float
{
    if ($points < 1 || $reference === '') {
        throw new InvalidArgumentException('Choose a valid points amount to redeem.');
    }
    $settings = loyalty_get_settings($conn);
    $rate = (float)$settings['points_per_wallet_peso'];
    if ($rate <= 0) {
        throw new RuntimeException('The points-to-wallet conversion rate is not configured correctly.');
    }
    $walletCredit = round($points / $rate, 2);
    if ($walletCredit < 0.01) {
        throw new RuntimeException('Redeem more points to receive at least ₱0.01 wallet balance.');
    }

    $conn->begin_transaction();
    try {
        $lock = $conn->prepare('SELECT points_balance FROM users WHERE id = ? FOR UPDATE');
        $lock->bind_param('i', $userId);
        $lock->execute();
        $user = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$user || (int)$user['points_balance'] < $points) {
            throw new RuntimeException('You do not have enough points for this redemption.');
        }

        $update = $conn->prepare('UPDATE users SET points_balance = points_balance - ?, wallet_balance = wallet_balance + ? WHERE id = ?');
        $update->bind_param('idi', $points, $walletCredit, $userId);
        $update->execute();
        $update->close();

        $ledger = $conn->prepare('INSERT INTO points_transactions (user_id, points_delta, reason, reference_key) VALUES (?, ?, \'wallet_redemption\', ?)');
        $negativePoints = -$points;
        $ledger->bind_param('iis', $userId, $negativePoints, $reference);
        $ledger->execute();
        $ledger->close();
        $conn->commit();
        return $walletCredit;
    } catch (Throwable $e) {
        $conn->rollback();
        if ($e instanceof mysqli_sql_exception && $e->getCode() === 1062) {
            throw new RuntimeException('This points redemption has already been processed.');
        }
        throw $e;
    }
}

function loyalty_redeem_voucher_points(mysqli $conn, int $userId, string $reference): string
{
    $settings = loyalty_get_settings($conn);
    $cost = (int)$settings['points_voucher_cost'];
    $percent = (int)$settings['points_voucher_percent'];
    $validityDays = (int)$settings['points_voucher_validity_days'];

    $conn->begin_transaction();
    try {
        $lock = $conn->prepare('SELECT points_balance FROM users WHERE id = ? FOR UPDATE');
        $lock->bind_param('i', $userId);
        $lock->execute();
        $user = $lock->get_result()->fetch_assoc();
        $lock->close();
        if (!$user || (int)$user['points_balance'] < $cost) {
            throw new RuntimeException('You do not have enough points for this voucher.');
        }

        $discountId = loyalty_issue_discount(
            $conn,
            $userId,
            'Points reward - ' . $percent . '% off',
            'percentage',
            $percent,
            (float)$settings['points_voucher_minimum_spend'],
            null,
            $validityDays
        );
        $update = $conn->prepare('UPDATE users SET points_balance = points_balance - ? WHERE id = ?');
        $update->bind_param('ii', $cost, $userId);
        $update->execute();
        $update->close();

        $ledger = $conn->prepare('INSERT INTO points_transactions (user_id, points_delta, reason, reference_key) VALUES (?, ?, \'voucher_redemption\', ?)');
        $negativePoints = -$cost;
        $ledger->bind_param('iis', $userId, $negativePoints, $reference);
        $ledger->execute();
        $ledger->close();
        $conn->commit();

        $codeStmt = $conn->prepare('SELECT code FROM discounts WHERE id = ?');
        $codeStmt->bind_param('i', $discountId);
        $codeStmt->execute();
        $codeStmt->bind_result($code);
        $codeStmt->fetch();
        $codeStmt->close();
        return (string)$code;
    } catch (Throwable $e) {
        $conn->rollback();
        if ($e instanceof mysqli_sql_exception && $e->getCode() === 1062) {
            throw new RuntimeException('This points redemption has already been processed.');
        }
        throw $e;
    }
}
