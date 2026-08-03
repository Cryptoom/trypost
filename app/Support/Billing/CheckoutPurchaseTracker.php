<?php

declare(strict_types=1);

namespace App\Support\Billing;

use App\Models\Account;
use App\Models\CheckoutPurchaseTracking;
use Stripe\Exception\InvalidRequestException;
use Throwable;

/**
 * Verifies Stripe Checkout Sessions for purchase analytics and re-delivers the
 * conversion payload until the client acknowledges it. Durable database state
 * prevents a cache reset from re-emitting an already acknowledged conversion.
 */
final class CheckoutPurchaseTracker
{
    /**
     * @return array{
     *     conversion: array{value: float, currency: string, transaction_id: string, verified_at: string}|null,
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

        $tracking = $this->findTracking($account, $sessionId);

        if ($tracking !== null) {
            return $this->deliver($tracking);
        }

        try {
            $session = $account->stripe()->checkout->sessions->retrieve($sessionId);
            $expectedCustomer = (string) $account->stripe_id;
            $customer = data_get($session, 'customer');
            $customerId = is_string($customer)
                ? $customer
                : data_get($customer, 'id');
            $status = data_get($session, 'status');

            if ($customerId !== $expectedCustomer) {
                $this->markEmpty($account, $sessionId);

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

            if (
                $status === 'complete'
                && ! CheckoutConversionData::hasSettledPayment($session)
            ) {
                return [
                    'conversion' => null,
                    'conversionResolved' => false,
                ];
            }

            if ($status !== 'complete') {
                $this->markEmpty($account, $sessionId);

                return [
                    'conversion' => null,
                    'conversionResolved' => true,
                ];
            }

            $payload = CheckoutConversionData::fromSession($session, $expectedCustomer);

            if ($payload === null) {
                $this->markEmpty($account, $sessionId);

                return [
                    'conversion' => null,
                    'conversionResolved' => true,
                ];
            }

            $tracking = CheckoutPurchaseTracking::query()->firstOrCreate(
                [
                    'account_id' => $account->id,
                    'session_id' => $sessionId,
                ],
                [
                    'kind' => 'purchase',
                    'payload' => $payload,
                    'verified_at' => now(),
                ],
            );

            return $this->deliver($tracking);
        } catch (InvalidRequestException) {
            $this->markEmpty($account, $sessionId);

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

        return CheckoutPurchaseTracking::query()
            ->whereBelongsTo($account)
            ->where('session_id', $sessionId)
            ->where('kind', 'purchase')
            ->where('payload->transaction_id', $sessionId)
            ->whereNull('acknowledged_at')
            ->update(['acknowledged_at' => now()]) === 1;
    }

    /**
     * @return array{
     *     conversion: array{value: float, currency: string, transaction_id: string, verified_at: string}|null,
     *     conversionResolved: bool
     * }
     */
    private function deliver(CheckoutPurchaseTracking $tracking): array
    {
        if ($tracking->kind !== 'purchase' || $tracking->acknowledged_at !== null) {
            return [
                'conversion' => null,
                'conversionResolved' => true,
            ];
        }

        $payload = $tracking->payload;

        if (! is_array($payload)) {
            return [
                'conversion' => null,
                'conversionResolved' => true,
            ];
        }

        if ($tracking->verified_at === null) {
            return [
                'conversion' => null,
                'conversionResolved' => true,
            ];
        }

        /** @var array{value: float, currency: string, transaction_id: string} $payload */
        return [
            'conversion' => [
                ...$payload,
                'verified_at' => $tracking->verified_at->toIso8601String(),
            ],
            'conversionResolved' => true,
        ];
    }

    private function findTracking(Account $account, string $sessionId): ?CheckoutPurchaseTracking
    {
        return CheckoutPurchaseTracking::query()
            ->whereBelongsTo($account)
            ->where('session_id', $sessionId)
            ->first();
    }

    private function markEmpty(Account $account, string $sessionId): void
    {
        CheckoutPurchaseTracking::query()->firstOrCreate(
            [
                'account_id' => $account->id,
                'session_id' => $sessionId,
            ],
            [
                'kind' => 'empty',
                'payload' => null,
                'verified_at' => now(),
            ],
        );
    }
}
