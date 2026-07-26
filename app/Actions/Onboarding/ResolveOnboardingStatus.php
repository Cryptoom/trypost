<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Enums\PostHog\OnboardingEvent;
use App\Models\AccessToken;
use App\Models\Account;
use App\Models\User;
use App\Services\PostHogService;
use Illuminate\Support\Facades\Cache;

class ResolveOnboardingStatus
{
    public const TOTAL_STEPS = 3;

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
     *     all_complete: bool,
     *     show_residual: bool,
     *     completed_at: ?string,
     *     dismissed_at: ?string
     * }
     */
    public function handle(User $user): array
    {
        $account = $user->account;

        if ($account?->onboarding_completed_at !== null) {
            return [
                'mcp_connected' => true,
                'social_connected' => true,
                'first_post_created' => true,
                'all_complete' => true,
                'show_residual' => false,
                'completed_at' => $account->onboarding_completed_at->toIso8601String(),
                'dismissed_at' => $account->onboarding_dismissed_at?->toIso8601String(),
            ];
        }

        $workspace = $user->currentWorkspace;

        $mcpConnected = $account !== null
            && AccessToken::query()
                ->whereIn(
                    'user_id',
                    User::query()->select('id')->where('account_id', $account->id),
                )
                ->activeMcpOAuth()
                ->exists();
        $socialConnected = $workspace?->socialAccounts()->exists() ?? false;
        $firstPostCreated = $workspace?->posts()->exists() ?? false;
        $allComplete = $mcpConnected && $socialConnected && $firstPostCreated;

        $showResidual = ! config('trypost.self_hosted')
            && $user->isAccountOwner()
            && ($account?->hasAppAccess() ?? false)
            && $account->onboarding_dismissed_at === null
            && ! $allComplete;

        return [
            'mcp_connected' => $mcpConnected,
            'social_connected' => $socialConnected,
            'first_post_created' => $firstPostCreated,
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
            $this->captureCompletedStep($user, $account, 'mcp_connected', $status['mcp_connected']);
            $this->captureCompletedStep($user, $account, 'social_connected', $status['social_connected']);
            $this->captureCompletedStep($user, $account, 'first_post_created', $status['first_post_created']);
        }

        if (! $status['all_complete'] || $account->onboarding_dismissed_at !== null) {
            return $status;
        }

        $this->markCompleted($user);
        $account->refresh();

        return [
            ...$status,
            'show_residual' => false,
            'completed_at' => $account->onboarding_completed_at?->toIso8601String(),
        ];
    }

    /**
     * Stamp account onboarding completion once and capture the funnel event.
     */
    public function markCompleted(User $user): bool
    {
        $account = $user->account;

        if ($account === null || $account->onboarding_completed_at !== null) {
            return false;
        }

        $completedAt = now();

        $updated = Account::query()
            ->whereKey($account->id)
            ->whereNull('onboarding_completed_at')
            ->update(['onboarding_completed_at' => $completedAt]);

        if ($updated === 0) {
            return false;
        }

        $account->setAttribute('onboarding_completed_at', $completedAt);

        if (PostHogService::isEnabled()) {
            $this->postHog->capture(
                $user->id,
                OnboardingEvent::Completed->value,
                account: $account,
            );
        }

        return true;
    }

    /**
     * Sidebar residual banner payload, or false when the banner should not show.
     *
     * @return array{completed: int, total: int}|false
     */
    public function residual(User $user): array|false
    {
        $account = $user->account;

        if ($account === null
            || ! $user->isAccountOwner()
            || $account->onboarding_completed_at !== null
            || $account->onboarding_dismissed_at !== null
            || config('trypost.self_hosted')
            || ! $account->hasAppAccess()
        ) {
            return false;
        }

        $status = $this->handle($user);

        if (! $status['show_residual']) {
            return false;
        }

        return [
            'completed' => collect([
                $status['mcp_connected'],
                $status['social_connected'],
                $status['first_post_created'],
            ])->filter()->count(),
            'total' => self::TOTAL_STEPS,
        ];
    }

    private function captureCompletedStep(User $user, Account $account, string $step, bool $completed): void
    {
        if (! $completed || ! PostHogService::isEnabled()) {
            return;
        }

        // Durable once-per-account dedupe (no short TTL re-fire).
        if (! Cache::add("onboarding_step:{$account->id}:{$step}", true, now()->addYears(100))) {
            return;
        }

        $this->postHog->capture(
            $user->id,
            OnboardingEvent::StepCompleted->value,
            ['step' => $step],
            $account,
        );
    }
}
