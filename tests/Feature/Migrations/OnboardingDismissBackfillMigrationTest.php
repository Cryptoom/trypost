<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config([
        'trypost.self_hosted' => false,
        'trypost.billing.require_card_for_trial' => true,
    ]);

    $this->runBackfill = function (): void {
        $migration = require database_path(
            'migrations/2026_07_29_183500_backfill_onboarding_dismissed_for_accounts_with_app_access.php',
        );

        $migration->up();
    };
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

    ($this->runBackfill)();

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

    ($this->runBackfill)();

    expect($account->fresh()->onboarding_dismissed_at?->equalTo(now()))->toBeTrue();
});

test('does not dismiss accounts without app access', function () {
    $user = User::factory()->create();

    ($this->runBackfill)();

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

    ($this->runBackfill)();

    expect($completed->account->fresh()->onboarding_dismissed_at)->toBeNull()
        ->and($completed->account->fresh()->onboarding_completed_at?->equalTo(now()->subDay()))->toBeTrue()
        ->and($dismissed->account->fresh()->onboarding_dismissed_at?->equalTo(now()->subDay()))->toBeTrue();
});

test('dismisses generic-trial accounts when card is not required', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');
    config(['trypost.billing.require_card_for_trial' => false]);

    $user = User::factory()->create();
    DB::table('accounts')->where('id', $user->account_id)->update([
        'trial_ends_at' => now()->addDays(7),
    ]);

    expect($user->account->fresh()->hasAppAccess())->toBeTrue();

    ($this->runBackfill)();

    expect($user->account->fresh()->onboarding_dismissed_at?->equalTo(now()))->toBeTrue();
});
