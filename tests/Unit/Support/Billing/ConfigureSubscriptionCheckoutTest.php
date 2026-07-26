<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Workspace;
use App\Support\Billing\ConfigureSubscriptionCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\SubscriptionBuilder;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->account = Account::factory()->create();
    Workspace::factory()->create(['account_id' => $this->account->id]);

    config([
        'cashier.allow_promotion_codes' => true,
    ]);
});

function checkoutSubscription(Account $account): SubscriptionBuilder
{
    return $account->newSubscription(Account::SUBSCRIPTION_NAME, 'price_monthly_test');
}

function trialExpires(SubscriptionBuilder $subscription): mixed
{
    $property = new ReflectionProperty($subscription, 'trialExpires');

    return $property->getValue($subscription);
}

test('applies trial days and promotion codes when CASHIER_TRIAL_DAYS is set', function () {
    config(['cashier.trial_days' => 8]);

    $subscription = checkoutSubscription($this->account);

    ConfigureSubscriptionCheckout::apply($subscription);

    expect(trialExpires($subscription)->toDateString())
        ->toBe(now()->addDays(8)->toDateString())
        ->and($subscription->allowPromotionCodes)->toBeTrue()
        ->and($subscription->couponId)->toBeNull();
});

test('clamps a one-day trial to Stripe Checkout minimum of two days', function () {
    config(['cashier.trial_days' => 1]);

    $subscription = checkoutSubscription($this->account);

    ConfigureSubscriptionCheckout::apply($subscription);

    expect(trialExpires($subscription)->toDateString())
        ->toBe(now()->addDays(ConfigureSubscriptionCheckout::MIN_CHECKOUT_TRIAL_DAYS)->toDateString());
});

test('skips promotion codes when disabled by env', function () {
    config([
        'cashier.trial_days' => 8,
        'cashier.allow_promotion_codes' => false,
    ]);

    $subscription = checkoutSubscription($this->account);

    ConfigureSubscriptionCheckout::apply($subscription);

    expect(trialExpires($subscription))->not->toBeNull()
        ->and($subscription->allowPromotionCodes)->toBeFalse()
        ->and($subscription->couponId)->toBeNull();
});

test('skips trial when CASHIER_TRIAL_DAYS is zero but still allows promotion codes', function () {
    config(['cashier.trial_days' => 0]);

    $subscription = checkoutSubscription($this->account);

    ConfigureSubscriptionCheckout::apply($subscription);

    expect(trialExpires($subscription))->toBeNull()
        ->and($subscription->couponId)->toBeNull()
        ->and($subscription->allowPromotionCodes)->toBeTrue();
});

test('honors CASHIER_ALLOW_PROMOTION_CODES=false without a trial', function () {
    config([
        'cashier.trial_days' => 0,
        'cashier.allow_promotion_codes' => false,
    ]);

    $subscription = checkoutSubscription($this->account);

    ConfigureSubscriptionCheckout::apply($subscription);

    expect(trialExpires($subscription))->toBeNull()
        ->and($subscription->couponId)->toBeNull()
        ->and($subscription->allowPromotionCodes)->toBeFalse();
});
