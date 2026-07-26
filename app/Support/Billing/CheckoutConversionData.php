<?php

declare(strict_types=1);

namespace App\Support\Billing;

final class CheckoutConversionData
{
    /**
     * Build purchase conversion payload from a Stripe Checkout Session.
     * Uses amount_total (amount collected) so trials report $0 instead of list price.
     *
     * @return array{value: float, currency: string, transaction_id: string}|null
     */
    public static function fromSession(object|array $session, string $expectedCustomerId): ?array
    {
        if (data_get($session, 'customer') !== $expectedCustomerId) {
            return null;
        }

        $amountTotal = data_get($session, 'amount_total');
        $currency = data_get($session, 'currency');
        $transactionId = data_get($session, 'id');

        if (! is_int($amountTotal) || ! is_string($currency) || $currency === '' || ! is_string($transactionId) || $transactionId === '') {
            return null;
        }

        return [
            'value' => $amountTotal / 100,
            'currency' => strtoupper($currency),
            'transaction_id' => $transactionId,
        ];
    }
}
