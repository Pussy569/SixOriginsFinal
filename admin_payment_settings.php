<?php
session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/services/admin_log_activity.php';
require_once __DIR__ . '/app/helpers/site_customization.php';
require_once __DIR__ . '/app/helpers/payment_methods.php';

if (empty($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}
if (!is_head_admin()) {
    http_response_code(403);
    exit('Only the Head Admin can manage checkout payment methods.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!validateCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Your session expired. Refresh the page and try again.');
        }

        $methods = [
            'cash on delivery' => isset($_POST['cash_on_delivery']) ? 1 : 0,
            'wallet balance' => isset($_POST['wallet_delivery']) ? 1 : 0,
            'pickup' => isset($_POST['store_pickup_wallet']) ? 1 : 0,
        ];
        if (!in_array(1, $methods, true)) {
            throw new RuntimeException('At least one payment method must remain enabled.');
        }

        $before = get_checkout_payment_methods($conn);
        $cashOnDelivery = $methods['cash on delivery'];
        $walletDelivery = $methods['wallet balance'];
        $storePickup = $methods['pickup'];
        $adminId = (int)$_SESSION['admin_id'];
        $stmt = $conn->prepare(
            'UPDATE checkout_payment_settings
             SET cash_on_delivery = ?, wallet_delivery = ?, store_pickup_wallet = ?, updated_by = ?
             WHERE id = 1'
        );
        $stmt->bind_param('iiii', $cashOnDelivery, $walletDelivery, $storePickup, $adminId);
        $stmt->execute();
        $stmt->close();

        log_admin_activity(
            $adminId,
            'Updated Checkout Payment Methods',
            'Changed checkout payment method availability.',
            'success',
            'checkout_payment_settings',
            1,
            $before,
            $methods
        );
        $message = 'Checkout payment methods have been updated.';
    } catch (mysqli_sql_exception $e) {
        error_log('Checkout payment settings update failed: ' . $e->getMessage());
        $message = 'Payment settings could not be saved. Check that the payment methods migration has been applied.';
        $messageType = 'error';
    } catch (RuntimeException $e) {
        $message = $e->getMessage();
        $messageType = 'error';
    }
}

$paymentMethods = get_checkout_payment_methods($conn);
?>
<?php include 'admin_header.php'; ?>
<main class="page payment-settings-page">
    <header class="settings-hero">
        <span class="settings-kicker">Head Admin controls</span>
        <h1><i class="fa-solid fa-credit-card"></i> Checkout payment methods</h1>
        <p>Temporarily turn payment options on or off to match today's delivery and pickup availability.</p>
    </header>

    <?php if ($message !== ''): ?>
        <div class="settings-notice <?php echo $messageType === 'error' ? 'error' : ''; ?>" role="status"><?php echo escapeOutput($message); ?></div>
    <?php endif; ?>

    <section class="settings-card">
        <div class="settings-heading">
            <span class="settings-icon"><i class="fa-solid fa-sliders"></i></span>
            <div><h2>Available at checkout</h2><p>Disabled methods are removed from customer checkout and rejected if submitted directly. Existing orders are not changed.</p></div>
        </div>
        <form method="post" class="payment-method-form">
            <input type="hidden" name="csrf_token" value="<?php echo escapeOutput($_SESSION['csrf_token']); ?>">
            <label class="payment-method-option">
                <span class="method-icon"><i class="fa-solid fa-money-bill-wave"></i></span>
                <span class="method-description"><strong>Cash on Delivery</strong><small>Delivery orders paid upon arrival.</small></span>
                <input type="checkbox" name="cash_on_delivery" value="1" <?php echo $paymentMethods['cash on delivery'] ? 'checked' : ''; ?>>
            </label>
            <label class="payment-method-option">
                <span class="method-icon"><i class="fa-solid fa-wallet"></i></span>
                <span class="method-description"><strong>Wallet Balance — Delivery</strong><small>Delivery orders paid from the customer's wallet.</small></span>
                <input type="checkbox" name="wallet_delivery" value="1" <?php echo $paymentMethods['wallet balance'] ? 'checked' : ''; ?>>
            </label>
            <label class="payment-method-option">
                <span class="method-icon"><i class="fa-solid fa-store"></i></span>
                <span class="method-description"><strong>Store Pickup — Wallet</strong><small>In-store pickup paid from the customer's wallet.</small></span>
                <input type="checkbox" name="store_pickup_wallet" value="1" <?php echo $paymentMethods['pickup'] ? 'checked' : ''; ?>>
            </label>
            <div class="settings-actions">
                <span><i class="fa-solid fa-circle-info"></i> Keep at least one payment method enabled.</span>
                <button class="settings-save" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save payment options</button>
            </div>
        </form>
    </section>
</main>
<style>
    .payment-settings-page{width:100%;max-width:1120px;margin:0 auto;padding:30px 24px 60px;display:grid;gap:20px}
    .settings-hero,.settings-card{padding:clamp(22px,3vw,34px);border:1px solid color-mix(in srgb,var(--gray-brown) 18%,transparent);border-radius:22px;background:var(--site-surface);box-shadow:0 14px 36px rgba(61,43,39,.07)}
    .settings-hero{background:radial-gradient(ellipse at 92% 10%,color-mix(in srgb,var(--primary-red) 16%,transparent),transparent 40%),linear-gradient(125deg,color-mix(in srgb,var(--primary-red) 7%,var(--site-surface)),var(--site-surface))}
    .settings-kicker{color:var(--primary-red);font-size:.74rem;font-weight:900;letter-spacing:.14em;text-transform:uppercase}
    .settings-hero h1{margin:8px 0;color:var(--dark-brown);font-size:clamp(1.7rem,3vw,2.4rem);letter-spacing:-.04em}.settings-hero p,.settings-heading p{color:var(--gray-brown);line-height:1.65}
    .settings-notice{padding:14px 17px;border-radius:12px;background:color-mix(in srgb,#32a66a 10%,var(--site-surface));color:#245b2b}.settings-notice.error{background:#fde8e7;color:#8a1f19}
    .settings-heading{display:flex;align-items:flex-start;gap:14px;margin-bottom:22px}.settings-icon,.method-icon{display:grid;place-items:center;flex:none;width:46px;height:46px;border-radius:14px;background:color-mix(in srgb,var(--primary-red) 10%,var(--site-surface));color:var(--primary-red);font-size:1.1rem}
    .settings-heading h2{margin:0 0 6px;color:var(--dark-brown);font-size:1.25rem}.settings-heading p{margin:0}
    .payment-method-form{display:grid;gap:12px}.payment-method-option{display:grid;grid-template-columns:46px minmax(0,1fr) 24px;align-items:center;gap:14px;padding:17px;border:1px solid color-mix(in srgb,var(--gray-brown) 17%,transparent);border-radius:15px;background:color-mix(in srgb,var(--site-page-bg) 32%,var(--site-surface));cursor:pointer;transition:border-color .18s,transform .18s}.payment-method-option:hover{transform:translateY(-1px);border-color:color-mix(in srgb,var(--primary-red) 40%,transparent)}
    .method-description{display:grid;gap:5px}.method-description strong{color:var(--dark-brown);font-size:.94rem}.method-description small{color:var(--gray-brown);font-size:.8rem;line-height:1.45}.payment-method-option input{width:20px;height:20px;accent-color:var(--primary-red)}
    .settings-actions{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-top:8px}.settings-actions>span{color:var(--gray-brown);font-size:.78rem;line-height:1.5}.settings-save{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:46px;padding:11px 18px;border:0;border-radius:11px;background:linear-gradient(135deg,var(--primary-red),var(--dark-brown));color:#fff;font:800 .9rem Montserrat,sans-serif;cursor:pointer}
    @media(max-width:600px){.payment-settings-page{padding:18px 16px 42px}.settings-hero,.settings-card{padding:20px;border-radius:17px}.payment-method-option{grid-template-columns:40px minmax(0,1fr) 22px;gap:10px;padding:13px}.method-icon{width:40px;height:40px}.settings-actions{align-items:stretch;flex-direction:column}.settings-save{width:100%}}
</style>
<?php include 'admin_footer.php'; ?>
