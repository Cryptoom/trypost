<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Models\Account;
use App\Support\Billing\CheckoutConversionData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Stripe\Exception\InvalidRequestException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class BillingController extends Controller
{
    /** Durable dedupe for checkout purchase tracking (matches onboarding step cache). */
    private const CHECKOUT_TRACKED_TTL_YEARS = 100;

    public function subscribe(): RedirectResponse
    {
        return redirect()->route('app.welcome.persona');
    }

    public function processing(Request $request): Response|RedirectResponse
    {
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
        }

        $user = $request->user();
        $account = $user->account;
        $sessionId = $request->query('session_id');

        // Verified purchase conversion for ad/analytics purchase events
        // (PostHog + Meta/Google via GTM) — not a Stripe pixel. Stripe only
        // proves the Checkout Session belongs to this account.
        $conversion = null;
        // True when verification is conclusive (or there is nothing to verify).
        // Processing.vue must not stop polling until this is true when a
        // session_id is present — otherwise active-sub races drop purchase events.
        $conversionResolved = true;

        if (
            $account !== null
            && $account->stripe_id
            && is_string($sessionId)
            && $sessionId !== ''
        ) {
            $cacheKey = "checkout_tracked:{$account->id}:{$sessionId}";
            $trackedTtl = now()->addYears(self::CHECKOUT_TRACKED_TTL_YEARS);

            if (Cache::has($cacheKey)) {
                // Already consumed — conclusive, no (re)payload.
                $conversionResolved = true;
            } else {
                $conversionResolved = false;

                try {
                    $session = $account->stripe()->checkout->sessions->retrieve($sessionId);
                    $expectedCustomer = (string) $account->stripe_id;
                    $customer = data_get($session, 'customer');
                    $status = data_get($session, 'status');

                    if ($customer !== $expectedCustomer) {
                        // Wrong customer — conclusive; consume once, no purchase.
                        Cache::add($cacheKey, true, $trackedTtl);
                        $conversionResolved = true;
                    } elseif ($status === 'open') {
                        // Still completing — leave unset so a later poll can pick up
                        // completion without permanently losing purchase tracking.
                        $conversionResolved = false;
                    } elseif ($status !== 'complete') {
                        // expired / unknown terminal — conclusive; stop retrying Stripe.
                        Cache::add($cacheKey, true, $trackedTtl);
                        $conversionResolved = true;
                    } else {
                        $payload = CheckoutConversionData::fromSession($session, $expectedCustomer);

                        if ($payload === null) {
                            // Complete + matching customer but unusable payload —
                            // resolve without conversion so the UI does not hang,
                            // without treating a transient malformed retrieve as a
                            // successful purchase claim for analytics.
                            Cache::add($cacheKey, true, $trackedTtl);
                            $conversionResolved = true;
                        } elseif (Cache::add($cacheKey, true, $trackedTtl)) {
                            // Claim first sight atomically so concurrent polls cannot
                            // double-fire purchase tracking.
                            $conversion = $payload;
                            $conversionResolved = true;
                        } else {
                            // Lost the race to another poll — already claimed.
                            $conversionResolved = true;
                        }
                    }
                } catch (InvalidRequestException) {
                    // Missing/invalid session_id — conclusive; consume so the poll
                    // loop does not hammer Stripe. No purchase event.
                    Cache::add($cacheKey, true, $trackedTtl);
                    $conversionResolved = true;
                } catch (Throwable) {
                    // Transient Stripe/network failure — leave the key unset so the
                    // next processing poll can retry verification.
                    $conversionResolved = false;
                }
            }
        }

        return Inertia::render('billing/Processing', [
            'subscriptionActive' => $account && $account->subscribed(Account::SUBSCRIPTION_NAME),
            'redirectToOnboarding' => $account !== null
                && $user->isAccountOwner()
                && $account->onboarding_completed_at === null
                && $account->onboarding_dismissed_at === null,
            'persona' => $user->persona?->value,
            'conversion' => $conversion,
            'conversionResolved' => $conversionResolved,
        ]);
    }

    public function index(Request $request): Response|RedirectResponse
    {
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
        }

        $account = $request->user()->account;

        abort_unless($request->user()->isAccountOwner(), SymfonyResponse::HTTP_FORBIDDEN);

        $subscription = $account->subscription(Account::SUBSCRIPTION_NAME);

        return Inertia::render('settings/account/Billing', [
            'hasSubscription' => $account->subscribed(Account::SUBSCRIPTION_NAME),
            'onTrial' => $account->isOnTrial(),
            'trialEndsAt' => $account->activeTrialEndsAt(),
            'subscription' => $subscription?->only([
                'stripe_status',
                'ends_at',
            ]),
            'plan' => $account->plan,
            'workspaceCount' => $account->workspaces()->count(),
            'invoices' => $account->invoices()->map(fn ($invoice) => [
                'id' => $invoice->id,
                'date' => $invoice->date(),
                'total' => $invoice->total(),
                'status' => $invoice->status,
                'invoice_pdf' => $invoice->invoice_pdf,
            ]),
            'defaultPaymentMethod' => $account->displayablePaymentMethod(),
        ]);
    }

    public function swapToYearly(Request $request): RedirectResponse
    {
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
        }

        $account = $request->user()->account;

        abort_unless($request->user()->isAccountOwner(), SymfonyResponse::HTTP_FORBIDDEN);
        abort_unless($account->subscribed(Account::SUBSCRIPTION_NAME), SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY, 'No active subscription');

        $plan = $account->plan;
        $yearlyPriceId = $plan?->stripe_yearly_price_id;

        abort_if($yearlyPriceId === null, SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY, 'No annual price configured');

        $subscription = $account->subscription(Account::SUBSCRIPTION_NAME);

        abort_if($subscription === null, SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY, 'No active subscription');

        if ($subscription->stripe_price === $yearlyPriceId) {
            return redirect()->route('app.billing.index');
        }

        $authorization = Gate::inspect('swapPlan', [$account]);

        if ($authorization->denied()) {
            return back()->with('flash.error', $authorization->message());
        }

        $subscription->swap($yearlyPriceId);

        return redirect()->route('app.billing.index')
            ->with('flash.success', __('billing.flash.switched_to_yearly'));
    }

    public function portal(Request $request): RedirectResponse
    {
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
        }

        $account = $request->user()->account;

        abort_unless($request->user()->isAccountOwner(), SymfonyResponse::HTTP_FORBIDDEN);

        return $account->redirectToBillingPortal(
            route('app.billing.index')
        );
    }
}
