<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Billing\CheckoutPurchaseTracker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);

    $this->user = User::factory()->create();
    $this->account = $this->user->account;
    $this->account->forceFill(['stripe_id' => 'cus_test_123'])->save();
    $this->tracker = app(CheckoutPurchaseTracker::class);
});

test('re-delivers purchase conversion until acknowledge', function () {
    $sessionId = 'cs_test_'.fake()->uuid();
    fakeStripeHttp([[
        'body' => [
            'id' => $sessionId,
            'customer' => 'cus_test_123',
            'status' => 'complete',
            'amount_total' => 2500,
            'currency' => 'usd',
        ],
        'status' => 200,
    ]]);

    $first = $this->tracker->resolve($this->account->fresh(), $sessionId);
    $second = $this->tracker->resolve($this->account->fresh(), $sessionId);

    expect($first['conversionResolved'])->toBeTrue()
        ->and($first['conversion']['value'])->toEqual(25)
        ->and($second['conversion']['transaction_id'])->toBe($sessionId);

    $this->tracker->acknowledge($this->account->fresh(), $sessionId);

    expect($this->tracker->resolve($this->account->fresh(), $sessionId))->toBe([
        'conversion' => null,
        'conversionResolved' => true,
    ]);
});

test('stops re-delivering after the grace window without an ack', function () {
    Carbon::setTestNow('2026-08-03 12:00:00');

    $sessionId = 'cs_test_'.fake()->uuid();
    fakeStripeHttp([[
        'body' => [
            'id' => $sessionId,
            'customer' => 'cus_test_123',
            'status' => 'complete',
            'amount_total' => 1000,
            'currency' => 'usd',
        ],
        'status' => 200,
    ]]);

    expect($this->tracker->resolve($this->account->fresh(), $sessionId)['conversion'])->not->toBeNull();

    Carbon::setTestNow(now()->addMinutes(CheckoutPurchaseTracker::REDELIVER_GRACE_MINUTES + 1));

    expect($this->tracker->resolve($this->account->fresh(), $sessionId))->toBe([
        'conversion' => null,
        'conversionResolved' => true,
    ]);
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
