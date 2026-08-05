<?php

declare(strict_types=1);

use App\Actions\Billing\StartSubscriptionCheckout;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Billing\CheckoutConversionData;
use Illuminate\Support\Facades\Cache;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;

beforeEach(function () {
    config([
        'trypost.self_hosted' => false,
        'trypost.billing.require_card_for_trial' => true,
        'cashier.trial_days' => 8,
        'cashier.allow_promotion_codes' => true,
    ]);
    Cache::flush();
});

afterEach(function () {
    // The Stripe HTTP fake is a process-global static — never leak it.
    ApiRequestor::setHttpClient(new CurlClient);
});

test('reuses the pending checkout session and stamps its purpose', function () {
    $user = User::factory()->create();
    $account = $user->account;
    Workspace::factory()->count(2)->create([
        'account_id' => $account->id,
        'user_id' => $user->id,
    ]);
    $sessionId = 'cs_test_'.fake()->uuid();
    $stripe = fakeStripeHttp([
        [
            'body' => [
                'id' => 'cus_test_123',
                'object' => 'customer',
                'email' => $account->billing_email,
            ],
            'status' => 200,
        ],
        [
            'body' => [
                'id' => $sessionId,
                'object' => 'checkout.session',
                'url' => 'https://checkout.stripe.test/session',
                'status' => 'open',
            ],
            'status' => 200,
        ],
        [
            'body' => [
                'id' => $sessionId,
                'object' => 'checkout.session',
                'url' => 'https://checkout.stripe.test/session',
                'status' => 'open',
            ],
            'status' => 200,
        ],
    ]);

    $action = app(StartSubscriptionCheckout::class);
    $first = $action->redirect($account, 'price_monthly_test', route('app.welcome.referral-source'));
    $second = $action->redirect($account->fresh(), 'price_monthly_test', route('app.welcome.referral-source'));
    $firstLocation = $first->headers->get('X-Inertia-Location')
        ?? $first->headers->get('Location');
    $secondLocation = $second->headers->get('X-Inertia-Location')
        ?? $second->headers->get('Location');

    expect($firstLocation)->toBe('https://checkout.stripe.test/session')
        ->and($secondLocation)->toBe('https://checkout.stripe.test/session');

    $checkoutCreates = collect($stripe->requests)
        ->filter(fn (array $request): bool => $request['method'] === 'post'
            && str_contains($request['absUrl'], '/v1/checkout/sessions'));
    $checkoutRequest = $checkoutCreates->first();
    $params = data_get($checkoutRequest, 'params', []);

    expect($checkoutCreates)->toHaveCount(1)
        ->and(data_get($params, 'client_reference_id'))->toBe((string) $account->id)
        ->and(data_get($params, 'metadata.trypost_purpose'))->toBe(CheckoutConversionData::PURPOSE)
        ->and(data_get($params, 'metadata.trypost_account_id'))->toBe((string) $account->id)
        ->and(data_get($params, 'metadata.trypost_price_id'))->toBe('price_monthly_test')
        ->and(data_get($params, 'metadata.trypost_trial_days'))->toBe('8')
        ->and(data_get($params, 'line_items.0.quantity'))->toBe(2)
        ->and(data_get($params, 'success_url'))->toContain('{CHECKOUT_SESSION_ID}');
});

test('routes a completed checkout session to processing and keeps pending until app access', function () {
    $user = User::factory()->create();
    $account = $user->account;
    Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $user->id,
    ]);
    $completedSessionId = 'cs_test_'.fake()->uuid();
    $pendingKey = StartSubscriptionCheckout::pendingCacheKey($account, 'price_monthly_test');
    Cache::put(
        $pendingKey,
        [
            'url' => 'https://checkout.stripe.test/stale',
            'session_id' => $completedSessionId,
        ],
        now()->addDay(),
    );

    $stripe = fakeStripeHttp([
        [
            'body' => [
                'id' => $completedSessionId,
                'object' => 'checkout.session',
                'url' => 'https://checkout.stripe.test/stale',
                'status' => 'complete',
            ],
            'status' => 200,
        ],
        [
            'body' => [
                'id' => $completedSessionId,
                'object' => 'checkout.session',
                'url' => 'https://checkout.stripe.test/stale',
                'status' => 'complete',
            ],
            'status' => 200,
        ],
    ]);

    $action = app(StartSubscriptionCheckout::class);
    $first = $action->redirect(
        $account,
        'price_monthly_test',
        route('app.welcome.referral-source'),
    );
    $second = $action->redirect(
        $account->fresh(),
        'price_monthly_test',
        route('app.welcome.referral-source'),
    );

    $firstLocation = $first->headers->get('X-Inertia-Location')
        ?? $first->headers->get('Location');
    $secondLocation = $second->headers->get('X-Inertia-Location')
        ?? $second->headers->get('Location');
    $processingUrl = route('app.billing.processing', ['session_id' => $completedSessionId]);

    expect($firstLocation)->toBe($processingUrl)
        ->and($secondLocation)->toBe($processingUrl)
        ->and(Cache::has($pendingKey))->toBeTrue()
        ->and(Cache::get($pendingKey)['session_id'])->toBe($completedSessionId);

    $creates = collect($stripe->requests)
        ->filter(fn (array $request): bool => $request['method'] === 'post'
            && str_contains($request['absUrl'], '/v1/checkout/sessions'));

    expect($creates)->toHaveCount(0);
});

test('mints a fresh checkout when the pending session expired', function () {
    $user = User::factory()->create();
    $account = $user->account;
    Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $user->id,
    ]);
    $expiredSessionId = 'cs_test_'.fake()->uuid();
    $freshSessionId = 'cs_test_'.fake()->uuid();
    Cache::put(
        StartSubscriptionCheckout::pendingCacheKey($account, 'price_monthly_test'),
        [
            'url' => 'https://checkout.stripe.test/expired',
            'session_id' => $expiredSessionId,
        ],
        now()->addDay(),
    );

    $stripe = fakeStripeHttp([
        [
            'body' => [
                'id' => $expiredSessionId,
                'object' => 'checkout.session',
                'url' => 'https://checkout.stripe.test/expired',
                'status' => 'expired',
            ],
            'status' => 200,
        ],
        [
            'body' => [
                'id' => 'cus_test_123',
                'object' => 'customer',
                'email' => $account->billing_email,
            ],
            'status' => 200,
        ],
        [
            'body' => [
                'id' => $freshSessionId,
                'object' => 'checkout.session',
                'url' => 'https://checkout.stripe.test/fresh',
                'status' => 'open',
            ],
            'status' => 200,
        ],
    ]);

    $response = app(StartSubscriptionCheckout::class)->redirect(
        $account,
        'price_monthly_test',
        route('app.welcome.referral-source'),
    );
    $location = $response->headers->get('X-Inertia-Location')
        ?? $response->headers->get('Location');

    expect($location)->toBe('https://checkout.stripe.test/fresh');

    $creates = collect($stripe->requests)
        ->filter(fn (array $request): bool => $request['method'] === 'post'
            && str_contains($request['absUrl'], '/v1/checkout/sessions'));

    expect($creates)->toHaveCount(1);
});
