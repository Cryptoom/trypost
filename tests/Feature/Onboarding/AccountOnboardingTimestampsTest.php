<?php

declare(strict_types=1);

use App\Models\User;

test('account can store onboarding completed and dismissed timestamps', function () {
    $user = User::factory()->create();
    $account = $user->account;

    $account->update([
        'onboarding_completed_at' => now(),
        'onboarding_dismissed_at' => now()->subMinute(),
    ]);

    $account->refresh();

    expect($account->onboarding_completed_at)->not->toBeNull()
        ->and($account->onboarding_dismissed_at)->not->toBeNull();
});
