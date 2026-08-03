<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\PostHog\OnboardingEvent;
use App\Events\OnboardingStatusUpdated;
use App\Models\AccessToken;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PostHogService;

class ResolveOnboardingStatus
{
    public const TOTAL_STEPS = 3;

    /**
     * Checklist step identifiers mapped to their status keys. Steps are derived
     * from real state; optional steps may additionally be skipped.
     */
    public const STEPS = [
        'mcp' => 'mcp_connected',
        'social' => 'social_connected',
        'first_post' => 'first_post_created',
    ];

    /** Steps the owner may skip — required steps must be completed for real. */
    public const SKIPPABLE_STEPS = ['mcp'];

    /**
     * Session key set when onboarding is stamped so the next full visit can
     * show the ready state instead of redirecting to the calendar. Uses put
     * (not flash) so Echo/poll partials do not age it out before a post-OAuth
     * full reload.
     */
    public const JUST_COMPLETED_SESSION_KEY = 'onboarding_just_completed';

    public function __construct(
        private readonly PostHogService $postHog,
    ) {}

    /**
     * Pure read of activation checklist state. Safe for Inertia shared props.
     *
     * @return array{
     *     mcp_connected: bool,
     *     social_connected: bool,
     *     first_post_created: bool,
     *     skipped_steps: list<string>,
     *     all_complete: bool,
     *     show_residual: bool,
     *     completed_at: ?string,
     *     dismissed_at: ?string
     * }
     */
    public function handle(User $user): array
    {
        $account = $user->account;
        $skippedSteps = $account?->onboarding_skipped_steps ?? [];

        if ($account?->onboarding_completed_at !== null) {
            $mcpConnected = ! in_array('mcp', $skippedSteps, true)
                || $this->accountHasMcpConnection($account);
            $effectiveSkippedSteps = $mcpConnected
                ? array_values(array_diff($skippedSteps, ['mcp']))
                : $skippedSteps;

            return [
                'mcp_connected' => $mcpConnected,
                'social_connected' => true,
                'first_post_created' => true,
                'skipped_steps' => $effectiveSkippedSteps,
                'all_complete' => true,
                'show_residual' => false,
                'completed_at' => $account->onboarding_completed_at->toIso8601String(),
                'dismissed_at' => $account->onboarding_dismissed_at?->toIso8601String(),
            ];
        }

        // All three steps are account-scoped so checklist checkmarks, residual
        // progress, and cross-workspace completion stay aligned.
        $stepStates = [
            'mcp' => $this->accountHasMcpConnection($account),
            'social' => $this->accountHasSocialConnection($account),
            'first_post' => $this->accountHasPost($account),
        ];

        // A skipped optional step counts as done for completion purposes.
        $allComplete = collect($stepStates)
            ->every(fn (bool $done, string $step): bool => $done || in_array($step, $skippedSteps, true));

        $showResidual = ! config('trypost.self_hosted')
            && $user->isAccountOwner()
            && ($account?->hasAppAccess() ?? false)
            && $account->onboarding_dismissed_at === null
            && ! $allComplete;

        return [
            'mcp_connected' => $stepStates['mcp'],
            'social_connected' => $stepStates['social'],
            'first_post_created' => $stepStates['first_post'],
            'skipped_steps' => $skippedSteps,
            'all_complete' => $allComplete,
            'show_residual' => $showResidual,
            'completed_at' => null,
            'dismissed_at' => $account?->onboarding_dismissed_at?->toIso8601String(),
        ];
    }

    /**
     * Read checklist state, capture step analytics, and stamp completion when done.
     * Call from intentional onboarding surfaces — not from shared Inertia props.
     *
     * @return array{
     *     mcp_connected: bool,
     *     social_connected: bool,
     *     first_post_created: bool,
     *     skipped_steps: list<string>,
     *     all_complete: bool,
     *     show_residual: bool,
     *     completed_at: ?string,
     *     dismissed_at: ?string
     * }
     */
    public function syncProgress(User $user): array
    {
        $status = $this->handle($user);
        $account = $user->account;

        if ($account === null || $account->onboarding_completed_at !== null) {
            return $status;
        }

        if ($account->onboarding_dismissed_at === null) {
            foreach (self::STEPS as $step => $statusKey) {
                $this->captureCompletedStep($user, $account, $step, $status[$statusKey]);
            }
        }

        if ($account->onboarding_dismissed_at !== null) {
            return $status;
        }

        if ($status['all_complete']) {
            $this->markCompleted($user);
            $account->refresh();

            return [
                ...$status,
                'show_residual' => false,
                'completed_at' => $account->onboarding_completed_at?->toIso8601String(),
            ];
        }

        return $status;
    }

    /**
     * Stamp account onboarding completion once and capture the funnel event.
     */
    public function markCompleted(User $user): bool
    {
        $account = $user->account;

        if (
            $account === null
            || $account->hasFinishedOnboarding()
        ) {
            return false;
        }

        $completedAt = now();

        $updated = Account::query()
            ->whereKey($account->id)
            ->whereNull('onboarding_completed_at')
            ->whereNull('onboarding_dismissed_at')
            ->update([
                'onboarding_completed_at' => $completedAt,
                'updated_at' => $completedAt,
            ]);

        if ($updated === 0) {
            return false;
        }

        $account->forceFill([
            'onboarding_completed_at' => $completedAt,
            'updated_at' => $completedAt,
        ]);
        $account->syncOriginalAttribute('onboarding_completed_at');
        $account->syncOriginalAttribute('updated_at');

        if (PostHogService::isEnabled()) {
            $this->postHog->capture(
                $user->id,
                OnboardingEvent::Completed->value,
                account: $account,
            );
        }

        // Every stamp path (endpoint, syncProgress, observers) must clear residual
        // banners account-wide — not only the explicit complete() action.
        OnboardingStatusUpdated::broadcastForAccount($account);

        // Lets the next full Inertia visit (e.g. OAuth popup → router.reload)
        // show the ready state instead of bouncing straight to calendar.
        if (request()->hasSession()) {
            request()->session()->put(self::JUST_COMPLETED_SESSION_KEY, true);
        }

        return true;
    }

    /**
     * Mark an optional step as skipped, then stamp completion when that was
     * the last open step. Returns false when the step cannot be skipped
     * (unknown, required, already done, already skipped, or onboarding over).
     */
    public function skipStep(User $user, string $step): bool
    {
        $account = $user->account;

        if ($account === null
            || ! in_array($step, self::SKIPPABLE_STEPS, true)
            || $account->hasFinishedOnboarding()
        ) {
            return false;
        }

        $status = $this->handle($user);
        $statusKey = self::STEPS[$step];

        if ($status[$statusKey] || in_array($step, $status['skipped_steps'], true)) {
            return false;
        }

        $skippedSteps = [...$status['skipped_steps'], $step];

        $updated = Account::query()
            ->whereKey($account->id)
            ->whereNull('onboarding_completed_at')
            ->whereNull('onboarding_dismissed_at')
            ->update([
                'onboarding_skipped_steps' => $skippedSteps,
                'updated_at' => now(),
            ]);

        if ($updated === 0) {
            return false;
        }

        $account->forceFill(['onboarding_skipped_steps' => $skippedSteps]);
        $account->syncOriginalAttribute('onboarding_skipped_steps');

        if (PostHogService::isEnabled()) {
            $this->postHog->capture(
                $user->id,
                OnboardingEvent::StepSkipped->value,
                ['step' => $step],
                $account,
            );
        }

        // markCompleted already broadcasts account-wide when it stamps.
        $completed = $this->handle($user)['all_complete'] && $this->markCompleted($user);

        if (! $completed) {
            OnboardingStatusUpdated::broadcastForAccount($account);
        }

        return true;
    }

    /**
     * Sidebar residual banner payload, or false when the banner should not show.
     *
     * Pure read — safe for Inertia shared props and prefetch. Cross-workspace
     * completion is stamped by syncProgress / observers, not here.
     *
     * @return array{completed: int, total: int}|false
     */
    public function residual(User $user): array|false
    {
        $account = $user->account;

        // Cheap gates first: this runs on every full Inertia load, so dismissed /
        // completed accounts, members, and self-hosted must never pay for the
        // step EXISTS queries in handle() — or even for a subscription lookup.
        if (config('trypost.self_hosted')
            || $account === null
            || $account->hasFinishedOnboarding()
            || ! $user->isAccountOwner()
            || ! $account->hasAppAccess()
        ) {
            return false;
        }

        $status = $this->handle($user);

        if (! $status['show_residual']) {
            return false;
        }

        return [
            'completed' => collect(self::STEPS)
                ->filter(fn (string $statusKey, string $step): bool => $status[$statusKey]
                    || in_array($step, $status['skipped_steps'], true))
                ->count(),
            'total' => self::TOTAL_STEPS,
        ];
    }

    private function accountHasMcpConnection(?Account $account): bool
    {
        if ($account === null) {
            return false;
        }

        return AccessToken::query()
            ->whereIn(
                'user_id',
                User::query()->select('id')->where('account_id', $account->id),
            )
            ->activeMcpOAuth()
            ->exists();
    }

    private function accountHasSocialConnection(?Account $account): bool
    {
        if ($account === null) {
            return false;
        }

        return Workspace::query()
            ->where('account_id', $account->id)
            ->whereHas('socialAccounts')
            ->exists();
    }

    private function accountHasPost(?Account $account): bool
    {
        if ($account === null) {
            return false;
        }

        return Workspace::query()
            ->where('account_id', $account->id)
            ->whereHas('posts')
            ->exists();
    }

    private function captureCompletedStep(User $user, Account $account, string $step, bool $completed): void
    {
        if (! $completed || ! PostHogService::isEnabled()) {
            return;
        }

        $this->postHog->capture(
            $user->id,
            OnboardingEvent::StepCompleted->value,
            ['step' => $step],
            $account,
            dedupeKey: "onboarding:step:{$account->id}:{$step}",
        );
    }
}
