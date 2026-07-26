<?php

declare(strict_types=1);

namespace App\Support\Billing;

/**
 * Parsers for Cashier checkout env values. Kept as pure functions so empty
 * strings and invalid input can be unit-tested without reloading Laravel config.
 */
final class CashierCheckoutEnv
{
    /**
     * Resolve trial days from an env/config raw value.
     * Empty / null falls back to $default so `CASHIER_TRIAL_DAYS=` does not
     * silently disable Checkout trials.
     */
    public static function trialDays(mixed $value, int $default = 8): int
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return max(0, (int) $value);
    }

    /**
     * Resolve allow_promotion_codes. Empty / null falls back to $default so
     * `CASHIER_ALLOW_PROMOTION_CODES=` does not silently disable the field.
     */
    public static function allowPromotionCodes(mixed $value, bool $default = true): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
