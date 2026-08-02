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

        // Verify-then-flag: `fromCheckout` is true only when the session belongs
        // to this account's Stripe customer, so a foreign or invented session_id
        // can never fire the purchase tracking. The per-account cache key makes
        // the check once-only; invalid sessions are consumed (no Stripe hammering
        // from the poll loop) while transient failures stay unconsumed so the
        // next poll retries.
        $fromCheckout = false;
        $conversion = null;

        if ($account !== null
            && $account->stripe_id
            && is_string($sessionId)
            && $sessionId !== ''
            && ! Cache::has($cacheKey = "checkout_tracked:{$account->id}:{$sessionId}")
        ) {
            try {
                $session = $account->stripe()->checkout->sessions->retrieve($sessionId);

                $conversion = CheckoutConversionData::fromSession($session, (string) $account->stripe_id);
                $fromCheckout = $conversion !== null;

                Cache::put($cacheKey, true, now()->addDay());
            } catch (InvalidRequestException) {
                Cache::put($cacheKey, true, now()->addDay());
            } catch (Throwable) {
                // Transient — next poll retries the verification.
            }
        }

        return Inertia::render('billing/Processing', [
            'subscriptionActive' => $account && $account->subscribed(Account::SUBSCRIPTION_NAME),
            'fromCheckout' => $fromCheckout,
            'redirectToOnboarding' => $account !== null
                && $user->isAccountOwner()
                && $account->onboarding_completed_at === null
                && $account->onboarding_dismissed_at === null,
            'persona' => $user->persona?->value,
            'conversion' => $conversion,
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
