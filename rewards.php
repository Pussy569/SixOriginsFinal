<?php

session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/helpers/loyalty.php';

if (empty($_SESSION['user_id'])) {
    $_SESSION['message'] = 'Sign in to view rewards and redeem your points.';
    header('Location: login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
foreach (['wallet', 'voucher'] as $referenceType) {
    if (empty($_SESSION['loyalty_reference_' . $referenceType])) {
        $_SESSION['loyalty_reference_' . $referenceType] = bin2hex(random_bytes(16));
    }
}

$message = (string)($_SESSION['rewards_notice'] ?? '');
unset($_SESSION['rewards_notice']);
$messageType = 'success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
        $message = 'Your session expired. Refresh the page and try again.';
        $messageType = 'error';
    } else {
        try {
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'claim_daily') {
                $gameType = isset($_POST['game_type']) ? (string)$_POST['game_type'] : null;
                $reward = loyalty_claim_daily_reward($conn, $userId, $gameType);
                $message = 'You received: ' . $reward['title'] . ($reward['points'] > 0 ? ' (' . $reward['points'] . ' points)' : '') . '!';
                $_SESSION['loyalty_reveal'] = ['game' => $gameType, 'title' => $reward['title']];
            } elseif ($action === 'redeem_wallet') {
                $reference = (string)($_POST['reference'] ?? '');
                if (!hash_equals((string)$_SESSION['loyalty_reference_wallet'], $reference)) {
                    throw new RuntimeException('This redemption form has already been used. Refresh and try again.');
                }
                $points = filter_var($_POST['points'] ?? null, FILTER_VALIDATE_INT);
                if ($points === false) {
                    throw new RuntimeException('Enter the points you want to exchange for wallet balance.');
                }
                $walletCredit = loyalty_redeem_wallet_points($conn, $userId, $points, $reference);
                unset($_SESSION['loyalty_reference_wallet']);
                $message = '₱' . number_format($walletCredit, 2) . ' has been added to your wallet.';
            } elseif ($action === 'redeem_voucher') {
                $reference = (string)($_POST['reference'] ?? '');
                if (!hash_equals((string)$_SESSION['loyalty_reference_voucher'], $reference)) {
                    throw new RuntimeException('This redemption form has already been used. Refresh and try again.');
                }
                $code = loyalty_redeem_voucher_points($conn, $userId, $reference);
                unset($_SESSION['loyalty_reference_voucher']);
                $message = 'Your points voucher is ready: ' . $code;
            } else {
                throw new RuntimeException('Choose a valid reward action.');
            }
        } catch (RuntimeException | InvalidArgumentException $e) {
            $message = $e->getMessage();
            $messageType = 'error';
        } catch (mysqli_sql_exception $e) {
            error_log('Customer reward action failed: ' . $e->getMessage());
            $message = 'Your reward could not be processed. Please try again.';
            $messageType = 'error';
        }
    }
    if ($messageType === 'success' && $message !== '') {
        $_SESSION['rewards_notice'] = $message;
        header('Location: rewards.php');
        exit;
    }
}

$reveal = $_SESSION['loyalty_reveal'] ?? null;
unset($_SESSION['loyalty_reveal']);
$stmt = $conn->prepare('SELECT name, wallet_balance, points_balance FROM users WHERE id = ? AND user_type = \'user\' LIMIT 1');
$stmt->bind_param('i', $userId);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

$points = (int)$user['points_balance'];
$loyaltySettings = loyalty_get_settings($conn);
$pointsRate = max(1, (int)$loyaltySettings['points_per_wallet_peso']);
$maxWalletRedeem = intdiv($points, $pointsRate) * $pointsRate;
$dailyState = loyalty_get_daily_state($conn, $userId);
$stmt = $conn->prepare(
    'SELECT d.name, d.code, d.amount, d.type, d.reward_type, d.minimum_spend, d.valid_until, p.name AS product_name
     FROM user_discounts ud JOIN discounts d ON d.id = ud.discount_id
     LEFT JOIN products p ON p.id = d.free_product_id
     WHERE ud.user_id = ? AND ud.used = 0 AND d.status = \'active\' AND d.valid_until >= CURDATE()
     ORDER BY d.valid_until, d.id DESC'
);
$stmt->bind_param('i', $userId);
$stmt->execute();
$vouchers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();
$history = $dailyState['history'] ?? [];
$claimedDays = array_fill_keys(array_map(static fn(array $claim): int => (int)$claim['day_number'], $history), true);
$offersStmt = $conn->prepare(
    'SELECT vc.name, vc.condition_type, vc.threshold_value, vc.reward_type, vc.reward_amount,
            vc.minimum_spend, vc.validity_days, p.name AS product_name
     FROM voucher_campaigns vc LEFT JOIN products p ON p.id = vc.free_product_id
     WHERE vc.active = 1 AND vc.trigger_type = \'order\'
       AND NOT EXISTS (
           SELECT 1 FROM voucher_claims vc_claim
           WHERE vc_claim.campaign_id = vc.id AND vc_claim.user_id = ?
       )
     ORDER BY vc.condition_type, vc.threshold_value, vc.id'
);
$offersStmt->bind_param('i', $userId);
$offersStmt->execute();
$availableOffers = $offersStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$offersStmt->close();

function rewards_voucher_description(array $voucher): string
{
    if ($voucher['reward_type'] === 'free_shipping') {
        return 'Free delivery';
    }
    if ($voucher['reward_type'] === 'free_product') {
        return 'Free ' . (string)($voucher['product_name'] ?? 'product');
    }
    return ($voucher['type'] === 'percentage' ? (int)$voucher['amount'] . '% off' : '₱' . number_format((float)$voucher['amount'], 2) . ' off');
}

function rewards_offer_description(array $offer): string
{
    if ($offer['reward_type'] === 'free_shipping') {
        return 'Free delivery';
    }
    if ($offer['reward_type'] === 'free_product') {
        return 'Free ' . (string)($offer['product_name'] ?? 'product');
    }
    return $offer['reward_type'] === 'percentage'
        ? (int)$offer['reward_amount'] . '% off'
        : '₱' . number_format((float)$offer['reward_amount'], 2) . ' off';
}
?>
<?php include 'header.php'; ?>
<main class="page rewards-page">
    <header class="rewards-hero">
        <span class="rewards-kicker">Your Six Origins perks</span>
        <h1>Rewards &amp; points</h1>
        <p>Check in, collect points, reveal surprises, and turn your rewards into wallet balance or vouchers.</p>
        <div class="rewards-balances">
            <div><span>Points</span><strong><?php echo number_format($points); ?></strong></div>
            <div><span>Wallet</span><strong>₱<?php echo number_format((float)$user['wallet_balance'], 2); ?></strong></div>
        </div>
    </header>

    <?php if ($message !== ''): ?>
        <div class="rewards-notice <?php echo $messageType === 'error' ? 'error' : ''; ?>" role="status"><?php echo escapeOutput($message); ?></div>
    <?php endif; ?>
    <?php if ($reveal): ?>
        <div class="reward-reveal" data-game="<?php echo escapeOutput($reveal['game'] ?? ''); ?>">
            <div class="reveal-art" aria-hidden="true"><?php echo ($reveal['game'] ?? '') === 'spin' ? '<i class="fa-solid fa-dharmachakra"></i>' : '<i class="fa-solid fa-gift"></i>'; ?></div>
            <div><span>Your mystery reward</span><strong><?php echo escapeOutput($reveal['title']); ?></strong></div>
        </div>
    <?php endif; ?>

    <section class="rewards-panel daily-panel">
        <div class="panel-heading"><div><span class="rewards-kicker">Daily check-in</span><h2>Seven days of surprises</h2></div>
            <?php if (!empty($dailyState['available'])): ?><span class="day-chip">Day <?php echo (int)$dailyState['day_number']; ?> of 7</span><?php endif; ?>
        </div>
        <?php if (!empty($dailyState['available'])): ?>
            <p><?php echo escapeOutput($dailyState['cycle_name']); ?> · Today's reward: <strong><?php echo escapeOutput($dailyState['reward']['title']); ?></strong>. Missed calendar days are skipped.</p>
            <div class="reward-progress" aria-label="Rewards claimed this cycle">
                <?php for ($day = 1; $day <= 7; $day++): ?>
                    <div class="reward-day <?php echo isset($claimedDays[$day]) ? 'claimed' : ''; ?> <?php echo $day === (int)$dailyState['day_number'] && empty($dailyState['claimed']) ? 'today' : ''; ?>">
                        <span>Day <?php echo $day; ?></span><i class="fa-solid <?php echo isset($claimedDays[$day]) ? 'fa-circle-check' : 'fa-gift'; ?>"></i>
                    </div>
                <?php endfor; ?>
            </div>
            <?php if (!empty($dailyState['claimed'])): ?>
                <div class="rewards-muted"><i class="fa-solid fa-circle-check"></i> Today's reward is claimed. Come back tomorrow for the next one.</div>
            <?php elseif ($dailyState['reward']['reward_type'] === 'mystery'): ?>
                <p>Day 6 is a mystery! Choose one game to reveal a weighted prize from the current pool.</p>
                <div class="game-actions">
                    <form method="post"><input type="hidden" name="csrf_token" value="<?php echo escapeOutput($_SESSION['csrf_token']); ?>"><input type="hidden" name="action" value="claim_daily"><input type="hidden" name="game_type" value="spin"><button class="rewards-button spin-button" type="submit"><i class="fa-solid fa-dharmachakra"></i> Spin for a prize</button></form>
                    <form method="post"><input type="hidden" name="csrf_token" value="<?php echo escapeOutput($_SESSION['csrf_token']); ?>"><input type="hidden" name="action" value="claim_daily"><input type="hidden" name="game_type" value="scratch"><button class="rewards-button scratch-button" type="submit"><i class="fa-solid fa-wand-magic-sparkles"></i> Scratch for a prize</button></form>
                </div>
            <?php else: ?>
                <form method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo escapeOutput($_SESSION['csrf_token']); ?>">
                    <input type="hidden" name="action" value="claim_daily">
                    <button class="rewards-button" type="submit"><i class="fa-solid fa-gift"></i> Claim today's reward</button>
                </form>
            <?php endif; ?>
        <?php else: ?>
            <p>The current 7-day reward cycle has ended. Watch this space for the next cycle from Six Origins.</p>
        <?php endif; ?>
    </section>

    <?php if ($availableOffers): ?>
        <section class="rewards-panel">
            <div class="panel-heading"><div><span class="rewards-kicker">More to unlock</span><h2>Earn a voucher with your order</h2></div></div>
            <p>Offers are granted once per customer after a qualifying order is completed. When several tiers for the same condition qualify, the highest one is granted.</p>
            <div class="offer-grid">
                <?php foreach ($availableOffers as $offer): ?>
                    <article class="offer-card">
                        <strong><?php echo escapeOutput($offer['name']); ?></strong>
                        <span><?php echo escapeOutput($offer['condition_type'] === 'spend'
                            ? 'Spend ₱' . number_format((float)$offer['threshold_value'], 2) . ' in one order'
                            : 'Order ' . (int)$offer['threshold_value'] . ' or more items'); ?></span>
                        <b><?php echo escapeOutput(rewards_offer_description($offer)); ?></b>
                        <?php if ((float)$offer['minimum_spend'] > 0): ?><span>Voucher minimum spend ₱<?php echo number_format((float)$offer['minimum_spend'], 2); ?></span><?php endif; ?>
                        <span>Granted after the order is completed · Expires in <?php echo (int)$offer['validity_days']; ?> days</span>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="rewards-columns">
        <article class="rewards-panel">
            <span class="rewards-kicker">Use your points</span><h2>Redeem to wallet</h2>
            <p><?php echo $pointsRate; ?> points = ₱1.00 added to your wallet. Points are redeemed in whole-peso amounts.</p>
            <form method="post" class="redeem-form">
                <input type="hidden" name="csrf_token" value="<?php echo escapeOutput($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="redeem_wallet">
                <input type="hidden" name="reference" value="<?php echo escapeOutput($_SESSION['loyalty_reference_wallet']); ?>">
                <label for="wallet-points">Points to redeem</label>
                <input id="wallet-points" type="number" name="points" min="<?php echo $pointsRate; ?>" max="<?php echo max($pointsRate, $maxWalletRedeem); ?>" step="<?php echo $pointsRate; ?>" value="<?php echo $maxWalletRedeem >= $pointsRate ? $pointsRate : ''; ?>" <?php echo $maxWalletRedeem < $pointsRate ? 'disabled' : ''; ?> required>
                <button class="rewards-button" type="submit" <?php echo $maxWalletRedeem < $pointsRate ? 'disabled' : ''; ?>>Redeem wallet balance</button>
            </form>
        </article>
        <article class="rewards-panel">
            <span class="rewards-kicker">Points shop</span><h2><?php echo (int)$loyaltySettings['points_voucher_cost']; ?> points for <?php echo (int)$loyaltySettings['points_voucher_percent']; ?>% off</h2>
            <p><?php echo (float)$loyaltySettings['points_voucher_minimum_spend'] > 0 ? 'Minimum spend: ₱' . number_format((float)$loyaltySettings['points_voucher_minimum_spend'], 2) . '. ' : ''; ?>Voucher expires <?php echo (int)$loyaltySettings['points_voucher_validity_days']; ?> days after redemption.</p>
            <form method="post" class="redeem-form">
                <input type="hidden" name="csrf_token" value="<?php echo escapeOutput($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="action" value="redeem_voucher">
                <input type="hidden" name="reference" value="<?php echo escapeOutput($_SESSION['loyalty_reference_voucher']); ?>">
                <button class="rewards-button" type="submit" <?php echo $points < (int)$loyaltySettings['points_voucher_cost'] ? 'disabled' : ''; ?>>Redeem points for voucher</button>
            </form>
        </article>
    </section>

    <section class="rewards-panel">
        <div class="panel-heading"><div><span class="rewards-kicker">Ready when you are</span><h2>Your vouchers</h2></div><span class="day-chip"><?php echo count($vouchers); ?> available</span></div>
        <?php if ($vouchers): ?>
            <div class="voucher-grid">
                <?php foreach ($vouchers as $voucher): ?>
                    <article class="voucher-card">
                        <span class="voucher-kind"><?php echo escapeOutput($voucher['name'] !== '' ? $voucher['name'] : 'Six Origins voucher'); ?></span>
                        <strong><?php echo escapeOutput(rewards_voucher_description($voucher)); ?></strong>
                        <?php if ((float)$voucher['minimum_spend'] > 0): ?><span>Minimum spend ₱<?php echo number_format((float)$voucher['minimum_spend'], 2); ?></span><?php endif; ?>
                        <code><?php echo escapeOutput($voucher['code']); ?></code>
                        <span>Use by <?php echo escapeOutput($voucher['valid_until']); ?></span>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p>You don't have any active vouchers yet. Check in daily or explore the store's automatic offers.</p>
        <?php endif; ?>
    </section>
</main>
<style>
    .rewards-page{display:grid;gap:22px;padding-top:28px;padding-bottom:56px;color:var(--site-text)}
    .rewards-hero,.rewards-panel{padding:clamp(20px,3vw,32px);border:1px solid color-mix(in srgb,var(--gray-brown) 19%,transparent);border-radius:20px;background:var(--site-surface);box-shadow:0 12px 32px rgba(61,43,39,.06)}
    .rewards-hero{background:radial-gradient(circle at 90% 8%,color-mix(in srgb,var(--primary-red) 14%,transparent),transparent 36%),linear-gradient(120deg,var(--site-surface),color-mix(in srgb,var(--site-page-bg) 70%,var(--site-surface)))}
    .rewards-kicker{display:block;color:var(--primary-red);font-size:.74rem;font-weight:900;letter-spacing:.14em;text-transform:uppercase}
    .rewards-hero h1,.rewards-panel h2{margin:7px 0 10px;color:var(--dark-brown)}
    .rewards-hero>p,.rewards-panel>p{color:var(--gray-brown);line-height:1.65}
    .rewards-balances{display:flex;flex-wrap:wrap;gap:12px;margin-top:22px}
    .rewards-balances div{display:grid;gap:4px;min-width:145px;padding:13px 16px;border:1px solid color-mix(in srgb,var(--primary-red) 22%,transparent);border-radius:13px;background:var(--site-surface)}
    .rewards-balances span{color:var(--gray-brown);font-size:.82rem;font-weight:700}.rewards-balances strong{color:var(--dark-brown);font-size:1.35rem}
    .panel-heading{display:flex;align-items:center;justify-content:space-between;gap:14px}.day-chip{padding:8px 12px;border-radius:999px;background:color-mix(in srgb,var(--primary-red) 10%,var(--site-surface));color:var(--primary-red);font-size:.83rem;font-weight:800;white-space:nowrap}
    .reward-progress{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:8px;margin:20px 0}
    .reward-day{display:grid;justify-items:center;gap:8px;padding:11px 6px;border:1px solid color-mix(in srgb,var(--gray-brown) 17%,transparent);border-radius:12px;color:var(--gray-brown);font-size:.74rem;font-weight:800}
    .reward-day i{font-size:1.1rem;color:color-mix(in srgb,var(--gray-brown) 65%,transparent)}.reward-day.claimed{background:color-mix(in srgb,#32a66a 10%,var(--site-surface));color:#24844f}.reward-day.claimed i{color:#24844f}
    .reward-day.today{border-color:var(--primary-red);box-shadow:0 0 0 2px color-mix(in srgb,var(--primary-red) 16%,transparent)}.reward-day.today i{color:var(--primary-red)}
    .rewards-button{min-height:44px;padding:11px 17px;border:0;border-radius:10px;background:linear-gradient(135deg,var(--primary-red),var(--dark-brown));color:#fff;font:800 .95rem Montserrat,sans-serif;cursor:pointer;transition:transform .16s,box-shadow .16s}
    .rewards-button:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 8px 20px color-mix(in srgb,var(--primary-red) 22%,transparent)}.rewards-button:disabled{opacity:.55;cursor:not-allowed}
    .game-actions{display:flex;flex-wrap:wrap;gap:12px}.spin-button{background:linear-gradient(135deg,#d89a27,#a95416)}.scratch-button{background:linear-gradient(135deg,#8055aa,#4b3270)}
    .rewards-muted{padding:13px 15px;border-radius:10px;background:color-mix(in srgb,#32a66a 10%,var(--site-surface));color:#267448}
    .rewards-notice{padding:14px 16px;border-radius:10px;background:#e8f5e9;color:#245b2b}.rewards-notice.error{background:#fde8e7;color:#8a1f19}
    .rewards-columns{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:22px}.redeem-form{display:grid;gap:10px;margin-top:20px}.redeem-form label{font-weight:800;color:var(--dark-brown)}
    .redeem-form input[type=number]{min-height:44px;padding:9px 12px;border:1px solid color-mix(in srgb,var(--gray-brown) 25%,var(--site-surface));border-radius:9px;background:var(--site-surface);color:var(--site-text);font:inherit}
    .voucher-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-top:16px}
    .voucher-card{display:grid;gap:7px;min-width:0;padding:16px;border:1px dashed color-mix(in srgb,var(--primary-red) 48%,var(--gray-brown));border-radius:14px;background:color-mix(in srgb,var(--primary-red) 5%,var(--site-surface))}
    .voucher-card strong{color:var(--dark-brown);font-size:1.2rem}.voucher-card span{color:var(--gray-brown);font-size:.84rem}.voucher-kind{font-weight:800}
    .voucher-card code{width:fit-content;padding:6px 9px;border-radius:6px;background:var(--site-surface);color:var(--primary-red);font-weight:900;letter-spacing:.08em}
    .reward-reveal{display:flex;align-items:center;gap:18px;padding:18px 22px;border:1px solid color-mix(in srgb,var(--primary-red) 28%,transparent);border-radius:16px;background:color-mix(in srgb,var(--primary-red) 8%,var(--site-surface))}
    .offer-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,230px),1fr));gap:12px}.offer-card{display:grid;gap:8px;padding:17px;border:1px solid color-mix(in srgb,var(--primary-red) 22%,transparent);border-radius:14px;background:color-mix(in srgb,var(--site-page-bg) 55%,var(--site-surface))}.offer-card strong{color:var(--dark-brown)}.offer-card span{color:var(--gray-brown);font-size:.84rem;line-height:1.45}.offer-card b{color:var(--primary-red);font-size:1.08rem}
    .reveal-art{display:grid;place-items:center;flex:none;width:56px;height:56px;border-radius:50%;background:var(--primary-red);color:#fff;font-size:1.5rem}
    .reward-reveal[data-game=spin] .reveal-art{animation:rewardSpin .8s cubic-bezier(.2,.8,.2,1) both}.reward-reveal[data-game=scratch] .reveal-art{animation:rewardScratch .8s ease-out both}
    .reward-reveal div:last-child{display:grid;gap:5px}.reward-reveal span{color:var(--gray-brown);font-size:.82rem}.reward-reveal strong{color:var(--dark-brown);font-size:1.2rem}
    @keyframes rewardSpin{to{transform:rotate(720deg) scale(1.08)}}@keyframes rewardScratch{from{clip-path:inset(0 100% 0 0)}to{clip-path:inset(0)}}
    @media(max-width:650px){.rewards-columns{grid-template-columns:1fr}.reward-progress{gap:4px}.reward-day{padding:9px 2px;font-size:.64rem}.reward-day i{font-size:.95rem}}
</style>
<?php include 'footer.php'; ?>
