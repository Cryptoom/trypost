<?php

declare(strict_types=1);

namespace App\Support\Billing;

final class CheckoutConversionData
{
    public const PURPOSE = 'trypost_subscription';

    public const OUTCOME_PENDING = 'pending';

    public const OUTCOME_PURCHASE = 'purchase';

    public const OUTCOME_TERMINAL = 'terminal';

    private const SETTLED_PAYMENT_STATUSES = [
        'paid',
        'no_payment_required',
    ];

    /**
     * @return array{
     *     outcome: 'pending'|'purchase'|'terminal',
     *     payload: array{kind: 'purchase'|'trial', value: float, currency: string, transaction_id: string}|null
     * }
     */
    public static function classify(
        object|array $session,
        string $expectedCustomerId,
        bool $requireSubscriptionPurpose = false,
        ?string $expectedPriceId = null,
    ): array {
        if (self::customerId($session) !== $expectedCustomerId) {
            return self::result(self::OUTCOME_TERMINAL);
        }

        if (
            $requireSubscriptionPurpose
            && ! self::matchesSubscriptionPurpose($session, $expectedPriceId)
        ) {
            return self::result(self::OUTCOME_TERMINAL);
        }

        $status = data_get($session, 'status');

        if ($status === 'open') {
            return self::result(self::OUTCOME_PENDING);
        }

        if ($status === 'complete' && ! self::hasSettledPayment($session)) {
            return self::result(self::OUTCOME_PENDING);
        }

        if ($status !== 'complete') {
            return self::result(self::OUTCOME_TERMINAL);
        }

        $payload = self::buildPayload($session);

        return $payload === null
            ? self::result(self::OUTCOME_TERMINAL)
            : self::result(self::OUTCOME_PURCHASE, $payload);
    }

    private static function hasSettledPayment(object|array $session): bool
    {
        return in_array(
            data_get($session, 'payment_status'),
            self::SETTLED_PAYMENT_STATUSES,
            true,
        );
    }

    private static function matchesSubscriptionPurpose(
        object|array $session,
        ?string $expectedPriceId,
    ): bool {
        if (
            data_get($session, 'mode') !== 'subscription'
            || data_get($session, 'metadata.trypost_purpose') !== self::PURPOSE
        ) {
            return false;
        }

        return $expectedPriceId === null
            || data_get($session, 'metadata.trypost_price_id') === $expectedPriceId;
    }

    /**
     * @return array{kind: 'purchase'|'trial', value: float, currency: string, transaction_id: string}|null
     */
    private static function buildPayload(object|array $session): ?array
    {
        $amountTotal = data_get($session, 'amount_total');
        $currency = data_get($session, 'currency');
        $transactionId = data_get($session, 'id');

        if (! is_int($amountTotal) || blank($currency) || blank($transactionId)) {
            return null;
        }

        return [
            'kind' => self::isTrial($session) ? 'trial' : 'purchase',
            'value' => $amountTotal / 100,
            'currency' => strtoupper((string) $currency),
            'transaction_id' => (string) $transactionId,
        ];
    }

    private static function isTrial(object|array $session): bool
    {
        return data_get($session, 'payment_status') === 'no_payment_required'
            && (int) data_get($session, 'metadata.trypost_trial_days', 0) > 0;
    }

    private static function customerId(object|array $session): ?string
    {
        $customer = data_get($session, 'customer');
        $customerId = is_string($customer)
            ? $customer
            : data_get($customer, 'id');

        return is_string($customerId) ? $customerId : null;
    }

    /**
     * @param  array{kind: 'purchase'|'trial', value: float, currency: string, transaction_id: string}|null  $payload
     * @return array{
     *     outcome: 'pending'|'purchase'|'terminal',
     *     payload: array{kind: 'purchase'|'trial', value: float, currency: string, transaction_id: string}|null
     * }
     */
    private static function result(string $outcome, ?array $payload = null): array
    {
        return [
            'outcome' => $outcome,
            'payload' => $payload,
        ];
    }
}
