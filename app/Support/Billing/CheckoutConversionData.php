<?php

declare(strict_types=1);

namespace App\Support\Billing;

final class CheckoutConversionData
{
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
     *     payload: array{value: float, currency: string, transaction_id: string}|null
     * }
     */
    public static function classify(object|array $session, string $expectedCustomerId): array
    {
        if (self::customerId($session) !== $expectedCustomerId) {
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

    /**
     * @return array{value: float, currency: string, transaction_id: string}|null
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
            'value' => $amountTotal / 100,
            'currency' => strtoupper((string) $currency),
            'transaction_id' => (string) $transactionId,
        ];
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
     * @param  array{value: float, currency: string, transaction_id: string}|null  $payload
     * @return array{
     *     outcome: 'pending'|'purchase'|'terminal',
     *     payload: array{value: float, currency: string, transaction_id: string}|null
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
