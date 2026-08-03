<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Onboarding\ResolveOnboardingStatus;
use App\Enums\PostHog\OnboardingEvent;
use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Http\Resources\App\SocialAccountResource;
use App\Services\PostHogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class OnboardingController extends Controller
{
    public function __construct(
        private readonly ResolveOnboardingStatus $resolveOnboardingStatus,
        private readonly PostHogService $postHog,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        if ($redirect = $this->redirectIfSelfHosted()) {
            return $redirect;
        }

        $user = $request->user();
        $workspace = $user->currentWorkspace;
        // Capture before syncProgress — same-request auto-stamp still shows celebration.
        $wasAlreadyComplete = $user->account?->onboarding_completed_at !== null;
        $status = $this->resolveOnboardingStatus->syncProgress($user);

        // Skip is always terminal (including Echo partial reloads).
        if ($status['dismissed_at'] !== null) {
            return redirect()->route('app.calendar');
        }

        // Completed revisits leave — keep celebration for Echo/poll partials and
        // for the immediate full reload after OAuth stamps completion.
        // The just-completed flag is session put (not flash) so partials never
        // age it out; only full visits pull/consume it.
        $isPartial = $request->hasHeader('X-Inertia-Partial-Component');
        $justCompleted = ! $isPartial
            && (bool) $request->session()->pull(ResolveOnboardingStatus::JUST_COMPLETED_SESSION_KEY);

        if ($wasAlreadyComplete && ! $justCompleted && ! $isPartial) {
            return redirect()->route('app.calendar');
        }

        if (! $isPartial && ! $wasAlreadyComplete) {
            $this->postHog->capture(
                $user->id,
                OnboardingEvent::Viewed->value,
                account: $user->account,
            );
        }

        $accounts = SocialAccountResource::collection(
            $workspace->socialAccounts()->orderBy('id')->get(),
        )->resolve();

        return Inertia::render('onboarding/Index', [
            'status' => $status,
            'canSkipSteps' => $user->isAccountOwner(),
            'mcpUrl' => route('mcp.trypost'),
            'samplePrompt' => __('onboarding.first_post.sample_prompt'),
            'platforms' => SocialPlatform::connectableOptions(),
            'accounts' => $accounts,
            // The step is account-scoped but the grid is workspace-local — tell
            // the user when the check comes from a sibling workspace.
            'socialConnectedElsewhere' => $status['social_connected'] && $accounts === [],
        ]);
    }

    public function skipStep(Request $request, string $step): RedirectResponse
    {
        if ($redirect = $this->redirectIfSelfHosted()) {
            return $redirect;
        }

        $user = $request->user();

        abort_unless($user->isAccountOwner(), SymfonyResponse::HTTP_FORBIDDEN);
        abort_unless(
            in_array($step, ResolveOnboardingStatus::SKIPPABLE_STEPS, true),
            SymfonyResponse::HTTP_NOT_FOUND,
        );

        $this->resolveOnboardingStatus->skipStep($user, $step);

        return back();
    }

    public function complete(Request $request): RedirectResponse
    {
        if ($redirect = $this->redirectIfSelfHosted()) {
            return $redirect;
        }

        $user = $request->user();
        $account = $user->account;

        // The explicit Continue leaves for good — kill any stale celebration
        // flag, whichever path stamped completion (observer, syncProgress, or
        // the markCompleted below).
        $request->session()->forget(ResolveOnboardingStatus::JUST_COMPLETED_SESSION_KEY);

        // Already stamped (e.g. observer / syncProgress auto-complete) — just leave.
        if ($account?->onboarding_completed_at !== null) {
            return redirect()->route('app.calendar');
        }

        // Skip must stay terminal: never let Continue re-open completion after dismiss.
        if ($account?->onboarding_dismissed_at !== null) {
            return redirect()->route('app.calendar');
        }

        // Any teammate who finishes activation may stamp — observers/syncProgress
        // already do the same. Dismiss remains owner-only. Steps are account-scoped.
        $status = $this->resolveOnboardingStatus->handle($user);

        if (! $status['all_complete']) {
            return redirect()->route('app.onboarding');
        }

        // markCompleted broadcasts account-wide so residual banners clear immediately.
        $this->resolveOnboardingStatus->markCompleted($user);

        // The explicit Continue already showed the ready state — the celebration
        // flag must not linger and resurface on a later visit.
        $request->session()->forget(ResolveOnboardingStatus::JUST_COMPLETED_SESSION_KEY);

        return redirect()->route('app.calendar');
    }

    private function redirectIfSelfHosted(): ?RedirectResponse
    {
        if (! config('trypost.self_hosted')) {
            return null;
        }

        return redirect()->route('app.calendar');
    }
}
