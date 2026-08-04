<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Account;
use App\Support\Billing\CheckoutConversionData;
use App\Support\Billing\ConfigureSubscriptionCheckout;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Laravel\Cashier\Cashier;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class StartSubscriptionCheckout
{
    private const PENDING_SESSION_TTL_HOURS = 24;

    private const LOCK_SECONDS = 180;

    private const LOCK_WAIT_SECONDS = 15;

    public const CREATED_HEADER = 'X-TryPost-Checkout-Created';

    /**
     * Create a Stripe Checkout session for the given price and return an Inertia
     * redirect to it. Quantity tracks the account's workspace count. Trial days
     * and promotion codes come from Cashier env config.
     */
    public function redirect(Account $account, string $priceId, string $cancelUrl): Response
    {
        $cacheKey = self::pendingCacheKey($account, $priceId);

        try {
            return Cache::lock("{$cacheKey}:lock", self::LOCK_SECONDS)->block(
                self::LOCK_WAIT_SECONDS,
                function () use ($account, $priceId, $cancelUrl, $cacheKey): Response {
                    $account->refresh();

                    if ($account->hasAppAccess()) {
                        self::forgetPending($cacheKey);

                        return $this->location(route('app.billing.processing'), created: false);
                    }

                    $pending = $this->resolvePendingCheckout($account, $cacheKey);

                    if (data_get($pending, 'kind') === 'reuse') {
                        return $this->location((string) data_get($pending, 'url'), created: false);
                    }

                    // Paid/complete session whose webhook has not landed yet —
                    // never mint a second Checkout (double-subscription risk).
                    if (data_get($pending, 'kind') === 'processing') {
                        $sessionId = (string) data_get($pending, 'session_id');

                        return $this->location(
                            route('app.billing.processing', ['session_id' => $sessionId]),
                            created: false,
                        );
                    }

                    $account->createOrGetStripeCustomer([
                        'email' => $account->stripeEmail(),
                        'name' => $account->stripeName(),
                    ]);

                    $subscription = $account->newSubscription(Account::SUBSCRIPTION_NAME, $priceId)
                        ->quantity(max(1, $account->workspaces()->count()));
                    $trialDays = ConfigureSubscriptionCheckout::checkoutTrialDays($account);

                    ConfigureSubscriptionCheckout::apply($subscription, $account);

                    $session = $subscription->checkout([
                        'success_url' => route('app.billing.processing').'?session_id={CHECKOUT_SESSION_ID}',
                        'cancel_url' => $cancelUrl,
                        'client_reference_id' => (string) $account->id,
                        'metadata' => [
                            'trypost_purpose' => CheckoutConversionData::PURPOSE,
                            'trypost_account_id' => (string) $account->id,
                            'trypost_price_id' => $priceId,
                            'trypost_trial_days' => (string) $trialDays,
                        ],
                    ]);

                    try {
                        Cache::put(
                            $cacheKey,
                            [
                                'url' => $session->url,
                                'session_id' => $session->id,
                            ],
                            now()->addHours(self::PENDING_SESSION_TTL_HOURS),
                        );
                    } catch (Throwable $exception) {
                        Log::warning('Stripe Checkout session could not be cached.', [
                            'account_id' => $account->id,
                            'session_id' => $session->id,
                            'exception' => $exception,
                        ]);
                    }

                    return $this->location($session->url, created: true);
                },
            );
        } catch (LockTimeoutException) {
            // 429 (not 409): Inertia uses 409 for external X-Inertia-Location redirects.
            abort(Response::HTTP_TOO_MANY_REQUESTS, 'Checkout creation is already in progress.');
        }
    }

    public static function pendingCacheKey(Account $account, string $priceId): string
    {
        return "billing:checkout:{$account->id}:".hash('sha256', $priceId);
    }

    /**
     * @return array{kind: 'reuse', url: string}|array{kind: 'processing', session_id: string}|array{kind: 'none'}
     */
    private function resolvePendingCheckout(Account $account, string $cacheKey): array
    {
        $pending = Cache::get($cacheKey);

        // Legacy string entries from before session-id verification — drop them.
        if (is_string($pending) && $pending !== '') {
            self::forgetPending($cacheKey);

            return ['kind' => 'none'];
        }

        if (! is_array($pending)) {
            return ['kind' => 'none'];
        }

        $url = data_get($pending, 'url');
        $sessionId = data_get($pending, 'session_id');

        if (! is_string($url) || $url === '' || ! is_string($sessionId) || $sessionId === '') {
            self::forgetPending($cacheKey);

            return ['kind' => 'none'];
        }

        try {
            $session = Cashier::stripe()->checkout->sessions->retrieve($sessionId);
        } catch (Throwable $exception) {
            Log::warning('Stripe Checkout pending session could not be retrieved.', [
                'account_id' => $account->id,
                'session_id' => $sessionId,
                'exception' => $exception,
            ]);
            self::forgetPending($cacheKey);

            return ['kind' => 'none'];
        }

        $status = $session->status ?? null;

        if ($status === 'open') {
            $liveUrl = is_string($session->url ?? null) && $session->url !== ''
                ? $session->url
                : $url;

            return ['kind' => 'reuse', 'url' => $liveUrl];
        }

        if ($status === 'complete') {
            self::forgetPending($cacheKey);

            return ['kind' => 'processing', 'session_id' => $sessionId];
        }

        // expired / canceled / unknown — mint a fresh session.
        self::forgetPending($cacheKey);

        return ['kind' => 'none'];
    }

    private static function forgetPending(string $cacheKey): void
    {
        try {
            Cache::forget($cacheKey);
        } catch (Throwable $exception) {
            Log::warning('Stripe Checkout pending cache could not be cleared.', [
                'cache_key' => $cacheKey,
                'exception' => $exception,
            ]);
        }
    }

    private function location(string $url, bool $created): Response
    {
        $response = Inertia::location($url);
        $response->headers->set(self::CREATED_HEADER, $created ? '1' : '0');

        return $response;
    }
}
