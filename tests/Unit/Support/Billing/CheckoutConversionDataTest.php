<?php

declare(strict_types=1);

use App\Support\Billing\CheckoutConversionData;

test('classifies checkout sessions for purchase tracking', function (
    array $overrides,
    string $expectedOutcome,
) {
    $classification = CheckoutConversionData::classify([
        'id' => 'cs_test_123',
        'customer' => 'cus_abc',
        'status' => 'complete',
        'payment_status' => 'paid',
        'amount_total' => 100,
        'currency' => 'usd',
        ...$overrides,
    ], 'cus_abc');

    expect($classification['outcome'])->toBe($expectedOutcome)
        ->and($classification['payload'])
        ->when(
            $expectedOutcome === 'purchase',
            fn ($payload) => $payload->toMatchArray([
                'value' => $overrides['amount_total'] ?? 1,
                'currency' => 'USD',
                'transaction_id' => 'cs_test_123',
            ]),
            fn ($payload) => $payload->toBeNull(),
        );
})->with([
    'wrong customer is terminal' => [
        ['customer' => 'cus_other'],
        'terminal',
    ],
    'open session is pending' => [
        ['status' => 'open'],
        'pending',
    ],
    'unpaid completed session is pending' => [
        ['payment_status' => 'unpaid'],
        'pending',
    ],
    'expired session is terminal' => [
        ['status' => 'expired'],
        'terminal',
    ],
    'incomplete purchase data is terminal' => [
        ['amount_total' => null],
        'terminal',
    ],
    'paid checkout is a purchase' => [
        [],
        'purchase',
    ],
    'zero-value checkout without trial metadata is a purchase' => [
        ['payment_status' => 'no_payment_required', 'amount_total' => 0],
        'purchase',
    ],
]);

test('classifies a no-payment checkout with trial metadata as a trial', function () {
    $payload = CheckoutConversionData::classify([
        'id' => 'cs_test_trial',
        'customer' => 'cus_abc',
        'mode' => 'subscription',
        'status' => 'complete',
        'payment_status' => 'no_payment_required',
        'amount_total' => 0,
        'currency' => 'usd',
        'metadata' => [
            'trypost_purpose' => CheckoutConversionData::PURPOSE,
            'trypost_trial_days' => '8',
        ],
    ], 'cus_abc', true)['payload'];

    expect($payload['kind'])->toBe('trial')
        ->and($payload['value'])->toBe(0);
});

test('builds conversion from amount_total including a zero trial charge', function () {
    $payload = CheckoutConversionData::classify([
        'id' => 'cs_test_123',
        'customer' => 'cus_abc',
        'status' => 'complete',
        'payment_status' => 'no_payment_required',
        'amount_total' => 0,
        'currency' => 'usd',
    ], 'cus_abc')['payload'];

    expect($payload['value'])->toBe(0)
        ->and($payload['currency'])->toBe('USD')
        ->and($payload['transaction_id'])->toBe('cs_test_123');
});

test('builds conversion from a discounted first-month total', function () {
    $payload = CheckoutConversionData::classify([
        'id' => 'cs_test_456',
        'customer' => 'cus_abc',
        'status' => 'complete',
        'payment_status' => 'paid',
        'amount_total' => 100,
        'currency' => 'usd',
    ], 'cus_abc')['payload'];

    expect($payload['value'])->toBe(1)
        ->and($payload['currency'])->toBe('USD')
        ->and($payload['transaction_id'])->toBe('cs_test_456');
});

test('rejects sessions for a different stripe customer', function () {
    expect(CheckoutConversionData::classify([
        'id' => 'cs_test_123',
        'customer' => 'cus_other',
        'status' => 'complete',
        'payment_status' => 'paid',
        'amount_total' => 100,
        'currency' => 'usd',
    ], 'cus_abc')['payload'])->toBeNull();
});

test('rejects sessions that were never completed', function (string $status) {
    expect(CheckoutConversionData::classify([
        'id' => 'cs_test_123',
        'customer' => 'cus_abc',
        'status' => $status,
        'amount_total' => 100,
        'currency' => 'usd',
    ], 'cus_abc')['payload'])->toBeNull();
})->with(['open', 'expired']);

test('rejects a completed checkout whose payment is still unpaid', function () {
    expect(CheckoutConversionData::classify([
        'id' => 'cs_test_123',
        'customer' => 'cus_abc',
        'status' => 'complete',
        'payment_status' => 'unpaid',
        'amount_total' => 100,
        'currency' => 'usd',
    ], 'cus_abc')['payload'])->toBeNull();
});

test('accepts completed checkouts with a settled payment status', function (string $paymentStatus) {
    expect(CheckoutConversionData::classify([
        'id' => 'cs_test_123',
        'customer' => 'cus_abc',
        'status' => 'complete',
        'payment_status' => $paymentStatus,
        'amount_total' => 100,
        'currency' => 'usd',
    ], 'cus_abc')['payload'])->not->toBeNull();
})->with(['paid', 'no_payment_required']);

test('rejects incomplete session payloads', function (array $session) {
    expect(CheckoutConversionData::classify($session, 'cus_abc')['payload'])->toBeNull();
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
