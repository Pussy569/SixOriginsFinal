<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/helpers/loyalty.php';

$tests = [
    'below cap uses percentage of subtotal only' => [
        ['type' => 'percentage', 'amount' => 20, 'maximum_discount_cap' => 100],
        300.0, 36.0, 39.0, 60.0,
    ],
    'at cap returns the cap' => [
        ['type' => 'percentage', 'amount' => 20, 'maximum_discount_cap' => 100],
        500.0, 60.0, 39.0, 100.0,
    ],
    'above cap stays capped' => [
        ['type' => 'percentage', 'amount' => 20, 'maximum_discount_cap' => 100],
        1000.0, 120.0, 39.0, 100.0,
    ],
    'zero cap means no cap but excludes tax and delivery' => [
        ['type' => 'percentage', 'amount' => 20, 'maximum_discount_cap' => 0],
        300.0, 36.0, 39.0, 60.0,
    ],
    'null cap means no cap' => [
        ['type' => 'percentage', 'amount' => 20, 'maximum_discount_cap' => null],
        500.0, 60.0, 39.0, 100.0,
    ],
    'requested checkout example' => [
        ['type' => 'percentage', 'amount' => 20, 'maximum_discount_cap' => 100],
        500.0, 60.0, 39.0, 100.0,
    ],
    'percentage discount cannot exceed product subtotal' => [
        ['type' => 'percentage', 'amount' => 100, 'maximum_discount_cap' => 1000],
        500.0, 60.0, 39.0, 500.0,
    ],
    'fixed discount cannot exceed product subtotal' => [
        ['type' => 'fixed', 'amount' => 700],
        500.0, 60.0, 39.0, 500.0,
    ],
];

$failures = [];
foreach ($tests as $name => [$voucher, $subtotal, $vat, $deliveryFee, $expected]) {
    $actual = loyalty_calculate_discount($voucher, $subtotal, $vat, $deliveryFee, 'delivery', []);
    if (abs($actual - $expected) > 0.001) {
        $failures[] = $name . ': expected ' . number_format($expected, 2) . ', got ' . number_format($actual, 2);
    }
}

$exampleDiscount = loyalty_calculate_discount(
    ['type' => 'percentage', 'amount' => 20, 'maximum_discount_cap' => 100],
    500.0,
    60.0,
    39.0,
    'delivery',
    []
);
$exampleTotal = 500.0 + 60.0 + 39.0 - $exampleDiscount;
if (abs($exampleTotal - 499.0) > 0.001) {
    $failures[] = 'requested checkout example total: expected 499.00, got ' . number_format($exampleTotal, 2);
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo (count($tests) + 1) . " voucher discount tests passed." . PHP_EOL;
