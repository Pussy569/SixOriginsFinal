<?php

session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/services/admin_log_activity.php';
require_once __DIR__ . '/app/helpers/site_customization.php';
require_once __DIR__ . '/app/helpers/loyalty.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}
if (!is_head_admin()) {
    http_response_code(403);
    exit('Only the Head Admin can manage rewards and vouchers.');
}

$message = '';
$messageType = 'success';
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$productsResult = $conn->query('SELECT id, name, price FROM products ORDER BY name');
$products = $productsResult->fetch_all(MYSQLI_ASSOC);
$productsResult->free();
$productNames = [];
foreach ($products as $product) {
    $productNames[(int)$product['id']] = (string)$product['name'];
}

function rewards_validate_product_id(array $productNames, mixed $rawId): ?int
{
    if ($rawId === '' || $rawId === null || (string)$rawId === '0') {
        return null;
    }
    if (!ctype_digit((string)$rawId) || !isset($productNames[(int)$rawId])) {
        throw new RuntimeException('Choose a product that still exists in the catalogue.');
    }
    return (int)$rawId;
}

function rewards_validate_campaign(array $productNames, array $input): array
{
    $name = trim((string)($input['name'] ?? ''));
    $triggerType = (string)($input['trigger_type'] ?? '');
    $conditionType = (string)($input['condition_type'] ?? 'none');
    $rewardType = (string)($input['reward_type'] ?? '');
    $threshold = (float)($input['threshold_value'] ?? 0);
    $amount = (float)($input['reward_amount'] ?? 0);
    $minimumSpend = (float)($input['minimum_spend'] ?? 0);
    $validityDays = filter_var($input['validity_days'] ?? null, FILTER_VALIDATE_INT);

    if ($name === '' || strlen($name) > 100) {
        throw new RuntimeException('Enter an offer name up to 100 characters.');
    }
    if (!in_array($triggerType, ['signup', 'order'], true)
        || !in_array($conditionType, ['none', 'spend', 'products'], true)
        || !in_array($rewardType, ['fixed', 'percentage', 'free_shipping', 'free_product'], true)) {
        throw new RuntimeException('Choose valid offer trigger, condition, and reward types.');
    }
    if ($minimumSpend < 0 || $validityDays === false || $validityDays < 1 || $validityDays > 365) {
        throw new RuntimeException('Minimum spend must be zero or more and voucher validity must be 1–365 days.');
    }
    if ($triggerType === 'signup' && $conditionType !== 'none') {
        throw new RuntimeException('Sign-up vouchers are issued after email verification and do not use purchase thresholds.');
    }
    if ($triggerType === 'order' && $conditionType === 'none') {
        throw new RuntimeException('Order vouchers need a spend or product-count threshold.');
    }
    if ($conditionType === 'spend' && $threshold <= 0) {
        throw new RuntimeException('Enter a spend threshold greater than zero.');
    }
    if ($conditionType === 'products' && ($threshold < 1 || floor($threshold) !== $threshold)) {
        throw new RuntimeException('Product-count thresholds must be a whole number greater than zero.');
    }
    if ($rewardType === 'fixed' && ($amount < 1 || floor($amount) !== $amount)) {
        throw new RuntimeException('Fixed-amount vouchers must be a whole peso amount greater than zero.');
    }
    if ($rewardType === 'percentage' && ($amount < 1 || $amount > 100 || floor($amount) !== $amount)) {
        throw new RuntimeException('Percentage rewards must be a whole number from 1 to 100.');
    }
    $productId = $rewardType === 'free_product' ? rewards_validate_product_id($productNames, $input['free_product_id'] ?? null) : null;
    if ($rewardType === 'free_product' && $productId === null) {
        throw new RuntimeException('Choose a free product for this offer.');
    }

    return [$name, $triggerType, $conditionType, $threshold, $rewardType, $amount, $minimumSpend, $productId, $validityDays];
}

function rewards_validate_day(array $productNames, int $dayNumber, array $input): array
{
    $title = trim((string)($input['title'][$dayNumber] ?? ''));
    $type = (string)($input['type'][$dayNumber] ?? '');
    $points = filter_var($input['points'][$dayNumber] ?? '0', FILTER_VALIDATE_INT);
    $voucherType = (string)($input['voucher_type'][$dayNumber] ?? 'fixed');
    $amount = (float)($input['amount'][$dayNumber] ?? 0);
    $minimumSpend = (float)($input['minimum_spend'][$dayNumber] ?? 0);
    $validity = filter_var($input['validity'][$dayNumber] ?? '14', FILTER_VALIDATE_INT);
    $productId = $type === 'free_product' ? rewards_validate_product_id($productNames, $input['product_id'][$dayNumber] ?? null) : null;

    if ($title === '' || strlen($title) > 100
        || !in_array($type, ['points', 'voucher', 'free_shipping', 'mystery', 'free_product'], true)
        || $points === false || $points < 0
        || !in_array($voucherType, ['fixed', 'percentage'], true)
        || $minimumSpend < 0 || $validity === false || $validity < 1 || $validity > 365) {
        throw new RuntimeException('Check the title, reward type, points, spend, and expiry for each day.');
    }
    if ($type === 'points' && $points < 1) {
        throw new RuntimeException('Point rewards must be at least one point.');
    }
    if ($type === 'voucher' && ($amount < 1 || ($voucherType === 'percentage' && $amount > 100) || floor($amount) !== $amount)) {
        throw new RuntimeException('Voucher values must be a whole amount; percentages cannot exceed 100.');
    }
    if ($type === 'free_product' && $productId === null) {
        throw new RuntimeException('Choose a product for each free-product reward.');
    }

    return [$title, $type, $points, $voucherType, $amount, $minimumSpend, $productId, $validity];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!validateCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }

        $action = (string)($_POST['action'] ?? '');
        if ($action === 'save_settings') {
            $pointsPerPeso = filter_var($_POST['points_per_wallet_peso'] ?? null, FILTER_VALIDATE_INT);
            $voucherCost = filter_var($_POST['points_voucher_cost'] ?? null, FILTER_VALIDATE_INT);
            $voucherPercent = filter_var($_POST['points_voucher_percent'] ?? null, FILTER_VALIDATE_INT);
            $voucherMinimum = (float)($_POST['points_voucher_minimum_spend'] ?? -1);
            $voucherValidity = filter_var($_POST['points_voucher_validity_days'] ?? null, FILTER_VALIDATE_INT);
            if ($pointsPerPeso === false || $pointsPerPeso < 1 || $pointsPerPeso > 10000
                || $voucherCost === false || $voucherCost < 1 || $voucherCost > 1000000
                || $voucherPercent === false || $voucherPercent < 1 || $voucherPercent > 100
                || $voucherMinimum < 0
                || $voucherValidity === false || $voucherValidity < 1 || $voucherValidity > 365) {
                throw new RuntimeException('Check the points conversion, voucher cost, percentage, minimum spend, and expiry.');
            }
            $stmt = $conn->prepare(
                'UPDATE loyalty_settings
                 SET points_per_wallet_peso = ?, points_voucher_cost = ?, points_voucher_percent = ?,
                     points_voucher_minimum_spend = ?, points_voucher_validity_days = ?, updated_by = ?
                 WHERE id = 1'
            );
            $adminId = (int)$_SESSION['admin_id'];
            $stmt->bind_param('iiidii', $pointsPerPeso, $voucherCost, $voucherPercent, $voucherMinimum, $voucherValidity, $adminId);
            $stmt->execute();
            $stmt->close();
            $message = 'Points redemption settings saved.';
        } elseif ($action === 'create_campaign' || $action === 'update_campaign') {
            [$name, $trigger, $condition, $threshold, $rewardType, $amount, $minimumSpend, $productId, $validity] = rewards_validate_campaign($productNames, $_POST);
            $active = !empty($_POST['active']) ? 1 : 0;
            if ($action === 'create_campaign') {
                $stmt = $conn->prepare(
                    'INSERT INTO voucher_campaigns
                     (name, trigger_type, condition_type, threshold_value, reward_type, reward_amount, minimum_spend, free_product_id, validity_days, active, created_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $adminId = (int)$_SESSION['admin_id'];
                $stmt->bind_param('sssdsddiiii', $name, $trigger, $condition, $threshold, $rewardType, $amount, $minimumSpend, $productId, $validity, $active, $adminId);
            } else {
                $campaignId = filter_var($_POST['campaign_id'] ?? null, FILTER_VALIDATE_INT);
                if ($campaignId === false || $campaignId < 1) {
                    throw new RuntimeException('Choose an offer to update.');
                }
                $stmt = $conn->prepare(
                    'UPDATE voucher_campaigns
                     SET name = ?, trigger_type = ?, condition_type = ?, threshold_value = ?, reward_type = ?,
                         reward_amount = ?, minimum_spend = ?, free_product_id = ?, validity_days = ?, active = ?
                     WHERE id = ?'
                );
                $stmt->bind_param('sssdsddiiii', $name, $trigger, $condition, $threshold, $rewardType, $amount, $minimumSpend, $productId, $validity, $active, $campaignId);
            }
            $stmt->execute();
            $stmt->close();
            $message = 'Voucher offer saved. Existing customer vouchers keep the terms they were issued with.';
        } elseif ($action === 'save_cycle') {
            $cycleId = filter_var($_POST['cycle_id'] ?? null, FILTER_VALIDATE_INT);
            if ($cycleId === false || $cycleId < 1) {
                throw new RuntimeException('Choose a 7-day reward cycle to update.');
            }
            $conn->begin_transaction();
            try {
                $cycleCheck = $conn->prepare('SELECT id FROM loyalty_reward_cycles WHERE id = ? AND published = 1 FOR UPDATE');
                $cycleCheck->bind_param('i', $cycleId);
                $cycleCheck->execute();
                $exists = $cycleCheck->get_result()->num_rows === 1;
                $cycleCheck->close();
                if (!$exists) {
                    throw new RuntimeException('The selected reward cycle is not available.');
                }
                for ($day = 1; $day <= 7; $day++) {
                    [$title, $type, $points, $voucherType, $amount, $minimumSpend, $productId, $validity] = rewards_validate_day($productNames, $day, $_POST);
                    $stmt = $conn->prepare(
                        'UPDATE loyalty_reward_days
                         SET title = ?, reward_type = ?, points_amount = ?, voucher_type = ?, voucher_amount = ?,
                             minimum_spend = ?, free_product_id = ?, validity_days = ?
                         WHERE cycle_id = ? AND day_number = ?'
                    );
                    $stmt->bind_param('ssisddiiii', $title, $type, $points, $voucherType, $amount, $minimumSpend, $productId, $validity, $cycleId, $day);
                    $stmt->execute();
                    if ($stmt->affected_rows === 0 && $stmt->errno !== 0) {
                        throw new RuntimeException('A daily reward could not be saved.');
                    }
                    $stmt->close();
                }

                $conn->query('DELETE FROM loyalty_reward_prizes WHERE cycle_id = ' . (int)$cycleId);
                $totalPrizeWeight = 0;
                for ($prizeIndex = 0; $prizeIndex < 3; $prizeIndex++) {
                    $title = trim((string)($_POST['prize_title'][$prizeIndex] ?? ''));
                    $type = (string)($_POST['prize_type'][$prizeIndex] ?? '');
                    $amount = (float)($_POST['prize_amount'][$prizeIndex] ?? 0);
                    $minimumSpend = (float)($_POST['prize_minimum_spend'][$prizeIndex] ?? 0);
                    $validity = filter_var($_POST['prize_validity'][$prizeIndex] ?? '14', FILTER_VALIDATE_INT);
                    $weight = filter_var($_POST['prize_weight'][$prizeIndex] ?? null, FILTER_VALIDATE_INT);
                    $voucherType = (string)($_POST['prize_voucher_type'][$prizeIndex] ?? 'fixed');
                    $productId = $type === 'free_product' ? rewards_validate_product_id($productNames, $_POST['prize_product_id'][$prizeIndex] ?? null) : null;
                    if ($title === '' || strlen($title) > 100
                        || !in_array($type, ['points', 'voucher', 'free_shipping', 'free_product'], true)
                        || $minimumSpend < 0 || $validity === false || $validity < 1 || $validity > 365
                        || $weight === false || $weight < 0 || $weight > 1000
                        || !in_array($voucherType, ['fixed', 'percentage'], true)) {
                        throw new RuntimeException('Check the three mystery prize titles, types, expiry, and odds.');
                    }
                    if ($type === 'points' && ($amount < 1 || floor($amount) !== $amount)) {
                        throw new RuntimeException('Mystery point prizes must award a whole number of points.');
                    }
                    if ($type === 'voucher' && ($amount < 1 || ($voucherType === 'percentage' && $amount > 100) || floor($amount) !== $amount)) {
                        throw new RuntimeException('Mystery voucher values must be whole amounts; percentages cannot exceed 100.');
                    }
                    if ($type === 'free_product' && $productId === null) {
                        throw new RuntimeException('Choose a product for every mystery free-product prize.');
                    }
                    $totalPrizeWeight += $weight;
                    $stmt = $conn->prepare(
                        'INSERT INTO loyalty_reward_prizes
                         (cycle_id, title, reward_type, voucher_type, reward_amount, minimum_spend, free_product_id, validity_days, weight)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    $stmt->bind_param('isssddiii', $cycleId, $title, $type, $voucherType, $amount, $minimumSpend, $productId, $validity, $weight);
                    $stmt->execute();
                    $stmt->close();
                }
                if ($totalPrizeWeight < 1) {
                    throw new RuntimeException('At least one Day 6 mystery prize must have a positive chance.');
                }
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
            $message = 'The 7-day rewards and Day 6 mystery prizes have been updated.';
        } elseif ($action === 'publish_cycle') {
            $name = trim((string)($_POST['cycle_name'] ?? ''));
            $startsOn = (string)($_POST['starts_on'] ?? '');
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $startsOn);
            if ($name === '' || strlen($name) > 100 || !$date || $date->format('Y-m-d') !== $startsOn || $startsOn < date('Y-m-d')) {
                throw new RuntimeException('Enter a cycle name and a valid start date that is today or later.');
            }
            $lastCycle = $conn->query('SELECT id, DATE_ADD(starts_on, INTERVAL 6 DAY) AS ends_on FROM loyalty_reward_cycles WHERE published = 1 ORDER BY starts_on DESC LIMIT 1')->fetch_assoc();
            if ($lastCycle && $startsOn <= (string)$lastCycle['ends_on']) {
                throw new RuntimeException('The next cycle must start after the last published 7-day cycle ends.');
            }
            $sourceCycleId = $lastCycle ? (int)$lastCycle['id'] : null;
            if ($sourceCycleId === null) {
                throw new RuntimeException('No existing reward settings are available to copy into the next cycle.');
            }
            $conn->begin_transaction();
            try {
                $stmt = $conn->prepare('INSERT INTO loyalty_reward_cycles (name, starts_on, published, created_by) VALUES (?, ?, 1, ?)');
                $adminId = (int)$_SESSION['admin_id'];
                $stmt->bind_param('ssi', $name, $startsOn, $adminId);
                $stmt->execute();
                $newCycleId = (int)$conn->insert_id;
                $stmt->close();

                $copyDays = $conn->prepare(
                    'INSERT INTO loyalty_reward_days
                     (cycle_id, day_number, title, reward_type, points_amount, voucher_type, voucher_amount, minimum_spend, free_product_id, validity_days)
                     SELECT ?, day_number, title, reward_type, points_amount, voucher_type, voucher_amount, minimum_spend, free_product_id, validity_days
                     FROM loyalty_reward_days WHERE cycle_id = ?'
                );
                $copyDays->bind_param('ii', $newCycleId, $sourceCycleId);
                $copyDays->execute();
                $copiedDays = $copyDays->affected_rows;
                $copyDays->close();
                if ($copiedDays !== 7) {
                    throw new RuntimeException('The existing cycle does not have all seven reward days configured.');
                }
                $copyPrizes = $conn->prepare(
                    'INSERT INTO loyalty_reward_prizes
                     (cycle_id, title, reward_type, voucher_type, reward_amount, minimum_spend, free_product_id, validity_days, weight)
                     SELECT ?, title, reward_type, voucher_type, reward_amount, minimum_spend, free_product_id, validity_days, weight
                     FROM loyalty_reward_prizes WHERE cycle_id = ?'
                );
                $copyPrizes->bind_param('ii', $newCycleId, $sourceCycleId);
                $copyPrizes->execute();
                $copyPrizes->close();
                $conn->commit();
            } catch (Throwable $e) {
                $conn->rollback();
                throw $e;
            }
            $message = 'The next 7-day reward cycle is published. Edit its rewards below before it starts.';
        } else {
            throw new RuntimeException('Choose a valid reward action.');
        }

        log_admin_activity((int)$_SESSION['admin_id'], 'Updated Rewards and Vouchers', $message, 'success', 'loyalty', 1);
    } catch (mysqli_sql_exception $e) {
        error_log('Loyalty administration database error: ' . $e->getMessage());
        $message = 'The reward changes could not be saved. Check the database migration and try again.';
        $messageType = 'error';
    } catch (RuntimeException | InvalidArgumentException $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

$loyaltySettings = loyalty_get_settings($conn);
$campaignResult = $conn->query('SELECT * FROM voucher_campaigns ORDER BY trigger_type, condition_type, threshold_value, id');
$campaigns = $campaignResult->fetch_all(MYSQLI_ASSOC);
$campaignResult->free();
$activeCampaignCount = count(array_filter($campaigns, static fn(array $campaign): bool => (int)$campaign['active'] === 1));
$cycleResult = $conn->query(
    'SELECT * FROM loyalty_reward_cycles WHERE published = 1
     ORDER BY CASE WHEN DATE_ADD(starts_on, INTERVAL 6 DAY) >= CURDATE() THEN 0 ELSE 1 END, starts_on DESC LIMIT 1'
);
$cycle = $cycleResult->fetch_assoc();
$cycleResult->free();
$days = [];
$prizes = [];
if ($cycle) {
    $stmt = $conn->prepare('SELECT * FROM loyalty_reward_days WHERE cycle_id = ? ORDER BY day_number');
    $stmt->bind_param('i', $cycle['id']);
    $stmt->execute();
    $dayRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    foreach ($dayRows as $row) {
        $days[(int)$row['day_number']] = $row;
    }
    $stmt = $conn->prepare('SELECT * FROM loyalty_reward_prizes WHERE cycle_id = ? ORDER BY id LIMIT 3');
    $stmt->bind_param('i', $cycle['id']);
    $stmt->execute();
    $prizes = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}
$defaultDay = ['title' => '', 'reward_type' => 'points', 'points_amount' => 10, 'voucher_type' => 'fixed', 'voucher_amount' => 0, 'minimum_spend' => 0, 'free_product_id' => null, 'validity_days' => 14];
$defaultPrizes = [
    ['title' => 'Bonus points', 'reward_type' => 'points', 'voucher_type' => 'fixed', 'reward_amount' => 30, 'minimum_spend' => 0, 'free_product_id' => null, 'validity_days' => 14, 'weight' => 6],
    ['title' => 'Mystery voucher', 'reward_type' => 'voucher', 'voucher_type' => 'fixed', 'reward_amount' => 50, 'minimum_spend' => 300, 'free_product_id' => null, 'validity_days' => 14, 'weight' => 3],
    ['title' => 'Free product jackpot', 'reward_type' => 'free_product', 'voucher_type' => 'fixed', 'reward_amount' => 0, 'minimum_spend' => 0, 'free_product_id' => null, 'validity_days' => 14, 'weight' => 1],
];
while (count($prizes) < 3) {
    $prizes[] = $defaultPrizes[count($prizes)];
}
?>
<?php include 'admin_header.php'; ?>
<main class="page rewards-admin">
    <header class="rewards-intro">
        <div><span class="rewards-eyebrow">Head Admin controls</span><h1><i class="fa-solid fa-gift"></i> Rewards &amp; vouchers</h1>
            <p>Create automatic offers for verified sign-ups and qualifying completed orders, then manage points and daily login rewards.</p></div>
        <div class="rewards-overview" aria-label="Rewards overview">
            <div class="overview-stat"><span>Active offers</span><strong><?php echo $activeCampaignCount; ?></strong></div>
            <div class="overview-stat"><span>Points exchange</span><strong><?php echo (int)$loyaltySettings['points_per_wallet_peso']; ?> <small>pts / ₱1</small></strong></div>
        </div>
    </header>
    <?php if ($message !== ''): ?>
        <div class="rewards-message <?php echo $messageType === 'error' ? 'error' : ''; ?>" role="status"><?php echo escapeOutput($message); ?></div>
    <?php endif; ?>

    <section class="rewards-card">
        <div class="rewards-section-heading"><span class="section-icon"><i class="fa-solid fa-coins"></i></span><div><h2>Points redemption</h2>
        <p>Customers can convert points into wallet balance or redeem a points-priced discount voucher.</p>
        </div></div>
        <form method="post" class="rewards-grid">
            <input type="hidden" name="csrf_token" value="<?php echo escapeOutput($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="save_settings">
            <label>Points for ₱1 wallet balance<input type="number" min="1" max="10000" name="points_per_wallet_peso" required value="<?php echo (int)$loyaltySettings['points_per_wallet_peso']; ?>"></label>
            <label>Points cost for voucher<input type="number" min="1" max="1000000" name="points_voucher_cost" required value="<?php echo (int)$loyaltySettings['points_voucher_cost']; ?>"></label>
            <label>Voucher discount (%)<input type="number" min="1" max="100" name="points_voucher_percent" required value="<?php echo (int)$loyaltySettings['points_voucher_percent']; ?>"></label>
            <label>Voucher minimum spend (₱)<input type="number" min="0" step="1" name="points_voucher_minimum_spend" required value="<?php echo escapeOutput($loyaltySettings['points_voucher_minimum_spend']); ?>"></label>
            <label>Voucher expiry (days)<input type="number" min="1" max="365" name="points_voucher_validity_days" required value="<?php echo (int)$loyaltySettings['points_voucher_validity_days']; ?>"></label>
            <button class="rewards-button" type="submit">Save points settings</button>
        </form>
    </section>

    <section class="rewards-card">
        <div class="rewards-section-heading"><span class="section-icon"><i class="fa-solid fa-ticket"></i></span><div><h2>Automatic voucher offers</h2>
        <p>Sign-up offers are granted after email verification. Each order offer is granted once per customer after a qualifying order reaches Completed; thresholds use one order's product subtotal or item quantity. When multiple tiers qualify for the same condition, only the highest tier is granted. Voucher minimum spend is checked when the customer redeems it.</p>
        </div></div>
        <form method="post" class="rewards-grid campaign-form">
            <input type="hidden" name="csrf_token" value="<?php echo escapeOutput($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="create_campaign">
            <?php include __DIR__ . '/app/views/loyalty_campaign_fields.php'; ?>
            <label class="rewards-check"><input type="checkbox" name="active" value="1" checked> Offer active</label>
            <button class="rewards-button" type="submit">Create automatic offer</button>
        </form>
        <div class="campaign-list">
            <?php if (!$campaigns): ?><p class="campaign-empty">No offers yet. Create your first voucher offer above.</p><?php endif; ?>
            <?php foreach ($campaigns as $campaign): ?>
                <details class="campaign-item">
                    <summary><strong><?php echo escapeOutput($campaign['name']); ?></strong><span><?php echo escapeOutput(ucfirst($campaign['trigger_type'])); ?> · <?php echo $campaign['active'] ? 'Active' : 'Paused'; ?></span></summary>
                    <form method="post" class="rewards-grid campaign-form">
                        <input type="hidden" name="csrf_token" value="<?php echo escapeOutput($_SESSION['csrf_token']); ?>">
                        <input type="hidden" name="action" value="update_campaign">
                        <input type="hidden" name="campaign_id" value="<?php echo (int)$campaign['id']; ?>">
                        <?php include __DIR__ . '/app/views/loyalty_campaign_fields.php'; ?>
                        <label class="rewards-check"><input type="checkbox" name="active" value="1" <?php echo $campaign['active'] ? 'checked' : ''; ?>> Offer active</label>
                        <button class="rewards-button" type="submit">Save offer</button>
                    </form>
                </details>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="rewards-card">
        <div class="rewards-section-heading"><span class="section-icon"><i class="fa-solid fa-calendar-check"></i></span><div><h2>Seven-day login rewards</h2>
        <p>Set the daily rewards, configure the Day 6 prize pool, then publish the next cycle when you are ready.</p></div></div>
        <?php if ($cycle): ?>
            <p>Current settings: <strong><?php echo escapeOutput($cycle['name']); ?></strong> · Starts <?php echo escapeOutput($cycle['starts_on']); ?> · Runs once for seven calendar days. Missed days are skipped.</p>
            <form method="post" class="rewards-grid reward-cycle-form">
                <input type="hidden" name="csrf_token" value="<?php echo escapeOutput($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="save_cycle">
                <input type="hidden" name="cycle_id" value="<?php echo (int)$cycle['id']; ?>">
                <?php for ($day = 1; $day <= 7; $day++):
                    $reward = $days[$day] ?? $defaultDay;
                ?>
                    <fieldset class="day-editor">
                        <legend>Day <?php echo $day; ?><?php echo $day === 6 ? ' · Spin or scratch' : ''; ?></legend>
                        <label>Reward name<input name="title[<?php echo $day; ?>]" maxlength="100" required value="<?php echo escapeOutput($reward['title']); ?>"></label>
                        <label>Reward type<select name="type[<?php echo $day; ?>]">
                            <?php foreach (['points' => 'Points', 'voucher' => 'Discount voucher', 'free_shipping' => 'Free shipping', 'mystery' => 'Mystery game', 'free_product' => 'Free product'] as $value => $label): ?>
                                <option value="<?php echo $value; ?>" <?php echo $reward['reward_type'] === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select></label>
                        <label>Points<input type="number" min="0" name="points[<?php echo $day; ?>]" value="<?php echo (int)$reward['points_amount']; ?>"></label>
                        <label>Voucher amount or percent<input type="number" min="0" step="1" name="amount[<?php echo $day; ?>]" value="<?php echo escapeOutput($reward['voucher_amount']); ?>"></label>
                        <label>Voucher type<select name="voucher_type[<?php echo $day; ?>]"><option value="fixed" <?php echo $reward['voucher_type'] === 'fixed' ? 'selected' : ''; ?>>Fixed amount (₱)</option><option value="percentage" <?php echo $reward['voucher_type'] === 'percentage' ? 'selected' : ''; ?>>Percentage (%)</option></select></label>
                        <label>Minimum spend (₱)<input type="number" min="0" step="1" name="minimum_spend[<?php echo $day; ?>]" value="<?php echo escapeOutput($reward['minimum_spend']); ?>"></label>
                        <label>Free product<select name="product_id[<?php echo $day; ?>]"><option value="">Choose if needed</option><?php foreach ($products as $product): ?><option value="<?php echo (int)$product['id']; ?>" <?php echo (int)($reward['free_product_id'] ?? 0) === (int)$product['id'] ? 'selected' : ''; ?>><?php echo escapeOutput($product['name']); ?></option><?php endforeach; ?></select></label>
                        <label>Voucher expiry (days)<input type="number" min="1" max="365" name="validity[<?php echo $day; ?>]" value="<?php echo (int)$reward['validity_days']; ?>"></label>
                    </fieldset>
                <?php endfor; ?>
                <h3 class="full-span">Day 6 mystery prize pool (same prizes for spin and scratch)</h3>
                <?php foreach ($prizes as $index => $prize): ?>
                    <fieldset class="day-editor prize-editor">
                        <legend>Mystery prize <?php echo $index + 1; ?></legend>
                        <label>Prize name<input name="prize_title[<?php echo $index; ?>]" maxlength="100" required value="<?php echo escapeOutput($prize['title']); ?>"></label>
                        <label>Reward type<select name="prize_type[<?php echo $index; ?>]"><?php foreach (['points' => 'Points', 'voucher' => 'Discount voucher', 'free_shipping' => 'Free shipping', 'free_product' => 'Free product jackpot'] as $value => $label): ?><option value="<?php echo $value; ?>" <?php echo $prize['reward_type'] === $value ? 'selected' : ''; ?>><?php echo $label; ?></option><?php endforeach; ?></select></label>
                        <label>Amount / points<input type="number" min="0" step="1" name="prize_amount[<?php echo $index; ?>]" value="<?php echo escapeOutput($prize['reward_amount']); ?>"></label>
                        <label>Voucher type<select name="prize_voucher_type[<?php echo $index; ?>]"><option value="fixed" <?php echo $prize['voucher_type'] === 'fixed' ? 'selected' : ''; ?>>Fixed amount (₱)</option><option value="percentage" <?php echo $prize['voucher_type'] === 'percentage' ? 'selected' : ''; ?>>Percentage (%)</option></select></label>
                        <label>Minimum spend (₱)<input type="number" min="0" step="1" name="prize_minimum_spend[<?php echo $index; ?>]" value="<?php echo escapeOutput($prize['minimum_spend']); ?>"></label>
                        <label>Free product<select name="prize_product_id[<?php echo $index; ?>]"><option value="">Choose if needed</option><?php foreach ($products as $product): ?><option value="<?php echo (int)$product['id']; ?>" <?php echo (int)($prize['free_product_id'] ?? 0) === (int)$product['id'] ? 'selected' : ''; ?>><?php echo escapeOutput($product['name']); ?></option><?php endforeach; ?></select></label>
                        <label>Expiry (days)<input type="number" min="1" max="365" name="prize_validity[<?php echo $index; ?>]" value="<?php echo (int)$prize['validity_days']; ?>"></label>
                        <label>Relative odds weight<input type="number" min="0" max="1000" name="prize_weight[<?php echo $index; ?>]" value="<?php echo (int)$prize['weight']; ?>"></label>
                    </fieldset>
                <?php endforeach; ?>
                <p class="full-span">Odds are relative weights; a weight of zero disables a prize. Customers choose either the spin wheel or scratch card once on Day 6.</p>
                <button class="rewards-button" type="submit">Save seven-day rewards</button>
            </form>
            <form method="post" class="publish-cycle-form">
                <input type="hidden" name="csrf_token" value="<?php echo escapeOutput($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="publish_cycle">
                <h3>Publish the next cycle</h3>
                <p>The next 7-day cycle will be copied from these settings. It will not start until its chosen date.</p>
                <label>Cycle name<input name="cycle_name" maxlength="100" required value="Seven-day rewards"></label>
                <label>Start date<input type="date" name="starts_on" min="<?php echo escapeOutput(date('Y-m-d', strtotime($cycle['starts_on'] . ' +7 days'))); ?>" required value="<?php echo escapeOutput(date('Y-m-d', strtotime($cycle['starts_on'] . ' +7 days'))); ?>"></label>
                <button class="rewards-button" type="submit">Publish next 7-day cycle</button>
            </form>
        <?php else: ?>
            <p>No seven-day reward cycle is available yet. Apply the loyalty rewards migration to create the initial cycle.</p>
        <?php endif; ?>
    </section>
</main>
<style>
    .rewards-admin{display:grid;gap:24px;width:100%;max-width:1488px;margin-inline:auto;padding:30px 24px 60px}
    .rewards-intro,.rewards-card{min-width:0;padding:clamp(22px,3vw,36px);border:1px solid color-mix(in srgb,var(--gray-brown) 18%,transparent);border-radius:22px;background:var(--site-surface);box-shadow:0 14px 36px rgba(61,43,39,.07)}
    .rewards-intro{display:flex;align-items:center;justify-content:space-between;gap:28px;overflow:hidden;background:radial-gradient(ellipse at 92% 10%,color-mix(in srgb,var(--primary-red) 18%,transparent),transparent 38%),linear-gradient(125deg,color-mix(in srgb,var(--primary-red) 7%,var(--site-surface)),var(--site-surface))}
    .rewards-eyebrow{color:var(--primary-red);font-size:.74rem;font-weight:900;letter-spacing:.15em;text-transform:uppercase}
    .rewards-intro h1,.rewards-card h2{color:var(--dark-brown);margin:8px 0 9px}
    .rewards-intro h1{font-size:clamp(1.7rem,3vw,2.4rem);letter-spacing:-.04em}.rewards-intro>div:first-child{max-width:720px}
    .rewards-card h2{font-size:1.32rem;letter-spacing:-.02em}.rewards-card p,.rewards-intro p{color:var(--gray-brown);line-height:1.7;margin:5px 0 0}
    .rewards-overview{display:flex;gap:12px;flex-shrink:0}.overview-stat{display:grid;gap:5px;min-width:138px;padding:16px 18px;border:1px solid color-mix(in srgb,var(--primary-red) 20%,transparent);border-radius:16px;background:color-mix(in srgb,var(--site-surface) 92%,transparent);box-shadow:0 8px 20px rgba(61,43,39,.04)}.overview-stat span{color:var(--gray-brown);font-size:.75rem;font-weight:800}.overview-stat strong{color:var(--dark-brown);font-size:1.45rem}.overview-stat small{font-size:.75rem}
    .rewards-section-heading{display:flex;align-items:flex-start;gap:14px;margin-bottom:20px}.section-icon{display:grid;place-items:center;flex:none;width:46px;height:46px;border-radius:14px;background:color-mix(in srgb,var(--primary-red) 11%,var(--site-surface));color:var(--primary-red);font-size:1.15rem}.rewards-section-heading h2{margin-top:0}
    .rewards-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));align-items:end;gap:16px;margin-top:20px}
    .campaign-form{grid-template-columns:repeat(4,minmax(0,1fr));padding:20px;border:1px solid color-mix(in srgb,var(--gray-brown) 16%,transparent);border-radius:17px;background:color-mix(in srgb,var(--site-page-bg) 36%,var(--site-surface))}
    .rewards-grid label,.publish-cycle-form label{display:grid;align-content:start;gap:8px;min-width:0;color:var(--dark-brown);font-size:.84rem;font-weight:800;line-height:1.35}
    .rewards-grid input:not([type=checkbox]),.rewards-grid select,.publish-cycle-form input{width:100%;min-width:0;min-height:46px;padding:10px 12px;border:1px solid color-mix(in srgb,var(--gray-brown) 25%,var(--site-surface));border-radius:10px;background:var(--site-surface);color:var(--site-text);font:500 .92rem Montserrat,sans-serif;transition:border-color .18s,box-shadow .18s}
    .rewards-grid input:focus,.rewards-grid select:focus,.publish-cycle-form input:focus{outline:none;border-color:var(--primary-red);box-shadow:0 0 0 3px color-mix(in srgb,var(--primary-red) 14%,transparent)}
    .rewards-check{display:flex!important;align-items:center;gap:10px;min-height:44px;padding:10px 12px;border:1px solid color-mix(in srgb,var(--gray-brown) 17%,transparent);border-radius:10px;background:var(--site-surface)}.rewards-check input{width:18px;height:18px;accent-color:var(--primary-red)}
    .rewards-button{display:inline-flex;align-items:center;justify-content:center;gap:8px;align-self:end;min-height:46px;padding:11px 18px;border:0;border-radius:11px;background:linear-gradient(135deg,var(--primary-red),var(--dark-brown));color:#fff;font:800 .9rem Montserrat,sans-serif;cursor:pointer;box-shadow:0 7px 16px color-mix(in srgb,var(--primary-red) 18%,transparent);transition:transform .18s,box-shadow .18s}.rewards-button:hover{transform:translateY(-1px);box-shadow:0 10px 22px color-mix(in srgb,var(--primary-red) 25%,transparent)}
    .rewards-message{padding:14px 17px;border:1px solid color-mix(in srgb,#24844f 24%,transparent);border-radius:12px;background:color-mix(in srgb,#32a66a 10%,var(--site-surface));color:#245b2b}.rewards-message.error{background:#fde8e7;color:#8a1f19}
    .campaign-list{display:grid;gap:12px;margin-top:20px}.campaign-item{overflow:hidden;border:1px solid color-mix(in srgb,var(--gray-brown) 17%,transparent);border-radius:14px;background:var(--site-surface);transition:border-color .18s,box-shadow .18s}.campaign-item[open]{border-color:color-mix(in srgb,var(--primary-red) 38%,transparent);box-shadow:0 8px 22px rgba(61,43,39,.05)}.campaign-item summary{display:flex;align-items:center;justify-content:space-between;gap:14px;min-height:58px;padding:14px 18px;cursor:pointer;color:var(--dark-brown);list-style:none}.campaign-item summary::-webkit-details-marker{display:none}.campaign-item summary::after{content:'Edit offer  +';margin-left:auto;color:var(--primary-red);font-size:.76rem;font-weight:900;white-space:nowrap}.campaign-item[open] summary::after{content:'Close  −'}.campaign-item summary strong{font-size:.95rem}.campaign-item summary span{padding:6px 10px;border-radius:999px;background:color-mix(in srgb,var(--primary-red) 8%,var(--site-surface));color:var(--gray-brown);font-size:.76rem;font-weight:800}.campaign-item .campaign-form{margin:0;border:0;border-top:1px solid color-mix(in srgb,var(--gray-brown) 14%,transparent);border-radius:0}
    .campaign-empty{padding:20px;border:1px dashed color-mix(in srgb,var(--gray-brown) 28%,transparent);border-radius:14px;text-align:center}
    .day-editor{display:grid;align-content:start;gap:13px;min-width:0;margin:0;padding:18px;border:1px solid color-mix(in srgb,var(--gray-brown) 19%,transparent);border-radius:15px;background:var(--site-surface)}.day-editor legend{padding:0 8px;color:var(--dark-brown);font-size:.93rem;font-weight:900}.day-editor label{font-size:.8rem}
    .reward-cycle-form{grid-template-columns:repeat(4,minmax(0,1fr));align-items:stretch}.reward-cycle-form .day-editor{grid-column:span 2}.reward-cycle-form .prize-editor{grid-column:span 1}.full-span{grid-column:1/-1}
    .publish-cycle-form{display:grid;grid-template-columns:minmax(0,1fr) minmax(200px,.55fr) auto;align-items:end;gap:16px;margin-top:28px;padding:22px;border:1px solid color-mix(in srgb,var(--primary-red) 18%,transparent);border-radius:16px;background:color-mix(in srgb,var(--primary-red) 4%,var(--site-surface))}
    .publish-cycle-form h3,.publish-cycle-form p{grid-column:1/-1}.publish-cycle-form h3{margin:0;color:var(--dark-brown)}.publish-cycle-form p{margin:-8px 0 0}
    @media(max-width:1100px){.rewards-intro{align-items:flex-start;flex-direction:column}.rewards-grid,.campaign-form{grid-template-columns:repeat(2,minmax(0,1fr))}.reward-cycle-form .day-editor{grid-column:span 1}.reward-cycle-form .prize-editor{grid-column:span 1}}
    @media(max-width:680px){.rewards-admin{gap:16px;padding:18px 16px 42px}.rewards-intro,.rewards-card{padding:20px;border-radius:17px}.rewards-overview{width:100%}.overview-stat{flex:1;min-width:0}.rewards-grid,.campaign-form,.reward-cycle-form,.publish-cycle-form{grid-template-columns:1fr}.reward-cycle-form .day-editor,.reward-cycle-form .prize-editor{grid-column:auto}.full-span,.publish-cycle-form h3,.publish-cycle-form p{grid-column:auto}.campaign-item summary{align-items:flex-start;flex-direction:column}.campaign-item summary::after{margin:0}.publish-cycle-form{padding:17px}.rewards-button{width:100%}}
</style>
<?php include 'admin_footer.php'; ?>
