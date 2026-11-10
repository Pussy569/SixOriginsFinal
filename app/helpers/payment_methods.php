<?php

function get_checkout_payment_methods(mysqli $conn): array
{
    $result = $conn->query(
        'SELECT cash_on_delivery, wallet_delivery, store_pickup_wallet
         FROM checkout_payment_settings WHERE id = 1'
    );
    $settings = $result->fetch_assoc();
    $result->free();
    if (!$settings) {
        throw new RuntimeException('Checkout payment settings are missing. Apply the payment methods database migration.');
    }

    return [
        'cash on delivery' => (int)$settings['cash_on_delivery'] === 1,
        'wallet balance' => (int)$settings['wallet_delivery'] === 1,
        'pickup' => (int)$settings['store_pickup_wallet'] === 1,
    ];
}
