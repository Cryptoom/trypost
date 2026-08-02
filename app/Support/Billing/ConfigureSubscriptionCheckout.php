<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Account;
use Laravel\Cashier\SubscriptionBuilder;
use Stripe\Subscription as StripeSubscription;

final class ConfigureSubscriptionCheckout
{
    /**
     * Stripe Checkout rejects subscription trials shorter than 48 hours.
     */
    public const MIN_CHECKOUT_TRIAL_DAYS = 2;

    /**
     * Apply env-driven checkout options to a subscription builder.
     *
     * - Qualifying first-time checkouts get `CASHIER_TRIAL_DAYS` (clamped to ≥ 2)
     * - Returning / no-card-mode checkouts skip the trial and keep promotion codes
     * - `CASHIER_ALLOW_PROMOTION_CODES` → show the Checkout promotion-code field
     */
    public static function apply(SubscriptionBuilder $subscription, Account $account): SubscriptionBuilder
    {
        $trialDays = self::checkoutTrialDays($account);

        if ($trialDays > 0) {
            $subscription->trialDays($trialDays);
        }

        if (CashierCheckoutEnv::allowPromotionCodes(config('cashier.allow_promotion_codes'))) {
            $subscription->allowPromotionCodes();
        }

        return $subscription;
    }

    /**
     * Trial length the checkout will actually apply — the single source of truth
     * for both the builder and any UI that advertises the trial. 0 disables the
     * trial (immediate charge); 1 is clamped to Stripe's 48-hour minimum.
     */
    public static function checkoutTrialDays(Account $account): int
    {
        $trialDays = (int) config('cashier.trial_days');

        if ($trialDays <= 0 || ! self::qualifiesForCheckoutTrial($account)) {
            return 0;
        }

        return max(self::MIN_CHECKOUT_TRIAL_DAYS, $trialDays);
    }

    /**
     * Checkout trials are for genuinely new card-required signups. Accounts whose
     * only prior subscriptions died incomplete (never paid) still qualify; any
     * subscription that made it past incomplete, and no-card generic-trial
     * installs, skip the trial and keep promotion codes.
     */
    public static function qualifiesForCheckoutTrial(Account $account): bool
    {
        if (! (bool) config('trypost.billing.require_card_for_trial', true)) {
            return false;
        }

        return ! $account->subscriptions()
            ->where('type', Account::SUBSCRIPTION_NAME)
            ->whereNotIn('stripe_status', [
                StripeSubscription::STATUS_INCOMPLETE,
                StripeSubscription::STATUS_INCOMPLETE_EXPIRED,
            ])
            ->exists();
    }
}
