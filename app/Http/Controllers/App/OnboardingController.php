<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Onboarding\ResolveOnboardingStatus;
use App\Enums\PostHog\OnboardingEvent;
use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Http\Requests\App\Onboarding\SkipOnboardingStepRequest;
use App\Http\Resources\App\SocialAccountResource;
use App\Services\PostHogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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
        // Capture before syncProgress — same-request auto-stamp still shows the ready state.
        $wasAlreadyComplete = $user->account?->onboarding_completed_at !== null;
        $status = $this->resolveOnboardingStatus->syncProgress($user);

        // Legacy dismiss (deploy backfill) is terminal, including Echo partial reloads.
        if ($status['dismissed_at'] !== null) {
            return redirect()->route('app.calendar');
        }

        // Completed revisits leave — keep the ready state for Echo/poll partials and
        // for the immediate full reload after OAuth stamps completion.
        // The just-completed flag is session put (not flash) so partials never
        // age it out; only full visits pull/consume it.
        $isPartial = $request->hasHeader('X-Inertia-Partial-Component');
        $justCompleted = ! $isPartial
            && (bool) $request->session()->pull(ResolveOnboardingStatus::JUST_COMPLETED_SESSION_KEY);

        if ($wasAlreadyComplete && ! $justCompleted && ! $isPartial) {
            return redirect()->route('app.calendar');
        }

        // Once-per-account, and only while still incomplete after syncProgress —
        // auto-stamp on this request must not also fire viewed.
        if (
            ! $isPartial
            && $status['completed_at'] === null
            && $status['dismissed_at'] === null
            && $user->isAccountOwner()
            && $user->account !== null
        ) {
            $this->postHog->capture(
                $user->id,
                OnboardingEvent::Viewed->value,
                account: $user->account,
                dedupeKey: "onboarding:viewed:{$user->account->id}",
            );
        }

        $accounts = SocialAccountResource::collection(
            $workspace->socialAccounts()->orderBy('id')->get(),
        )->resolve();

        return Inertia::render('onboarding/Index', [
            'status' => $status,
            'canSkipSteps' => $user->isAccountOwner(),
            'canManageAccounts' => $user->can('manageAccounts', $workspace),
            'canCreatePost' => $user->can('createPost', $workspace),
            'mcpUrl' => route('mcp.trypost'),
            'samplePrompt' => __('onboarding.first_post.sample_prompt'),
            'platforms' => SocialPlatform::connectableOptions(),
            'accounts' => $accounts,
        ]);
    }

    public function skipStep(SkipOnboardingStepRequest $request, string $step): RedirectResponse
    {
        if ($redirect = $this->redirectIfSelfHosted()) {
            return $redirect;
        }

        $this->resolveOnboardingStatus->skipStep($request->user(), $step);

        return back();
    }

    public function complete(Request $request): RedirectResponse
    {
        if ($redirect = $this->redirectIfSelfHosted()) {
            return $redirect;
        }

        $user = $request->user();
        $account = $user->account;

        // The explicit Continue leaves for good — kill any stale just-completed
        // flag, whichever path stamped completion (observer, syncProgress, or
        // the markCompleted below).
        $request->session()->forget(ResolveOnboardingStatus::JUST_COMPLETED_SESSION_KEY);

        // Already stamped (e.g. observer / syncProgress auto-complete) — just leave.
        if ($account?->onboarding_completed_at !== null) {
            return redirect()->route('app.calendar');
        }

        // Legacy dismiss stays terminal: never let Continue stamp after backfill.
        if ($account?->onboarding_dismissed_at !== null) {
            return redirect()->route('app.calendar');
        }

        // Any teammate who finishes activation may stamp — observers/syncProgress
        // already do the same. Steps are account-scoped.
        $status = $this->resolveOnboardingStatus->handle($user);

        if (! $status['all_complete']) {
            return redirect()->route('app.onboarding');
        }

        // markCompleted broadcasts account-wide so residual banners clear immediately.
        $this->resolveOnboardingStatus->markCompleted($user);

        // The explicit Continue already showed the ready state — the just-completed
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
