<?php
$campaignFields = $campaign ?? [
    'name' => '',
    'trigger_type' => 'signup',
    'condition_type' => 'none',
    'threshold_value' => 0,
    'reward_type' => 'fixed',
    'reward_amount' => 100,
    'minimum_spend' => 500,
    'free_product_id' => null,
    'validity_days' => 14,
];
?>
<label>Offer name
    <input type="text" name="name" maxlength="100" required value="<?php echo escapeOutput($campaignFields['name']); ?>">
</label>
<label>When to grant
    <select name="trigger_type">
        <option value="signup" <?php echo $campaignFields['trigger_type'] === 'signup' ? 'selected' : ''; ?>>After verified sign-up</option>
        <option value="order" <?php echo $campaignFields['trigger_type'] === 'order' ? 'selected' : ''; ?>>After qualifying completed order</option>
    </select>
</label>
<label>Order condition
    <select name="condition_type">
        <option value="none" <?php echo $campaignFields['condition_type'] === 'none' ? 'selected' : ''; ?>>No purchase condition (sign-up)</option>
        <option value="spend" <?php echo $campaignFields['condition_type'] === 'spend' ? 'selected' : ''; ?>>One order reaches spend threshold</option>
        <option value="products" <?php echo $campaignFields['condition_type'] === 'products' ? 'selected' : ''; ?>>One order reaches item-count threshold</option>
    </select>
</label>
<label>Spend threshold (₱) or item count
    <input type="number" min="0" step="1" name="threshold_value" required value="<?php echo escapeOutput($campaignFields['threshold_value']); ?>">
</label>
<label>Reward type
    <select name="reward_type">
        <option value="fixed" <?php echo $campaignFields['reward_type'] === 'fixed' ? 'selected' : ''; ?>>Fixed amount off</option>
        <option value="percentage" <?php echo $campaignFields['reward_type'] === 'percentage' ? 'selected' : ''; ?>>Percentage off</option>
        <option value="free_shipping" <?php echo $campaignFields['reward_type'] === 'free_shipping' ? 'selected' : ''; ?>>Free delivery</option>
        <option value="free_product" <?php echo $campaignFields['reward_type'] === 'free_product' ? 'selected' : ''; ?>>One free product</option>
    </select>
</label>
<label>Reward amount / percentage
    <input type="number" min="0" step="1" name="reward_amount" required value="<?php echo escapeOutput($campaignFields['reward_amount']); ?>">
</label>
<label>Voucher minimum spend (₱)
    <input type="number" min="0" step="1" name="minimum_spend" required value="<?php echo escapeOutput($campaignFields['minimum_spend']); ?>">
</label>
<label>Free product
    <select name="free_product_id">
        <option value="">Choose if needed</option>
        <?php foreach ($products as $product): ?>
            <option value="<?php echo (int)$product['id']; ?>" <?php echo (int)($campaignFields['free_product_id'] ?? 0) === (int)$product['id'] ? 'selected' : ''; ?>>
                <?php echo escapeOutput($product['name']); ?>
            </option>
        <?php endforeach; ?>
    </select>
</label>
<label>Voucher expiry (days)
    <input type="number" min="1" max="365" name="validity_days" required value="<?php echo (int)$campaignFields['validity_days']; ?>">
</label>
<?php unset($campaignFields); ?>
