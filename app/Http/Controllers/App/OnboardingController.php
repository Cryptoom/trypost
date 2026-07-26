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
        $status = $this->resolveOnboardingStatus->syncProgress($user);

        if (! $request->hasHeader('X-Inertia-Partial-Component')) {
            $this->postHog->capture(
                $user->id,
                OnboardingEvent::Viewed->value,
                account: $user->account,
            );
        }

        return Inertia::render('onboarding/Index', [
            'status' => $status,
            'mcpUrl' => url('/mcp/trypost'),
            'mcpClients' => collect(config('trypost.mcp.clients', []))
                ->map(fn (array $client, string $id): array => [
                    'id' => $id,
                    'label' => (string) data_get($client, 'label'),
                    'logo' => (string) data_get($client, 'logo'),
                    'settings_url' => (string) data_get($client, 'settings_url'),
                ])
                ->values()
                ->all(),
            'samplePrompt' => __('onboarding.first_post.sample_prompt'),
            'platforms' => SocialPlatform::connectableOptions(),
            'accounts' => SocialAccountResource::collection(
                $workspace->socialAccounts()->orderBy('id')->get(),
            )->resolve(),
        ]);
    }

    public function dismiss(Request $request): RedirectResponse
    {
        if ($redirect = $this->redirectIfSelfHosted()) {
            return $redirect;
        }

        $user = $request->user();

        abort_unless($user->isAccountOwner(), SymfonyResponse::HTTP_FORBIDDEN);

        $user->account->update(['onboarding_dismissed_at' => now()]);

        $this->postHog->capture(
            $user->id,
            OnboardingEvent::Skipped->value,
            account: $user->account,
        );

        return redirect()->route('app.calendar');
    }

    public function complete(Request $request): RedirectResponse
    {
        if ($redirect = $this->redirectIfSelfHosted()) {
            return $redirect;
        }

        $user = $request->user();
        $status = $this->resolveOnboardingStatus->handle($user);

        if (! $status['all_complete']) {
            return redirect()->route('app.onboarding');
        }

        if ($user->account->onboarding_completed_at === null) {
            $user->account->update(['onboarding_completed_at' => now()]);
        }

        $this->postHog->capture(
            $user->id,
            OnboardingEvent::Completed->value,
            account: $user->account,
        );

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
