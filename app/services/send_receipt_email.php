<?php
require_once __DIR__ . '/mail_helper.php';
require_once dirname(__DIR__) . '/helpers/site_customization.php';

function sendReceiptEmail($toEmail, $toName, $orderData, ?array $siteCustomization = null): bool {
        $siteCustomization = $siteCustomization ?? site_customization_defaults();
        $brandName = htmlspecialchars($siteCustomization['brand_name'], ENT_QUOTES, 'UTF-8');
        $brandTagline = htmlspecialchars($siteCustomization['brand_tagline'], ENT_QUOTES, 'UTF-8');
        $primaryColor = htmlspecialchars($siteCustomization['primary_color'], ENT_QUOTES, 'UTF-8');
        $secondaryColor = htmlspecialchars($siteCustomization['secondary_color'], ENT_QUOTES, 'UTF-8');
        $accentColor = htmlspecialchars($siteCustomization['accent_color'], ENT_QUOTES, 'UTF-8');
        $pageColor = htmlspecialchars($siteCustomization['page_background'], ENT_QUOTES, 'UTF-8');
        $surfaceColor = htmlspecialchars($siteCustomization['surface_color'], ENT_QUOTES, 'UTF-8');
        $textColor = htmlspecialchars($siteCustomization['text_color'], ENT_QUOTES, 'UTF-8');

        // ✅ NEW: normalize special instructions into a clean array regardless
        // of whether the caller passed an array or a raw newline-separated string
        $special_instructions = [];
        if (!empty($orderData['special_instructions'])) {
            $raw_instructions = $orderData['special_instructions'];
            $lines = is_array($raw_instructions) ? $raw_instructions : explode("\n", (string)$raw_instructions);
            foreach ($lines as $line) {
                $line = trim((string)$line);
                if ($line !== '') {
                    $special_instructions[] = $line;
                }
            }
        }

        // ✅ NEW: pull the "[Preference: ...]" / "[Extras: ...]" notes cart.php now
        // appends to each product entry (e.g. "Caffè Americano (1 x Regular) [Preference: Hot / Iced: Iced] [Extras: Caramel Syrup (+₱15.00)]")
        // out into their own labeled lines, so the email shows them cleanly instead of raw brackets.
        function parseProductCustomizations($productLine) {
            $base = $productLine;
            $tags = [];
            if (preg_match_all('/\[(Preference|Extras):\s*(.*?)\]/i', $productLine, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $tags[] = ['label' => $m[1], 'value' => $m[2]];
                }
                $base = trim(preg_replace('/\s*\[(Preference|Extras):\s*.*?\]/i', '', $productLine));
            }
            return [$base, $tags];
        }

        // Stylish HTML body
        $body = "
        <div style='
            font-family: \"Segoe UI\", Arial, sans-serif;
            max-width: 600px;
            margin: 32px auto;
            padding: 32px 24px;
            border-radius: 14px;
            box-shadow: 0 4px 32px rgba(40,40,70,0.08);
            background: {$pageColor};
            border: 1px solid {$accentColor};'
        >
            <div style='text-align:center; margin-bottom: 24px;'>
                <h2 style='
                    color: {$primaryColor};
                    font-size: 2rem;
                    margin: 0 0 8px 0;
                    letter-spacing: 1px;
                    font-weight: bold;
                '>{$brandName} Order Update</h2>
                <span style='
                    color: {$accentColor};
                    font-size: 1rem;
                    letter-spacing: 0.5px;
                '>{$brandTagline}</span>
            </div>
            <p style='font-size:1.1rem; color: {$textColor}; margin:16px 0 10px 0;'>Hi " . htmlspecialchars($toName) . ",</p>
            <p style='font-size:1.07rem; color: {$textColor}; margin:0 0 22px 0;'>
                Your order status has been updated to:
                <span style='
                    color: #38a169;
                    font-weight: 600;
                    background: #e6fffa;
                    border-radius: 3px;
                    padding: 2px 8px;
                    margin-left: 2px;
                '>" . htmlspecialchars($orderData['status']) . "</span>.
            </p>
            <div style='background: {$surfaceColor}; color: {$textColor}; border-radius: 8px; border: 1px solid {$accentColor}; padding:18px 20px; margin-bottom: 24px;'>
                <h3 style='color:{$primaryColor}; font-size:1.15rem; margin:0 0 12px 0;'>Receipt</h3>
                <table style='width:100%; border-collapse:collapse; font-size:1rem;'>
                    <tr><td style='padding:8px 0; color:{$accentColor};'><strong>Order ID:</strong></td><td>" . htmlspecialchars($orderData['order_id']) . "</td></tr>
                    <tr><td style='padding:8px 0; color:{$accentColor};'><strong>Name:</strong></td><td>" . htmlspecialchars($orderData['name']) . "</td></tr>
                    <tr><td style='padding:8px 0; color:{$accentColor};'><strong>Address:</strong></td><td>" . htmlspecialchars($orderData['address']) . "</td></tr>
                    <tr><td style='padding:8px 0; color:{$accentColor};'><strong>Payment Method:</strong></td><td>" . htmlspecialchars($orderData['method']) . "</td></tr>
                    <tr><td style='padding:8px 0; color:{$accentColor};'><strong>Total:</strong></td><td style='font-weight:600;color:{$textColor};'>₱" . number_format($orderData['total'], 2) . "</td></tr>
                    <tr><td style='padding:8px 0; color:{$accentColor};'><strong>New Status:</strong></td><td style='color: {$primaryColor}; font-weight: bold;'>" . htmlspecialchars($orderData['status']) . "</td></tr>
                </table>
            </div>
            <div style='margin-bottom:18px;'>
                <h4 style='margin:0 0 10px 0; color:{$secondaryColor};'>Products Ordered:</h4>
                <ul style='
                    background: {$pageColor};
                    border-radius: 8px;
                    padding: 16px 24px;
                    list-style: disc inside;
                    margin: 0;
                '>";
        foreach ($orderData['products'] as $product) {
            [$base_line, $custom_tags] = parseProductCustomizations(trim($product));
            $body .= "<li style='padding: 3px 0; color:{$textColor};'>" . htmlspecialchars($base_line);
            if (!empty($custom_tags)) {
                $body .= "<div style='margin-top:4px;'>";
                foreach ($custom_tags as $tag) {
                    $body .= "<span style='
                        display:inline-block;
                        font-size:0.8rem;
                        font-weight:600;
                        color:{$primaryColor};
                        background:{$pageColor};
                        border:1px solid {$accentColor};
                        border-radius:20px;
                        padding:2px 10px;
                        margin:2px 6px 2px 0;
                    '>" . htmlspecialchars($tag['label']) . ": " . htmlspecialchars($tag['value']) . "</span>";
                }
                $body .= "</div>";
            }
            $body .= "</li>";
        }
        $body .= "</ul>
            </div>";

        // ✅ NEW: Special Instructions block, only rendered when there's actually something to show
        if (!empty($special_instructions)) {
            $body .= "
            <div style='margin-bottom:18px;'>
                <h4 style='margin:0 0 10px 0; color:{$secondaryColor};'>Special Instructions:</h4>
                <ul style='
                    background: {$pageColor};
                    border: 1px dashed {$accentColor};
                    border-radius: 8px;
                    padding: 16px 24px;
                    list-style: disc inside;
                    margin: 0;
                '>";
            foreach ($special_instructions as $note) {
                $body .= "<li style='padding: 3px 0; color:{$secondaryColor};'>" . htmlspecialchars($note) . "</li>";
            }
            $body .= "</ul>
            </div>";
        }

        $body .= "
            <p style='margin-top: 22px; font-size:1.1rem; color:{$primaryColor}; font-weight:500; text-align:center;'>
                Thank you for choosing {$brandName}!
            </p>
        </div>";

        // ✅ NEW: plain-text fallback now also mentions special instructions if present
        // (product customization tags are stripped from the plain-text version's product
        // names since altBody is meant to stay short — the HTML body has the full detail)
        $plainProducts = array_map(function ($product) {
            [$base_line] = parseProductCustomizations(trim($product));
            return $base_line;
        }, $orderData['products']);

        $altBody = "Your order #{$orderData['order_id']} status is now: {$orderData['status']}.";
        if (!empty($plainProducts)) {
            $altBody .= "\nProducts: " . implode(', ', $plainProducts) . ".";
        }
        if (!empty($special_instructions)) {
            $altBody .= " Special instructions: " . implode('; ', $special_instructions) . ".";
        }
        return send_six_origins_mail($toEmail, $toName, "Order Update - #" . $orderData['order_id'], $body, $altBody);
}