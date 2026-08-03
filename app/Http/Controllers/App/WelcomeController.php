<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Billing\StartSubscriptionCheckout;
use App\Actions\Onboarding\ResolveOnboardingStatus;
use App\Enums\Plan\Slug;
use App\Enums\PostHog\WelcomeEvent;
use App\Enums\User\Goal;
use App\Enums\User\Persona;
use App\Enums\User\ReferralSource;
use App\Http\Requests\App\Welcome\StoreWelcomeGoalsRequest;
use App\Http\Requests\App\Welcome\StoreWelcomePersonaRequest;
use App\Http\Requests\App\Welcome\StoreWelcomeReferralSourceRequest;
use App\Models\Plan;
use App\Services\PostHogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class WelcomeController extends Controller
{
    public function __construct(
        private readonly ResolveOnboardingStatus $resolveOnboardingStatus,
    ) {}

    public function persona(Request $request): Response|RedirectResponse
    {
        if ($redirect = $this->redirectIfUnavailable($request)) {
            return $redirect;
        }

        return Inertia::render('welcome/Persona', [
            'personas' => array_map(fn (Persona $persona): string => $persona->value, Persona::cases()),
            'selected' => $request->user()->persona?->value,
        ]);
    }

    public function storePersona(StoreWelcomePersonaRequest $request, PostHogService $postHog): RedirectResponse
    {
        if ($redirect = $this->redirectIfUnavailable($request)) {
            return $redirect;
        }

        $user = $request->user();
        $persona = (string) $request->validated('persona');

        $user->update(['persona' => $persona]);

        $postHog->identify($user->id, [
            'persona' => $persona,
        ]);
        $postHog->capture(
            $user->id,
            WelcomeEvent::PersonaSaved->value,
            ['persona' => $persona],
            $user->account,
        );

        return redirect()->route('app.welcome.goals');
    }

    public function goals(Request $request): Response|RedirectResponse
    {
        if ($redirect = $this->redirectIfStepIncomplete($request)) {
            return $redirect;
        }

        $user = $request->user();

        return Inertia::render('welcome/Goals', [
            'goals' => array_map(fn (Goal $goal): string => $goal->value, Goal::cases()),
            'selected' => $user->goals ?? [],
        ]);
    }

    public function storeGoals(StoreWelcomeGoalsRequest $request, PostHogService $postHog): RedirectResponse
    {
        if ($redirect = $this->redirectIfStepIncomplete($request)) {
            return $redirect;
        }

        $user = $request->user();
        $goals = array_values($request->validated('goals'));

        $user->update(['goals' => $goals]);

        $postHog->identify($user->id, [
            'goals' => $goals,
        ]);
        $postHog->capture(
            $user->id,
            WelcomeEvent::GoalsSaved->value,
            ['goals' => $goals],
            $user->account,
        );

        return redirect()->route('app.welcome.referral-source');
    }

    public function referralSource(Request $request): Response|RedirectResponse
    {
        if ($redirect = $this->redirectIfStepIncomplete($request, requireGoals: true)) {
            return $redirect;
        }

        $user = $request->user();
        $plan = Plan::where('slug', Slug::Workspace)->firstOrFail();

        return Inertia::render('welcome/ReferralSource', [
            'sources' => array_map(fn (ReferralSource $source): string => $source->value, ReferralSource::cases()),
            'selected' => $user->referral_source?->value,
            'canCheckout' => $user->isAccountOwner(),
            'plan' => [
                'name' => $plan->name,
                'interval' => 'monthly',
            ],
        ]);
    }

    public function storeReferralSource(
        StoreWelcomeReferralSourceRequest $request,
        StartSubscriptionCheckout $checkout,
        PostHogService $postHog,
    ): SymfonyResponse|RedirectResponse {
        if ($redirect = $this->redirectIfStepIncomplete($request, requireGoals: true)) {
            return $redirect;
        }

        $user = $request->user();

        abort_unless($user->isAccountOwner(), SymfonyResponse::HTTP_FORBIDDEN);

        $referralSource = (string) $request->validated('referral_source');

        $user->update(['referral_source' => $referralSource]);

        $postHog->identify($user->id, [
            'referral_source' => $referralSource,
        ]);
        $postHog->capture(
            $user->id,
            WelcomeEvent::ReferralSaved->value,
            ['referral_source' => $referralSource],
            $user->account,
        );

        $plan = Plan::where('slug', Slug::Workspace)->firstOrFail();
        $priceId = $plan->stripe_monthly_price_id;

        abort_if($priceId === null, SymfonyResponse::HTTP_INTERNAL_SERVER_ERROR, 'Monthly price is not configured.');

        $response = $checkout->redirect(
            $user->account,
            $priceId,
            route('app.welcome.referral-source'),
        );

        $postHog->capture(
            $user->id,
            WelcomeEvent::CheckoutStarted->value,
            [
                'plan_name' => $plan->name,
                'interval' => 'monthly',
            ],
            $user->account,
        );

        return $response;
    }

    public function subscriptionRequired(Request $request): Response|RedirectResponse
    {
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
        }

        $user = $request->user();

        if ($user->account?->hasAppAccess()) {
            $status = $this->resolveOnboardingStatus->handle($user);

            return redirect()->route($status['show_residual'] ? 'app.onboarding' : 'app.calendar');
        }

        if ($user->isAccountOwner()) {
            return redirect()->route('app.welcome.persona');
        }

        return Inertia::render('welcome/SubscriptionRequired', [
            'ownerName' => $user->account?->owner?->name,
        ]);
    }

    private function redirectIfStepIncomplete(Request $request, bool $requireGoals = false): ?RedirectResponse
    {
        if ($redirect = $this->redirectIfUnavailable($request)) {
            return $redirect;
        }

        $user = $request->user();

        if (! $user->persona) {
            return redirect()->route('app.welcome.persona');
        }

        if ($requireGoals && ! $user->goals) {
            return redirect()->route('app.welcome.goals');
        }

        return null;
    }

    private function redirectIfUnavailable(Request $request): ?RedirectResponse
    {
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
        }

        $user = $request->user();

        // Match EnsureAccountReady — generic-trial (no-card) users already have
        // app access and must not be sent through Stripe checkout again.
        if ($user->account?->hasAppAccess()) {
            $status = $this->resolveOnboardingStatus->handle($user);

            return redirect()->route($status['show_residual'] ? 'app.onboarding' : 'app.calendar');
        }

        // Members can't check out — hold them on a dedicated screen instead of
        // walking an ICP flow they can never finish.
        if (! $user->isAccountOwner()) {
            return redirect()->route('app.welcome.subscription-required');
        }

        return null;
    }
}
