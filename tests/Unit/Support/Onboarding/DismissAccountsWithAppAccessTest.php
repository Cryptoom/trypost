<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\User;
use App\Support\Onboarding\DismissAccountsWithAppAccess;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Model::preventLazyLoading();

    config([
        'trypost.self_hosted' => false,
        'trypost.billing.require_card_for_trial' => true,
    ]);
});

test('dismisses accounts with past_due subscriptions that still have app access', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');

    $user = User::factory()->create();
    $account = $user->account;

    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_'.fake()->uuid(),
        'stripe_status' => 'past_due',
        'stripe_price' => 'price_123',
    ]);

    expect($account->fresh()->hasAppAccess())->toBeTrue()
        ->and($account->fresh()->onboarding_dismissed_at)->toBeNull();

    DismissAccountsWithAppAccess::run();

    expect($account->fresh()->onboarding_dismissed_at?->equalTo(now()))->toBeTrue();
});

test('dismisses accounts on a canceled subscription that is still in grace', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');

    $user = User::factory()->create();
    $account = $user->account;

    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_'.fake()->uuid(),
        'stripe_status' => 'canceled',
        'stripe_price' => 'price_123',
        'ends_at' => now()->addDays(5),
    ]);

    expect($account->fresh()->hasAppAccess())->toBeTrue();

    DismissAccountsWithAppAccess::run();

    expect($account->fresh()->onboarding_dismissed_at?->equalTo(now()))->toBeTrue();
});

test('does not dismiss accounts without app access', function () {
    $user = User::factory()->create();

    DismissAccountsWithAppAccess::run();

    expect($user->account->fresh()->onboarding_dismissed_at)->toBeNull();
});

test('does not overwrite already completed or dismissed accounts', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');

    $completed = User::factory()->create();
    subscribeAccount($completed->account);
    $completed->account->update(['onboarding_completed_at' => now()->subDay()]);

    $dismissed = User::factory()->create();
    subscribeAccount($dismissed->account);
    $dismissed->account->update(['onboarding_dismissed_at' => now()->subDay()]);

    DismissAccountsWithAppAccess::run();

    expect($completed->account->fresh()->onboarding_dismissed_at)->toBeNull()
        ->and($completed->account->fresh()->onboarding_completed_at?->equalTo(now()->subDay()))->toBeTrue()
        ->and($dismissed->account->fresh()->onboarding_dismissed_at?->equalTo(now()->subDay()))->toBeTrue();
});
