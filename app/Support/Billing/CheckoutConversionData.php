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

        // Abandoned (open/expired) sessions of the account's own customer are
        // not purchases — only a completed checkout may fire the pixel.
        if (data_get($session, 'status') !== 'complete') {
            return null;
        }

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
}
