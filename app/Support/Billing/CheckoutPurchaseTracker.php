<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Account;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Stripe\Exception\InvalidRequestException;
use Throwable;

/**
 * Verifies Stripe Checkout Sessions for purchase analytics and re-delivers the
 * conversion payload until the client acknowledges (or a short grace expires).
 *
 * Two-phase keys:
 * - checkout_verified:{account}:{session} — Stripe outcome (purchase payload or empty)
 * - checkout_tracked:{account}:{session} — client ack / grace / terminal empty
 */
final class CheckoutPurchaseTracker
{
    public const TRACKED_TTL_YEARS = 100;

    /** Re-deliver conversion after first verify until ack, then stop. */
    public const REDELIVER_GRACE_MINUTES = 15;

    /**
     * @return array{
     *     conversion: array{value: float, currency: string, transaction_id: string}|null,
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
        $verifiedKey = $this->verifiedKey($account->id, $sessionId);
        $ttl = now()->addYears(self::TRACKED_TTL_YEARS);

        if (Cache::has($trackedKey)) {
            return [
                'conversion' => null,
                'conversionResolved' => true,
            ];
        }

        $verified = Cache::get($verifiedKey);

        if (is_array($verified)) {
            return $this->deliver($verified, $trackedKey, $ttl);
        }

        try {
            $session = $account->stripe()->checkout->sessions->retrieve($sessionId);
            $expectedCustomer = (string) $account->stripe_id;
            $customer = data_get($session, 'customer');
            $status = data_get($session, 'status');

            if ($customer !== $expectedCustomer) {
                $this->markEmpty($verifiedKey, $trackedKey, $ttl);

                return [
                    'conversion' => null,
                    'conversionResolved' => true,
                ];
            }

            if ($status === 'open') {
                return [
                    'conversion' => null,
                    'conversionResolved' => false,
                ];
            }

            if ($status !== 'complete') {
                $this->markEmpty($verifiedKey, $trackedKey, $ttl);

                return [
                    'conversion' => null,
                    'conversionResolved' => true,
                ];
            }

            $payload = CheckoutConversionData::fromSession($session, $expectedCustomer);

            if ($payload === null) {
                $this->markEmpty($verifiedKey, $trackedKey, $ttl);

                return [
                    'conversion' => null,
                    'conversionResolved' => true,
                ];
            }

            $record = [
                'kind' => 'purchase',
                'payload' => $payload,
                'verified_at' => now()->toIso8601String(),
            ];

            Cache::add($verifiedKey, $record, $ttl);
            $stored = Cache::get($verifiedKey);

            return $this->deliver(
                is_array($stored) ? $stored : $record,
                $trackedKey,
                $ttl,
            );
        } catch (InvalidRequestException) {
            $this->markEmpty($verifiedKey, $trackedKey, $ttl);

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

    public function acknowledge(Account $account, string $sessionId): void
    {
        if ($sessionId === '') {
            return;
        }

        Cache::add(
            $this->trackedKey($account->id, $sessionId),
            true,
            now()->addYears(self::TRACKED_TTL_YEARS),
        );
    }

    /**
     * @param  array{kind?: string, payload?: mixed, verified_at?: string}  $verified
     * @return array{
     *     conversion: array{value: float, currency: string, transaction_id: string}|null,
     *     conversionResolved: bool
     * }
     */
    private function deliver(array $verified, string $trackedKey, mixed $ttl): array
    {
        if (data_get($verified, 'kind') !== 'purchase') {
            Cache::add($trackedKey, true, $ttl);

            return [
                'conversion' => null,
                'conversionResolved' => true,
            ];
        }

        $verifiedAt = data_get($verified, 'verified_at');
        $payload = data_get($verified, 'payload');

        if (
            is_string($verifiedAt)
            && now()->greaterThan(Carbon::parse($verifiedAt)->addMinutes(self::REDELIVER_GRACE_MINUTES))
        ) {
            Cache::add($trackedKey, true, $ttl);

            return [
                'conversion' => null,
                'conversionResolved' => true,
            ];
        }

        if (! is_array($payload)) {
            Cache::add($trackedKey, true, $ttl);

            return [
                'conversion' => null,
                'conversionResolved' => true,
            ];
        }

        /** @var array{value: float, currency: string, transaction_id: string} $payload */
        return [
            'conversion' => $payload,
            'conversionResolved' => true,
        ];
    }

    private function markEmpty(string $verifiedKey, string $trackedKey, mixed $ttl): void
    {
        Cache::add($verifiedKey, [
            'kind' => 'empty',
            'payload' => null,
            'verified_at' => now()->toIso8601String(),
        ], $ttl);
        Cache::add($trackedKey, true, $ttl);
    }

    private function trackedKey(int|string $accountId, string $sessionId): string
    {
        return "checkout_tracked:{$accountId}:{$sessionId}";
    }

    private function verifiedKey(int|string $accountId, string $sessionId): string
    {
        return "checkout_verified:{$accountId}:{$sessionId}";
    }
}
