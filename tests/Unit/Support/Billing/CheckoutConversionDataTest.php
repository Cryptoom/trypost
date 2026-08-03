<?php

declare(strict_types=1);

use App\Support\Billing\CheckoutConversionData;

test('builds conversion from amount_total including a zero trial charge', function () {
    $payload = CheckoutConversionData::fromSession([
        'id' => 'cs_test_123',
        'customer' => 'cus_abc',
        'status' => 'complete',
        'payment_status' => 'no_payment_required',
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
        'status' => 'complete',
        'payment_status' => 'paid',
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
        'status' => 'complete',
        'payment_status' => 'paid',
        'amount_total' => 100,
        'currency' => 'usd',
    ], 'cus_abc'))->toBeNull();
});

test('rejects sessions that were never completed', function (string $status) {
    expect(CheckoutConversionData::fromSession([
        'id' => 'cs_test_123',
        'customer' => 'cus_abc',
        'status' => $status,
        'amount_total' => 100,
        'currency' => 'usd',
    ], 'cus_abc'))->toBeNull();
})->with(['open', 'expired']);

test('rejects a completed checkout whose payment is still unpaid', function () {
    expect(CheckoutConversionData::fromSession([
        'id' => 'cs_test_123',
        'customer' => 'cus_abc',
        'status' => 'complete',
        'payment_status' => 'unpaid',
        'amount_total' => 100,
        'currency' => 'usd',
    ], 'cus_abc'))->toBeNull();
});

test('accepts completed checkouts with a settled payment status', function (string $paymentStatus) {
    expect(CheckoutConversionData::fromSession([
        'id' => 'cs_test_123',
        'customer' => 'cus_abc',
        'status' => 'complete',
        'payment_status' => $paymentStatus,
        'amount_total' => 100,
        'currency' => 'usd',
    ], 'cus_abc'))->not->toBeNull();
})->with(['paid', 'no_payment_required']);

test('rejects incomplete session payloads', function (array $session) {
    expect(CheckoutConversionData::fromSession($session, 'cus_abc'))->toBeNull();
})->with([
    'missing amount' => [[
        'id' => 'cs_test_123',
        'customer' => 'cus_abc',
        'status' => 'complete',
        'payment_status' => 'paid',
        'currency' => 'usd',
    ]],
    'empty currency' => [[
        'id' => 'cs_test_123',
        'customer' => 'cus_abc',
        'status' => 'complete',
        'payment_status' => 'paid',
        'amount_total' => 0,
        'currency' => '',
    ]],
    'empty id' => [[
        'id' => '',
        'customer' => 'cus_abc',
        'status' => 'complete',
        'payment_status' => 'paid',
        'amount_total' => 0,
        'currency' => 'usd',
    ]],
]);
