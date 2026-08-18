<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\AccessToken\ListConnectedMcpClients;
use App\Actions\Billing\StartSubscriptionCheckout;
use App\Actions\Welcome\FetchLatestSocialPost;
use App\Enums\Plan\Slug;
use App\Enums\PostHog\CheckoutEvent;
use App\Enums\PostHog\WelcomeEvent;
use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Enums\User\Goal;
use App\Enums\User\Persona;
use App\Enums\User\PublishMethod;
use App\Enums\User\ReferralSource;
use App\Http\Requests\App\Welcome\StoreWelcomeConnectRequest;
use App\Http\Requests\App\Welcome\StoreWelcomeGoalsRequest;
use App\Http\Requests\App\Welcome\StoreWelcomePersonaRequest;
use App\Http\Requests\App\Welcome\StoreWelcomePublishMethodRequest;
use App\Http\Requests\App\Welcome\StoreWelcomeReferralSourceRequest;
use App\Http\Resources\App\SocialAccountResource;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostHogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class WelcomeController extends Controller
{
    public function show(Request $request, FetchLatestSocialPost $fetchLatest): InertiaResponse|RedirectResponse
    {
        if ($redirect = $this->redirectIfUnavailable($request)) {
            return $redirect;
        }

        $user = $request->user();
        $step = $this->currentStep($user);

        $props = $this->chatState($user);

        if ($step === 'connect') {
            $props = [...$props, ...$this->connectState($user, $fetchLatest, deferLatestPost: true)];
        }

        return Inertia::render('welcome/Chat', $props);
    }

    public function storePersona(StoreWelcomePersonaRequest $request, PostHogService $postHog): RedirectResponse|JsonResponse
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
            WelcomeEvent::Persona->value,
            ['persona' => $persona],
            $user->account,
        );

        return $this->advance($request, $user->fresh());
    }

    public function storeGoals(StoreWelcomeGoalsRequest $request, PostHogService $postHog): RedirectResponse|JsonResponse
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
            WelcomeEvent::Goals->value,
            ['goals' => $goals],
            $user->account,
        );

        return $this->advance($request, $user->fresh());
    }

    public function storeReferralSource(
        StoreWelcomeReferralSourceRequest $request,
        FetchLatestSocialPost $fetchLatest,
        PostHogService $postHog,
    ): RedirectResponse|JsonResponse {
        if ($redirect = $this->redirectIfStepIncomplete($request, requireGoals: true)) {
            return $redirect;
        }

        $user = $request->user();
        $referralSource = (string) $request->validated('referral_source');

        $user->update(['referral_source' => $referralSource]);

        $postHog->identify($user->id, [
            'referral_source' => $referralSource,
        ]);
        $postHog->capture(
            $user->id,
            WelcomeEvent::Referral->value,
            ['referral_source' => $referralSource],
            $user->account,
        );

        return $this->advance($request, $user->fresh(), $fetchLatest);
    }

    public function storePublishMethod(
        StoreWelcomePublishMethodRequest $request,
        FetchLatestSocialPost $fetchLatest,
        PostHogService $postHog,
    ): RedirectResponse|JsonResponse {
        if ($redirect = $this->redirectIfStepIncomplete($request, requireGoals: true, requireReferral: true)) {
            return $redirect;
        }

        $user = $request->user();
        $workspace = $user->currentWorkspace;

        if (
            $workspace === null
            || $workspace->socialAccounts()->where('status', Status::Connected)->doesntExist()
        ) {
            return $this->advance($request, $user, $fetchLatest);
        }

        $publishMethod = (string) $request->validated('publish_method');

        $user->update(['publish_method' => $publishMethod]);

        $postHog->identify($user->id, [
            'publish_method' => $publishMethod,
        ]);
        $postHog->capture(
            $user->id,
            WelcomeEvent::PublishMethod->value,
            ['publish_method' => $publishMethod],
            $user->account,
        );

        return $this->advance($request, $user->fresh(), $fetchLatest);
    }

    public function storeConnect(
        StoreWelcomeConnectRequest $request,
        StartSubscriptionCheckout $checkout,
        PostHogService $postHog,
    ): Response|RedirectResponse {
        if ($redirect = $this->redirectIfStepIncomplete($request, requireGoals: true, requireReferral: true)) {
            return $redirect;
        }

        abort_unless($request->user()->currentWorkspace !== null, Response::HTTP_NOT_FOUND);

        $user = $request->user();
        $platforms = $request->connectedPlatforms();

        $plan = Plan::where('slug', Slug::Workspace)->firstOrFail();
        $priceId = $plan->stripe_monthly_price_id;

        abort_if($priceId === null, Response::HTTP_INTERNAL_SERVER_ERROR, 'Monthly price is not configured.');

        $response = $checkout->redirect(
            $user->account,
            $priceId,
            route('app.welcome'),
        );

        try {
            $postHog->capture(
                $user->id,
                WelcomeEvent::Connect->value,
                ['platforms' => $platforms],
                $user->account,
            );
            $postHog->capture(
                $user->id,
                CheckoutEvent::Started->value,
                ['plan_name' => $plan->name, 'interval' => 'monthly'],
                $user->account,
            );
        } catch (Throwable $e) {
            report($e);
        }

        return $response;
    }

    public function subscriptionRequired(Request $request): InertiaResponse|RedirectResponse
    {
        $user = $request->user();

        if ($user->account?->hasAppAccess()) {
            return redirect()->route('app.calendar');
        }

        if ($user->isAccountOwner()) {
            return redirect()->route('app.welcome');
        }

        return Inertia::render('welcome/SubscriptionRequired', [
            'ownerName' => $user->account?->owner?->name,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function chatState(User $user): array
    {
        $step = $this->currentStep($user);

        return [
            'step' => $step,
            'history' => $this->chatHistory($user, $step),
            'personas' => array_map(fn (Persona $persona): string => $persona->value, Persona::cases()),
            'selectedPersona' => $user->persona?->value,
            'goals' => array_map(fn (Goal $goal): string => $goal->value, Goal::cases()),
            'selectedGoals' => $user->goals ?? [],
            'sources' => array_map(fn (ReferralSource $source): string => $source->value, ReferralSource::cases()),
            'selectedReferral' => $user->referral_source?->value,
            'publishMethods' => array_map(fn (PublishMethod $method): string => $method->value, PublishMethod::cases()),
            'selectedPublishMethod' => $user->publish_method?->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function connectState(User $user, FetchLatestSocialPost $fetchLatest, bool $deferLatestPost = false): array
    {
        $workspace = $user->currentWorkspace;

        abort_unless($workspace !== null, Response::HTTP_NOT_FOUND);

        $accounts = $workspace->socialAccounts()->orderBy('id')->get();
        $connected = $accounts->first(
            fn (SocialAccount $account): bool => $account->status === Status::Connected
                && $account->platform->supportsImpressionAnalytics(),
        );

        $latestPost = null;

        if ($connected !== null) {
            $latestPost = $deferLatestPost
                ? Inertia::defer(fn (): ?array => $fetchLatest->handle($connected))
                : $fetchLatest->handle($connected);
        }

        return [
            'platforms' => array_map(
                function (array $option): array {
                    $platform = SocialPlatform::tryFrom((string) data_get($option, 'value'));

                    if ($platform !== null) {
                        $option['label'] = $platform->welcomeLabel();
                    }

                    return $option;
                },
                SocialPlatform::connectableOptions(),
            ),
            'accounts' => SocialAccountResource::collection($accounts)->resolve(),
            'latestPostNetwork' => $connected?->platform->network(),
            'latestPost' => $latestPost,
            'mcpUrl' => route('mcp.trypost'),
            'connectedClients' => ListConnectedMcpClients::forUser($user, $workspace),
        ];
    }

    private function advance(Request $request, User $user, ?FetchLatestSocialPost $fetchLatest = null): RedirectResponse|JsonResponse
    {
        if (! $request->expectsJson()) {
            return redirect()->route('app.welcome');
        }

        $state = $this->chatState($user);

        if (data_get($state, 'step') === 'connect') {
            abort_unless($fetchLatest instanceof FetchLatestSocialPost, Response::HTTP_INTERNAL_SERVER_ERROR);

            $state = [...$state, ...$this->connectState($user, $fetchLatest)];
        }

        return response()->json($state);
    }

    private function currentStep(User $user): string
    {
        if (! $user->persona) {
            return 'persona';
        }

        if (! Goal::containsCurrent($user->goals)) {
            return 'goals';
        }

        if (! $user->referral_source) {
            return 'referral';
        }

        return 'connect';
    }

    private function redirectIfStepIncomplete(
        Request $request,
        bool $requireGoals = false,
        bool $requireReferral = false,
    ): ?RedirectResponse {
        if ($redirect = $this->redirectIfUnavailable($request)) {
            return $redirect;
        }

        $user = $request->user();

        if (! $user->persona) {
            return redirect()->route('app.welcome');
        }

        if ($requireGoals && ! Goal::containsCurrent($user->goals)) {
            return redirect()->route('app.welcome');
        }

        if ($requireReferral && ! $user->referral_source) {
            return redirect()->route('app.welcome');
        }

        return null;
    }

    /**
     * Answered welcome turns before the current step, reconstructed from
     * stored user fields so a reload still looks like a chat thread.
     *
     * @return list<array{step: 'persona'|'goals'|'referral', values: list<string>}>
     */
    private function chatHistory(User $user, string $currentStep): array
    {
        $history = [];

        if ($currentStep !== 'persona' && $user->persona) {
            $history[] = [
                'step' => 'persona',
                'values' => [$user->persona->value],
            ];
        }

        if (in_array($currentStep, ['referral', 'connect'], true) && Goal::containsCurrent($user->goals)) {
            $allowed = array_map(fn (Goal $goal): string => $goal->value, Goal::cases());

            $history[] = [
                'step' => 'goals',
                'values' => array_values(array_intersect($user->goals ?? [], $allowed)),
            ];
        }

        if ($currentStep === 'connect' && $user->referral_source) {
            $history[] = [
                'step' => 'referral',
                'values' => [$user->referral_source->value],
            ];
        }

        return $history;
    }

    private function redirectIfUnavailable(Request $request): ?RedirectResponse
    {
        $user = $request->user();

        // Match EnsureAccountReady — generic-trial (no-card) users already have
        // app access and must not be sent through Stripe checkout again.
        // Self-hosted always has app access, so welcome/checkout is skipped too.
        if ($user->account?->hasAppAccess()) {
            return redirect()->route('app.calendar');
        }

        // Members can't check out — hold them on a dedicated screen instead of
        // walking an ICP flow they can never finish.
        if (! $user->isAccountOwner()) {
            return redirect()->route('app.welcome.subscription-required');
        }

        return null;
    }
}
