<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\OnboardingStatusUpdated;
use App\Models\AccessToken;
use App\Models\User;

class AccessTokenObserver
{
    /**
     * OAuth MCP grants unlock the MCP onboarding step for the whole account.
     * Broadcast to every workspace so teammates see the checklist update.
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

        $user = User::query()
            ->with(['account', 'currentWorkspace'])
            ->find($accessToken->user_id);

        OnboardingStatusUpdated::dispatchForAccount($user?->account, $user);
    }
}
