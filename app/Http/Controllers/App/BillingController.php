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

        // First-sight per account, atomically: `Cache::add` returns true only
        // for the first request with this session_id, so a back-button/refresh
        // can't re-fire `checkout.completed` and a foreign session_id can't
        // burn the real buyer's tracking.
        $fromCheckout = $account !== null
            && $account->stripe_id
            && is_string($sessionId)
            && $sessionId !== ''
            && Cache::add("checkout_tracked:{$account->id}:{$sessionId}", true, now()->addDay());

        // The purchase pixel only fires with a verified conversion (see
        // Processing.vue), so the session must belong to this account's Stripe
        // customer. A transient Stripe failure loses the pixel rather than
        // firing it unverified.
        $conversion = null;

        if ($fromCheckout) {
            try {
                $session = $account->stripe()->checkout->sessions->retrieve($sessionId);

                $conversion = CheckoutConversionData::fromSession($session, (string) $account->stripe_id);
            } catch (Throwable) {
                // Verified-or-nothing: no conversion, no purchase event.
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
