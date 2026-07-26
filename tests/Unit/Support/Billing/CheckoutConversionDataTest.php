<?php

declare(strict_types=1);

use App\Support\Billing\CheckoutConversionData;

test('builds conversion from amount_total including a zero trial charge', function () {
    $payload = CheckoutConversionData::fromSession([
        'id' => 'cs_test_123',
        'customer' => 'cus_abc',
        'amount_total' => 0,
        'currency' => 'usd',
    ], 'cus_abc');

    expect($payload['value'])->toBe(0)
        ->and($payload['currency'])->toBe('USD')
        ->and($payload['transaction_id'])->toBe('cs_test_123');
});

test('builds conversion from a discounted first-month total', function () {
    $payload = CheckoutConversionData::fromSession([
        'id' => 'cs_test_456',
        'customer' => 'cus_abc',
        'amount_total' => 100,
        'currency' => 'usd',
    ], 'cus_abc');

    expect($payload['value'])->toBe(1)
        ->and($payload['currency'])->toBe('USD')
        ->and($payload['transaction_id'])->toBe('cs_test_456');
});

test('rejects sessions for a different stripe customer', function () {
    expect(CheckoutConversionData::fromSession([
        'id' => 'cs_test_123',
        'customer' => 'cus_other',
        'amount_total' => 100,
        'currency' => 'usd',
    ], 'cus_abc'))->toBeNull();
});

test('rejects incomplete session payloads', function (array $session) {
    expect(CheckoutConversionData::fromSession($session, 'cus_abc'))->toBeNull();
})->with([
    'missing amount' => [[
        'id' => 'cs_test_123',
        'customer' => 'cus_abc',
        'currency' => 'usd',
    ]],
    'empty currency' => [[
        'id' => 'cs_test_123',
        'customer' => 'cus_abc',
        'amount_total' => 0,
        'currency' => '',
    ]],
    'empty id' => [[
        'id' => '',
        'customer' => 'cus_abc',
        'amount_total' => 0,
        'currency' => 'usd',
    ]],
]);
