<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Account;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Stripe\Exception\InvalidRequestException;
use Throwable;

/**
 * Verifies a Stripe Checkout Session belongs to the account and returns a
 * one-shot conversion payload for client-side purchase tracking.
 */
final class CheckoutPurchaseTracker
{
    public const CACHE_TTL_HOURS = 24;

    /**
     * @return array{
     *     conversion: array{kind: 'purchase'|'trial', value: float, currency: string, transaction_id: string, verified_at: string}|null,
     *     conversionResolved: bool
     * }
     */
    public function resolve(Account $account, string $sessionId): array
    {
        if ($account->stripe_id === null || $sessionId === '') {
            return [
                'conversion' => null,
                'conversionResolved' => true,
            ];
        }

        $trackedKey = $this->trackedKey($account->id, $sessionId);

        if (Cache::has($trackedKey)) {
            return [
                'conversion' => null,
                'conversionResolved' => true,
            ];
        }

        try {
            $session = $account->stripe()->checkout->sessions->retrieve($sessionId);
            $classification = CheckoutConversionData::classify(
                $session,
                (string) $account->stripe_id,
                true,
                $account->plan?->stripe_monthly_price_id,
            );

            if ($classification['outcome'] === CheckoutConversionData::OUTCOME_PENDING) {
                return [
                    'conversion' => null,
                    'conversionResolved' => false,
                ];
            }

            if (
                $classification['outcome'] === CheckoutConversionData::OUTCOME_TERMINAL
                || $classification['payload'] === null
            ) {
                Cache::add($trackedKey, true, $this->expiresAt());

                return [
                    'conversion' => null,
                    'conversionResolved' => true,
                ];
            }

            /** @var array{kind: 'purchase'|'trial', value: float, currency: string, transaction_id: string} $payload */
            $payload = $classification['payload'];

            // Consume on first delivery — the client keeps the prop in memory for polls.
            Cache::add($trackedKey, true, $this->expiresAt());

            return [
                'conversion' => [
                    ...$payload,
                    'verified_at' => now()->toIso8601String(),
                ],
                'conversionResolved' => true,
            ];
        } catch (InvalidRequestException) {
            Cache::add($trackedKey, true, $this->expiresAt());

            return [
                'conversion' => null,
                'conversionResolved' => true,
            ];
        } catch (Throwable) {
            return [
                'conversion' => null,
                'conversionResolved' => false,
            ];
        }
    }

    private function trackedKey(int|string $accountId, string $sessionId): string
    {
        return "checkout_tracked:{$accountId}:{$sessionId}";
    }

    private function expiresAt(): DateTimeInterface
    {
        return now()->addHours(self::CACHE_TTL_HOURS);
    }
}
