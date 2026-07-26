<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\OnboardingStatusUpdated;
use App\Models\AccessToken;
use App\Models\User;

class AccessTokenObserver
{
    /**
     * Personal tokens (no workspace_id) unlock the MCP onboarding step.
     * Broadcast to the user's current workspace UI so the checklist can refresh.
     */
    public function created(AccessToken $accessToken): void
    {
        if ($accessToken->workspace_id !== null || $accessToken->revoked) {
            return;
        }

        $workspaceId = User::query()
            ->whereKey($accessToken->user_id)
            ->value('current_workspace_id');

        if (blank($workspaceId)) {
            return;
        }

        OnboardingStatusUpdated::dispatchForWorkspace($workspaceId);
    }
}
