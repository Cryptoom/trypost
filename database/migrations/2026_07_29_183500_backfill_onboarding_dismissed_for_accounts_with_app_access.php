<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Stamp onboarding_dismissed_at for legacy accounts that already have app
     * access, so the new activation checklist does not appear for existing
     * customers. Mirrors Account::hasAppAccess() / Cashier subscribed() with
     * keepPastDueSubscriptionsActive enabled.
     */
    public function up(): void
    {
        $now = now();

        if (config('trypost.self_hosted')) {
            DB::table('accounts')
                ->whereNull('onboarding_dismissed_at')
                ->whereNull('onboarding_completed_at')
                ->update([
                    'onboarding_dismissed_at' => $now,
                    'updated_at' => $now,
                ]);

            return;
        }

        $query = DB::table('accounts')
            ->whereNull('onboarding_dismissed_at')
            ->whereNull('onboarding_completed_at')
            ->where(function (Builder $accounts) use ($now): void {
                $accounts->whereExists(function (Builder $subscriptions) use ($now): void {
                    $subscriptions->selectRaw('1')
                        ->from('subscriptions')
                        ->whereColumn('subscriptions.account_id', 'accounts.id')
                        ->where('subscriptions.type', 'default')
                        ->where(function (Builder $valid) use ($now): void {
                            $valid
                                ->where('subscriptions.ends_at', '>', $now)
                                ->orWhere('subscriptions.trial_ends_at', '>', $now)
                                ->orWhere(function (Builder $active) use ($now): void {
                                    $active->where(function (Builder $notEnded) use ($now): void {
                                        $notEnded->whereNull('subscriptions.ends_at')
                                            ->orWhere('subscriptions.ends_at', '>', $now);
                                    })->whereNotIn('subscriptions.stripe_status', [
                                        'incomplete',
                                        'incomplete_expired',
                                        'unpaid',
                                    ]);
                                });
                        });
                });

                if (! (bool) config('trypost.billing.require_card_for_trial', true)) {
                    $accounts->orWhere('accounts.trial_ends_at', '>', $now);
                }
            });

        $query->update([
            'onboarding_dismissed_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
