<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\OnboardingStatusUpdated;
use App\Models\AccessToken;
use App\Models\User;

class AccessTokenObserver
{
    /**
     * OAuth MCP grants unlock the MCP onboarding step. Broadcast to the user's
     * current workspace UI so the checklist can refresh.
     */
    public function created(AccessToken $accessToken): void
    {
        if ($accessToken->revoked) {
            return;
        }

        $this->broadcastIfMcpOAuth($accessToken);
    }

    public function updated(AccessToken $accessToken): void
    {
        if (! $accessToken->wasChanged('revoked')) {
            return;
        }

        $this->broadcastIfMcpOAuth($accessToken);
    }

    private function broadcastIfMcpOAuth(AccessToken $accessToken): void
    {
        if (! $accessToken->isMcpOAuthClient()) {
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
