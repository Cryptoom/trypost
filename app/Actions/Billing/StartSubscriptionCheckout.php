<?php

declare(strict_types=1);

namespace App\Actions\Billing;

use App\Models\Account;
use App\Support\Billing\CheckoutConversionData;
use App\Support\Billing\ConfigureSubscriptionCheckout;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

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
        $cacheKey = "billing:checkout:{$account->id}:".hash('sha256', $priceId);

        return Cache::lock("{$cacheKey}:lock", 15)->block(10, function () use (
            $account,
            $priceId,
            $cancelUrl,
            $cacheKey,
        ): Response {
            $account->refresh();

            if ($account->hasAppAccess()) {
                return Inertia::location(route('app.billing.processing'));
            }

            $pendingUrl = Cache::get($cacheKey);

            if (is_string($pendingUrl) && $pendingUrl !== '') {
                return Inertia::location($pendingUrl);
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

            Cache::put(
                $cacheKey,
                $session->url,
                now()->addHours(self::PENDING_SESSION_TTL_HOURS),
            );

            return Inertia::location($session->url);
        });
    }
}
