<?php

declare(strict_types=1);

use App\Actions\Billing\StartSubscriptionCheckout;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Billing\CheckoutConversionData;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    config([
        'trypost.self_hosted' => false,
        'trypost.billing.require_card_for_trial' => true,
        'cashier.trial_days' => 8,
        'cashier.allow_promotion_codes' => true,
    ]);
    Cache::flush();
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
    expect($first->headers->get(StartSubscriptionCheckout::CREATED_HEADER))->toBe('1')
        ->and($second->headers->get(StartSubscriptionCheckout::CREATED_HEADER))->toBe('0');

    $checkoutRequests = collect($stripe->requests)
        ->filter(fn (array $request): bool => str_contains($request['absUrl'], '/v1/checkout/sessions'));
    $checkoutRequest = $checkoutRequests->first();
    $params = data_get($checkoutRequest, 'params', []);

    expect($checkoutRequests)->toHaveCount(1)
        ->and(data_get($params, 'client_reference_id'))->toBe((string) $account->id)
        ->and(data_get($params, 'metadata.trypost_purpose'))->toBe(CheckoutConversionData::PURPOSE)
        ->and(data_get($params, 'metadata.trypost_account_id'))->toBe((string) $account->id)
        ->and(data_get($params, 'metadata.trypost_price_id'))->toBe('price_monthly_test')
        ->and(data_get($params, 'metadata.trypost_trial_days'))->toBe('8')
        ->and(data_get($params, 'line_items.0.quantity'))->toBe(2)
        ->and(data_get($params, 'success_url'))->toContain('{CHECKOUT_SESSION_ID}');
});
