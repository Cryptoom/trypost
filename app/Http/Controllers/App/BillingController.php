<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Onboarding\ResolveOnboardingStatus;
use App\Http\Requests\App\Billing\AcknowledgeCheckoutPurchaseRequest;
use App\Models\Account;
use App\Support\Billing\CheckoutPurchaseTracker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class BillingController extends Controller
{
    public function __construct(
        private readonly CheckoutPurchaseTracker $checkoutPurchaseTracker,
        private readonly ResolveOnboardingStatus $resolveOnboardingStatus,
    ) {}

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
        // proves the Checkout Session belongs to this account. Payload is
        // re-delivered until the client acknowledges it.
        $conversion = null;
        $conversionResolved = true;

        if (
            $account !== null
            && $user->isAccountOwner()
            && is_string($sessionId)
            && $sessionId !== ''
        ) {
            $resolved = $this->checkoutPurchaseTracker->resolve($account, $sessionId);
            $conversion = data_get($resolved, 'conversion');
            $conversionResolved = (bool) data_get($resolved, 'conversionResolved', true);
        }

        $subscriptionActive = $account !== null
            && $account->subscribed(Account::SUBSCRIPTION_NAME);
        $redirectToOnboarding = $account !== null
            && $user->isAccountOwner()
            && $account->onboarding_completed_at === null
            && $account->onboarding_dismissed_at === null;

        if ($subscriptionActive && $redirectToOnboarding) {
            $status = $this->resolveOnboardingStatus->syncProgress($user);
            $redirectToOnboarding = (bool) data_get($status, 'show_residual', true);
        }

        return Inertia::render('billing/Processing', [
            'subscriptionActive' => $subscriptionActive,
            'redirectToOnboarding' => $redirectToOnboarding,
            'persona' => $user->persona?->value,
            'conversion' => $conversion,
            'conversionResolved' => $conversionResolved,
        ]);
    }

    public function acknowledgePurchase(AcknowledgeCheckoutPurchaseRequest $request): SymfonyResponse
    {
        if (! config('trypost.self_hosted')) {
            $account = $request->user()->account;

            if ($account !== null) {
                $this->checkoutPurchaseTracker->acknowledge(
                    $account,
                    (string) data_get($request->validated(), 'session_id'),
                );
            }
        }

        return response()->noContent();
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
