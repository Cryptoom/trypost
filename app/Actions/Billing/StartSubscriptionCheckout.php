<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Account;
use App\Support\Billing\CheckoutConversionData;
use App\Support\Billing\ConfigureSubscriptionCheckout;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Laravel\Cashier\Cashier;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class StartSubscriptionCheckout
{
    private const PENDING_SESSION_TTL_HOURS = 24;

    /**
     * Create a Stripe Checkout session for the given price and return an Inertia
     * redirect to it. Quantity tracks the account's workspace count. Trial days
     * and promotion codes come from Cashier env config.
     */
    public function redirect(Account $account, string $priceId, string $cancelUrl): Response
    {
        $cacheKey = self::pendingCacheKey($account, $priceId);

        $account->refresh();

        if ($account->hasAppAccess()) {
            self::forgetPending($cacheKey);

            return $this->location(route('app.billing.processing'));
        }

        $pending = $this->resolvePendingCheckout($account, $cacheKey);

        if (data_get($pending, 'kind') === 'reuse') {
            return $this->location((string) data_get($pending, 'url'));
        }

        // Paid/complete session whose webhook has not landed yet —
        // never mint a second Checkout (double-subscription risk).
        if (data_get($pending, 'kind') === 'processing') {
            $sessionId = (string) data_get($pending, 'session_id');

            return $this->location(
                route('app.billing.processing', ['session_id' => $sessionId]),
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

        $this->rememberPending($cacheKey, (string) $session->url, (string) $session->id);

        return $this->location($session->url);
    }

    public static function pendingCacheKey(Account $account, string $priceId): string
    {
        return "billing:checkout:{$account->id}:{$priceId}";
    }

    /**
     * @return array{kind: 'reuse', url: string}|array{kind: 'processing', session_id: string}|array{kind: 'none'}
     */
    private function resolvePendingCheckout(Account $account, string $cacheKey): array
    {
        $pending = Cache::get($cacheKey);

        if (! is_array($pending)) {
            if ($pending !== null) {
                self::forgetPending($cacheKey);
            }

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
            // Keep the pending entry until hasAppAccess() clears it. Forgetting
            // here lets a second checkout attempt mint another Stripe session
            // while the webhook is still catching up (double-subscription risk).
            $this->rememberPending($cacheKey, $url, $sessionId);

            return ['kind' => 'processing', 'session_id' => $sessionId];
        }

        // expired / canceled / unknown — mint a fresh session.
        self::forgetPending($cacheKey);

        return ['kind' => 'none'];
    }

    private function rememberPending(string $cacheKey, string $url, string $sessionId): void
    {
        try {
            Cache::put(
                $cacheKey,
                [
                    'url' => $url,
                    'session_id' => $sessionId,
                ],
                now()->addHours(self::PENDING_SESSION_TTL_HOURS),
            );
        } catch (Throwable $exception) {
            Log::warning('Stripe Checkout pending session could not be re-cached.', [
                'cache_key' => $cacheKey,
                'session_id' => $sessionId,
                'exception' => $exception,
            ]);
        }
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

    private function location(string $url): Response
    {
        return Inertia::location($url);
    }
}
