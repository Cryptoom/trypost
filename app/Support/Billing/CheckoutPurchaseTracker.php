<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Account;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Stripe\Exception\InvalidRequestException;
use Throwable;

/**
 * Verifies Stripe Checkout Sessions for purchase analytics and re-delivers the
 * conversion payload until the client acknowledges it or the cache expires.
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

        $verifiedKey = $this->verifiedKey($account->id, $sessionId);
        $trackedKey = $this->trackedKey($account->id, $sessionId);

        if (Cache::has($trackedKey)) {
            return [
                'conversion' => null,
                'conversionResolved' => true,
            ];
        }

        $verified = Cache::get($verifiedKey);

        if (is_array($verified)) {
            return $this->deliver($verified, $trackedKey);
        }

        try {
            $session = $account->stripe()->checkout->sessions->retrieve($sessionId);
            $expectedCustomer = (string) $account->stripe_id;
            $classification = CheckoutConversionData::classify(
                $session,
                $expectedCustomer,
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
            ) {
                $this->markEmpty($verifiedKey);

                return [
                    'conversion' => null,
                    'conversionResolved' => true,
                ];
            }

            $payload = $classification['payload'];

            if ($payload === null) {
                $this->markEmpty($verifiedKey);

                return [
                    'conversion' => null,
                    'conversionResolved' => true,
                ];
            }

            $verified = [
                'kind' => 'purchase',
                'payload' => $payload,
                'verified_at' => now()->toIso8601String(),
            ];

            Cache::add($verifiedKey, $verified, $this->expiresAt());

            $cached = Cache::get($verifiedKey);

            return $this->deliver(is_array($cached) ? $cached : $verified, $trackedKey);
        } catch (InvalidRequestException) {
            $this->markEmpty($verifiedKey);

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

    public function acknowledge(Account $account, string $sessionId): bool
    {
        if ($sessionId === '') {
            return false;
        }

        $verified = Cache::get($this->verifiedKey($account->id, $sessionId));
        $payload = is_array($verified) ? data_get($verified, 'payload') : null;

        if (
            data_get($verified, 'kind') !== 'purchase'
            || ! is_array($payload)
            || data_get($payload, 'transaction_id') !== $sessionId
        ) {
            return false;
        }

        return Cache::add(
            $this->trackedKey($account->id, $sessionId),
            true,
            $this->expiresAt(),
        );
    }

    /**
     * @return array{
     *     conversion: array{kind: 'purchase'|'trial', value: float, currency: string, transaction_id: string, verified_at: string}|null,
     *     conversionResolved: bool
     * }
     */
    private function deliver(array $verified, string $trackedKey): array
    {
        if (Cache::has($trackedKey) || data_get($verified, 'kind') !== 'purchase') {
            return [
                'conversion' => null,
                'conversionResolved' => true,
            ];
        }

        $payload = data_get($verified, 'payload');

        if (! is_array($payload)) {
            return [
                'conversion' => null,
                'conversionResolved' => true,
            ];
        }

        $verifiedAt = data_get($verified, 'verified_at');

        if (! is_string($verifiedAt) || $verifiedAt === '') {
            return [
                'conversion' => null,
                'conversionResolved' => true,
            ];
        }

        /** @var array{kind: 'purchase'|'trial', value: float, currency: string, transaction_id: string} $payload */
        return [
            'conversion' => [
                ...$payload,
                'verified_at' => $verifiedAt,
            ],
            'conversionResolved' => true,
        ];
    }

    private function markEmpty(string $verifiedKey): void
    {
        Cache::add($verifiedKey, [
            'kind' => 'empty',
            'payload' => null,
            'verified_at' => now()->toIso8601String(),
        ], $this->expiresAt());
    }

    private function verifiedKey(int|string $accountId, string $sessionId): string
    {
        return "checkout_verified:{$accountId}:{$sessionId}";
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
