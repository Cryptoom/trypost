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
        $cacheKey = "billing:checkout:{$account->id}:".hash('sha256', $priceId);

        try {
            return Cache::lock("{$cacheKey}:lock", self::LOCK_SECONDS)->block(
                self::LOCK_WAIT_SECONDS,
                function () use ($account, $priceId, $cancelUrl, $cacheKey): Response {
                    $account->refresh();

                    if ($account->hasAppAccess()) {
                        return $this->location(route('app.billing.processing'), created: false);
                    }

                    $pendingUrl = Cache::get($cacheKey);

                    if (is_string($pendingUrl) && $pendingUrl !== '') {
                        return $this->location($pendingUrl, created: false);
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
                            $session->url,
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
            abort(Response::HTTP_CONFLICT, 'Checkout creation is already in progress.');
        }
    }

    private function location(string $url, bool $created): Response
    {
        $response = Inertia::location($url);
        $response->headers->set(self::CREATED_HEADER, $created ? '1' : '0');

        return $response;
    }
}
