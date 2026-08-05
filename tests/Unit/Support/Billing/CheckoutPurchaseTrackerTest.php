<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Billing\CheckoutPurchaseTracker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);

    $this->user = User::factory()->create();
    $this->account = $this->user->account;
    $this->account->forceFill(['stripe_id' => 'cus_test_123'])->save();
    $this->tracker = app(CheckoutPurchaseTracker::class);
});

afterEach(function () {
    // The Stripe HTTP fake is a process-global static — never leak it.
    ApiRequestor::setHttpClient(new CurlClient);
});

test('delivers a purchase conversion once then suppresses it', function () {
    $sessionId = 'cs_test_'.fake()->uuid();
    fakeStripeHttp([[
        'body' => [
            'id' => $sessionId,
            'customer' => 'cus_test_123',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => 2500,
            'currency' => 'usd',
        ],
        'status' => 200,
    ]]);

    $first = $this->tracker->resolve($this->account->fresh(), $sessionId);
    $second = $this->tracker->resolve($this->account->fresh(), $sessionId);

    expect($first['conversionResolved'])->toBeTrue()
        ->and($first['conversion']['value'])->toEqual(25)
        ->and($first['conversion']['transaction_id'])->toBe($sessionId)
        ->and($second)->toBe([
            'conversion' => null,
            'conversionResolved' => true,
        ]);
});

test('forgets a tracked purchase after twenty four hours', function () {
    Carbon::setTestNow('2026-08-03 12:00:00');

    $sessionId = 'cs_test_'.fake()->uuid();
    fakeStripeHttp([
        ['body' => [
            'id' => $sessionId,
            'customer' => 'cus_test_123',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => 1000,
            'currency' => 'usd',
        ], 'status' => 200],
        ['body' => [
            'id' => $sessionId,
            'customer' => 'cus_test_123',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => 2000,
            'currency' => 'usd',
        ], 'status' => 200],
    ]);

    expect($this->tracker->resolve($this->account->fresh(), $sessionId)['conversion']['value'])->toEqual(10);

    Carbon::setTestNow(now()->addHours(23));

    expect($this->tracker->resolve($this->account->fresh(), $sessionId))->toBe([
        'conversion' => null,
        'conversionResolved' => true,
    ]);

    Carbon::setTestNow(now()->addHours(2));

    expect($this->tracker->resolve($this->account->fresh(), $sessionId)['conversion']['value'])->toEqual(20);
});

test('leaves open sessions unresolved without caching a tracked key', function () {
    $sessionId = 'cs_test_'.fake()->uuid();
    fakeStripeHttp([[
        'body' => [
            'id' => $sessionId,
            'customer' => 'cus_test_123',
            'status' => 'open',
            'amount_total' => 1000,
            'currency' => 'usd',
        ],
        'status' => 200,
    ]]);

    expect($this->tracker->resolve($this->account->fresh(), $sessionId))->toBe([
        'conversion' => null,
        'conversionResolved' => false,
    ])->and(Cache::has("checkout_tracked:{$this->account->id}:{$sessionId}"))->toBeFalse();
});

test('keeps an unpaid completed session unresolved until Stripe reports payment', function () {
    $sessionId = 'cs_test_'.fake()->uuid();
    $session = [
        'id' => $sessionId,
        'customer' => 'cus_test_123',
        'status' => 'complete',
        'payment_status' => 'paid',
        'amount_total' => 1000,
        'currency' => 'usd',
    ];
    fakeStripeHttp([
        ['body' => [...$session, 'payment_status' => 'unpaid'], 'status' => 200],
        ['body' => [...$session, 'payment_status' => 'paid'], 'status' => 200],
    ]);

    expect($this->tracker->resolve($this->account->fresh(), $sessionId))->toBe([
        'conversion' => null,
        'conversionResolved' => false,
    ]);

    $resolved = $this->tracker->resolve($this->account->fresh(), $sessionId);

    expect($resolved['conversionResolved'])->toBeTrue()
        ->and($resolved['conversion']['transaction_id'])->toBe($sessionId);
});

test('accepts an expanded Stripe customer owned by the account', function () {
    $sessionId = 'cs_test_'.fake()->uuid();
    fakeStripeHttp([[
        'body' => [
            'id' => $sessionId,
            'customer' => [
                'id' => 'cus_test_123',
                'object' => 'customer',
            ],
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => 1000,
            'currency' => 'usd',
        ],
        'status' => 200,
    ]]);

    $resolved = $this->tracker->resolve($this->account->fresh(), $sessionId);

    expect($resolved['conversionResolved'])->toBeTrue()
        ->and($resolved['conversion']['transaction_id'])->toBe($sessionId);
});

test('rejects checkout sessions outside the TryPost subscription flow', function (array $overrides) {
    $sessionId = 'cs_test_'.fake()->uuid();
    fakeStripeHttp([[
        'body' => [
            'id' => $sessionId,
            'customer' => 'cus_test_123',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => 1000,
            'currency' => 'usd',
            ...$overrides,
        ],
        'status' => 200,
    ]]);

    expect($this->tracker->resolve($this->account->fresh(), $sessionId))->toBe([
        'conversion' => null,
        'conversionResolved' => true,
    ]);
})->with([
    'payment mode' => [['mode' => 'payment']],
    'wrong purpose' => [[
        'metadata' => ['trypost_purpose' => 'different_checkout'],
    ]],
]);
