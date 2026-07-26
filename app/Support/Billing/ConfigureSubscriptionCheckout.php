<?php

declare(strict_types=1);

namespace App\Support\Billing;

use Laravel\Cashier\SubscriptionBuilder;

final class ConfigureSubscriptionCheckout
{
    /**
     * Stripe Checkout rejects subscription trials shorter than 48 hours.
     */
    public const MIN_CHECKOUT_TRIAL_DAYS = 2;

    /**
     * Apply env-driven checkout options to a subscription builder.
     *
     * - `CASHIER_TRIAL_DAYS` > 0 → Stripe Checkout subscription trial (clamped to ≥ 2 days)
     * - `CASHIER_ALLOW_PROMOTION_CODES` → show the Checkout promotion-code field
     */
    public static function apply(SubscriptionBuilder $subscription): SubscriptionBuilder
    {
        $trialDays = (int) config('cashier.trial_days');

        if ($trialDays > 0) {
            // Stripe Checkout's minimum trial is 48 hours; clamp misconfigured 1-day values.
            $subscription->trialDays(max(self::MIN_CHECKOUT_TRIAL_DAYS, $trialDays));
        }

        if ((bool) config('cashier.allow_promotion_codes', true)) {
            $subscription->allowPromotionCodes();
        }

        return $subscription;
    }
}
