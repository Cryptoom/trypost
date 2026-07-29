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
        'trypost.billing.require_card_for_trial' => true,
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

    ConfigureSubscriptionCheckout::apply($subscription, $this->account);

    expect(trialExpires($subscription)->toDateString())
        ->toBe(now()->addDays(8)->toDateString())
        ->and($subscription->allowPromotionCodes)->toBeTrue()
        ->and($subscription->couponId)->toBeNull();
});

test('clamps a one-day trial to Stripe Checkout minimum of two days', function () {
    config(['cashier.trial_days' => 1]);

    $subscription = checkoutSubscription($this->account);

    ConfigureSubscriptionCheckout::apply($subscription, $this->account);

    expect(trialExpires($subscription)->toDateString())
        ->toBe(now()->addDays(ConfigureSubscriptionCheckout::MIN_CHECKOUT_TRIAL_DAYS)->toDateString());
});

test('skips promotion codes when disabled by env', function () {
    config([
        'cashier.trial_days' => 8,
        'cashier.allow_promotion_codes' => false,
    ]);

    $subscription = checkoutSubscription($this->account);

    ConfigureSubscriptionCheckout::apply($subscription, $this->account);

    expect(trialExpires($subscription))->not->toBeNull()
        ->and($subscription->allowPromotionCodes)->toBeFalse()
        ->and($subscription->couponId)->toBeNull();
});

test('skips trial when CASHIER_TRIAL_DAYS is zero but still allows promotion codes', function () {
    config(['cashier.trial_days' => 0]);

    $subscription = checkoutSubscription($this->account);

    ConfigureSubscriptionCheckout::apply($subscription, $this->account);

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

    ConfigureSubscriptionCheckout::apply($subscription, $this->account);

    expect(trialExpires($subscription))->toBeNull()
        ->and($subscription->couponId)->toBeNull()
        ->and($subscription->allowPromotionCodes)->toBeFalse();
});

test('skips checkout trial for accounts with a prior canceled subscription', function () {
    config(['cashier.trial_days' => 8]);

    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_'.fake()->uuid(),
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_123',
        'ends_at' => now()->subDay(),
    ]);

    $subscription = checkoutSubscription($this->account);

    ConfigureSubscriptionCheckout::apply($subscription, $this->account);

    expect(trialExpires($subscription))->toBeNull()
        ->and($subscription->allowPromotionCodes)->toBeTrue()
        ->and(ConfigureSubscriptionCheckout::qualifiesForCheckoutTrial($this->account))->toBeFalse();
});

test('still grants a trial after an incomplete checkout attempt', function () {
    config(['cashier.trial_days' => 8]);

    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_'.fake()->uuid(),
        'stripe_status' => 'incomplete',
        'stripe_price' => 'price_123',
    ]);

    $subscription = checkoutSubscription($this->account);

    ConfigureSubscriptionCheckout::apply($subscription, $this->account);

    expect(trialExpires($subscription)->toDateString())
        ->toBe(now()->addDays(8)->toDateString())
        ->and(ConfigureSubscriptionCheckout::qualifiesForCheckoutTrial($this->account))->toBeTrue();
});

test('still grants a trial after an incomplete_expired checkout attempt', function () {
    config(['cashier.trial_days' => 8]);

    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_'.fake()->uuid(),
        'stripe_status' => 'incomplete_expired',
        'stripe_price' => 'price_123',
    ]);

    $subscription = checkoutSubscription($this->account);

    ConfigureSubscriptionCheckout::apply($subscription, $this->account);

    expect(trialExpires($subscription)->toDateString())
        ->toBe(now()->addDays(8)->toDateString())
        ->and(ConfigureSubscriptionCheckout::qualifiesForCheckoutTrial($this->account))->toBeTrue();
});

test('skips checkout trial when REQUIRE_CARD_FOR_TRIAL is false', function () {
    config([
        'cashier.trial_days' => 8,
        'trypost.billing.require_card_for_trial' => false,
    ]);

    $subscription = checkoutSubscription($this->account);

    ConfigureSubscriptionCheckout::apply($subscription, $this->account);

    expect(trialExpires($subscription))->toBeNull()
        ->and($subscription->allowPromotionCodes)->toBeTrue()
        ->and(ConfigureSubscriptionCheckout::qualifiesForCheckoutTrial($this->account))->toBeFalse();
});

test('ignores non-default subscription types when deciding checkout trial eligibility', function () {
    config(['cashier.trial_days' => 8]);

    $this->account->subscriptions()->create([
        'type' => 'addon',
        'stripe_id' => 'sub_'.fake()->uuid(),
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_addon',
        'ends_at' => now()->subDay(),
    ]);

    $subscription = checkoutSubscription($this->account);

    ConfigureSubscriptionCheckout::apply($subscription, $this->account);

    expect(trialExpires($subscription)->toDateString())
        ->toBe(now()->addDays(8)->toDateString())
        ->and(ConfigureSubscriptionCheckout::qualifiesForCheckoutTrial($this->account))->toBeTrue();
});
