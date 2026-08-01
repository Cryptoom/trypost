<?php

declare(strict_types=1);

namespace App\Support\Onboarding;

use App\Models\Account;

final class DismissAccountsWithAppAccess
{
    /**
     * Stamp onboarding_dismissed_at for every existing account that already
     * has app access, so residual activation does not appear for legacy
     * customers (active, trialing, past_due, grace, generic trial).
     */
    public static function run(): void
    {
        $dismissedAt = now();

        Account::query()
            ->with('subscriptions')
            ->whereNull('onboarding_dismissed_at')
            ->whereNull('onboarding_completed_at')
            ->orderBy('id')
            ->chunkById(100, function ($accounts) use ($dismissedAt): void {
                foreach ($accounts as $account) {
                    if (! $account->hasAppAccess()) {
                        continue;
                    }

                    $account->forceFill([
                        'onboarding_dismissed_at' => $dismissedAt,
                    ])->saveQuietly();
                }
            });
    }
}
