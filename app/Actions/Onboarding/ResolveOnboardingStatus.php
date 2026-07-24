<?php

declare(strict_types=1);

namespace App\Actions\Onboarding;

use App\Models\AccessToken;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;

class ResolveOnboardingStatus
{
    /**
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
        /** @var Account|null $account */
        $account = data_get($user, 'account');

        /** @var Workspace|null $workspace */
        $workspace = data_get($user, 'currentWorkspace');

        $mcpConnected = AccessToken::query()
            ->where('user_id', $user->id)
            ->whereNull('workspace_id')
            ->where('revoked', false)
            ->exists();
        $socialConnected = $workspace?->socialAccounts()->exists() ?? false;
        $firstPostCreated = $workspace?->posts()->exists() ?? false;
        $allComplete = $mcpConnected && $socialConnected && $firstPostCreated;

        if ($allComplete && $account?->onboarding_completed_at === null) {
            $account->update(['onboarding_completed_at' => now()]);
        }

        $showResidual = ! config('trypost.self_hosted')
            && ($account?->subscribed(Account::SUBSCRIPTION_NAME) ?? false)
            && $account->onboarding_completed_at === null
            && $account->onboarding_dismissed_at === null;

        return [
            'mcp_connected' => $mcpConnected,
            'social_connected' => $socialConnected,
            'first_post_created' => $firstPostCreated,
            'all_complete' => $allComplete,
            'show_residual' => $showResidual,
            'completed_at' => $account?->onboarding_completed_at?->toIso8601String(),
            'dismissed_at' => $account?->onboarding_dismissed_at?->toIso8601String(),
        ];
    }
}
