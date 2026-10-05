<?php
/**
 * TextBee SMS Notification Service
 * Uses an Android phone (registered on textbee.dev) as the SMS gateway.
 */

function sendOrderStatusSMS($phone_number, $order_id, $status, $total_price, $customer_name) {
    $api_key = getenv('TEXTBEE_API_KEY') ?: '';
    // Optional: force a specific registered device. Leave empty string to let
    // TextBee use your default/most recently active device.
    $device_id = getenv('TEXTBEE_DEVICE_ID') ?: '';

    error_log("========== SMS SEND START ==========");
    error_log("Phone Input: $phone_number");
    error_log("Order ID: $order_id");
    error_log("Status: $status");

    try {
        if ($api_key === '') {
            error_log("TextBee API key is not configured.");
            error_log("========== SMS SEND END (FAILED) ==========");
            return false;
        }

        $phone = formatPhoneNumberForTwilio($phone_number);

        error_log("Phone After Format: [$phone]");

        if (empty($phone)) {
            error_log("❌ PHONE IS EMPTY AFTER FORMATTING");
            error_log("========== SMS SEND END (FAILED) ==========");
            return false;
        }

        $message = getStatusMessage($customer_name, $status, $order_id, $total_price);

        error_log("Message: $message");
        error_log("Attempting to connect to TextBee API...");

        // TEXTBEE API ENDPOINT (account-level; picks default/most recently active device)
        $url = "https://api.textbee.dev/api/v1/gateway/send-sms";

        $data = [
            'recipients' => [$phone],
            'message'    => $message,
        ];

        if (!empty($device_id)) {
            $data['deviceId'] = $device_id;
        }

        error_log("Request URL: $url");
        error_log("Request Data: " . json_encode($data));

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        error_log("Sending request...");

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);

        curl_close($ch);

        error_log("HTTP Code: $http_code");
        error_log("Curl Error: " . ($curl_error ?: "None"));
        error_log("Raw Response: $response");

        $result = json_decode($response, true);
        error_log("Decoded Response: " . json_encode($result));

        // TextBee returns 200/201 with a JSON body (including a smsBatchId) on success
        if (($http_code == 200 || $http_code == 201) && is_array($result)) {
            error_log("✅ SMS SUCCESS!");
            error_log("Response: " . json_encode($result));
            error_log("========== SMS SEND END (SUCCESS) ==========");
            return true;
        } else {
            error_log("❌ SMS FAILED!");
            error_log("HTTP Code: $http_code");
            error_log("Response: " . json_encode($result));
            error_log("========== SMS SEND END (FAILED) ==========");
            return false;
        }

    } catch (Exception $e) {
        error_log("❌ EXCEPTION!");
        error_log("Message: " . $e->getMessage());
        error_log("========== SMS SEND END (EXCEPTION) ==========");
        return false;
    }
}

function formatPhoneNumberForTwilio($phone) {
    $original = $phone;
    $phone = preg_replace('/[^0-9+]/', '', $phone);

    // Already in +63 format
    if (preg_match('/^\+63\d{10}$/', $phone)) {
        error_log("[PHONE] Already +63 format: $phone");
        return $phone;
    }
    // 09xxxxxxxxx format
    elseif (preg_match('/^09\d{9}$/', $phone)) {
        $converted = '+63' . substr($phone, 1);
        error_log("[PHONE] Converted 09 to: $converted");
        return $converted;
    }
    // 9xxxxxxxxx format
    elseif (preg_match('/^9\d{9}$/', $phone)) {
        $converted = '+63' . $phone;
        error_log("[PHONE] Converted 9 to: $converted");
        return $converted;
    }
    // 63xxxxxxxxxx format
    elseif (preg_match('/^63\d{10}$/', $phone)) {
        $converted = '+' . $phone;
        error_log("[PHONE] Converted 63 to: $converted");
        return $converted;
    }
    else {
        error_log("❌ [PHONE] INVALID FORMAT: $original");
        return '';
    }
}

function getStatusMessage($customer_name, $status, $order_id, $total_price) {
    $status = strtolower($status);

    $messages = [
        'pending' => "Hi $customer_name! Your order #$order_id (₱" . number_format($total_price, 2) . ") is pending. We'll confirm shortly.",
        'accepted' => "Hi $customer_name! Your order #$order_id has been accepted. We're preparing it now!",
        'preparing' => "Hi $customer_name! Your order #$order_id is being prepared. Coming soon!",
        'done preparing' => "Hi $customer_name! Your order #$order_id is ready and waiting to head out!",
        'out for delivery' => "Hi $customer_name! Your order #$order_id is on the way!",
        'completed' => "Hi $customer_name! Your order #$order_id delivered. Thank you for ordering!",
        'cancelled' => "Hi $customer_name! Your order #$order_id has been cancelled. Contact us for help."
    ];

    return $messages[$status] ?? "Hi $customer_name! Your order #$order_id status: " . ucfirst($status);
}
?>