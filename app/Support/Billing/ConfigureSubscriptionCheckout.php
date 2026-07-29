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
        $trialDays = (int) config('cashier.trial_days');

        if ($trialDays > 0 && self::qualifiesForCheckoutTrial($account)) {
            // Stripe Checkout's minimum trial is 48 hours; clamp misconfigured 1-day values.
            $subscription->trialDays(max(self::MIN_CHECKOUT_TRIAL_DAYS, $trialDays));
        }

        if ((bool) config('cashier.allow_promotion_codes', true)) {
            $subscription->allowPromotionCodes();
        }

        return $subscription;
    }

    /**
     * Checkout trials are for genuinely new card-required signups. Returning
     * accounts (any prior subscription that left incomplete) and no-card
     * generic-trial installs should charge immediately / use promo codes.
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
