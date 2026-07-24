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

class OnboardingController extends Controller
{
    public function __construct(
        private readonly ResolveOnboardingStatus $resolveOnboardingStatus,
        private readonly PostHogService $postHog,
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
        }

        $user = $request->user();
        $workspace = $user->currentWorkspace;
        $status = $this->resolveOnboardingStatus->handle($user);

        $platforms = collect(SocialPlatform::cases())
            ->filter(fn (SocialPlatform $platform): bool => $platform->isConnectable())
            ->map(fn (SocialPlatform $platform): array => [
                'value' => $platform->value,
                'label' => $platform->label(),
                'color' => $platform->color(),
                'network' => $platform->network(),
            ])->values();

        $this->postHog->capture(
            $user->id,
            OnboardingEvent::Viewed->value,
            account: $user->account,
        );

        return Inertia::render('onboarding/Index', [
            'status' => $status,
            'mcpUrl' => url('/mcp/trypost'),
            'mcpClients' => [
                ['id' => 'claude', 'label' => 'Claude'],
                ['id' => 'chatgpt', 'label' => 'ChatGPT'],
            ],
            'samplePrompt' => __('onboarding.first_post.sample_prompt'),
            'platforms' => $platforms,
            'accounts' => SocialAccountResource::collection(
                $workspace->socialAccounts()->orderBy('id')->get(),
            )->resolve(),
            'createPostUrl' => route('app.posts.create'),
        ]);
    }

    public function dismiss(Request $request): RedirectResponse
    {
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
        }

        $user = $request->user();
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
        if (config('trypost.self_hosted')) {
            return redirect()->route('app.calendar');
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
}
