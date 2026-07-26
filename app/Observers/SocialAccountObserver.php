<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\SocialAccount\Platform;
use App\Events\OnboardingStatusUpdated;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Jobs\PostHog\SyncAccountUsage;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\PostHogService;
use Illuminate\Support\Facades\Auth;

class SocialAccountObserver
{
    /**
     * Enforce one connected account per social network per workspace. Variants
     * of the same network (LinkedIn profile/page, Instagram standalone/Facebook)
     * collapse via Platform::network(). Reconnecting an existing account goes
     * through updateOrCreate's update path and never reaches this hook. Bypassed
     * in self-hosted mode, which has no per-workspace limits.
     */
    public function creating(SocialAccount $socialAccount): void
    {
        if (config('trypost.self_hosted')) {
            return;
        }

        $platform = $socialAccount->platform;

        if (! $platform instanceof Platform) {
            return;
        }

        $conflict = SocialAccount::query()
            ->where('workspace_id', $socialAccount->workspace_id)
            ->whereIn('platform', $platform->networkPlatformValues())
            ->exists();

        if ($conflict) {
            throw new NetworkAlreadyConnectedException($platform);
        }
    }

    public function created(SocialAccount $socialAccount): void
    {
        $this->syncUsage($socialAccount);

        OnboardingStatusUpdated::dispatchForWorkspace(
            $socialAccount->workspace_id,
            $this->actorFor($socialAccount),
        );
    }

    public function deleted(SocialAccount $socialAccount): void
    {
        $this->syncUsage($socialAccount);

        OnboardingStatusUpdated::dispatchForWorkspace(
            $socialAccount->workspace_id,
            $this->actorFor($socialAccount),
        );
    }

    private function actorFor(SocialAccount $socialAccount): ?User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return null;
        }

        if ((string) $user->account_id !== (string) $socialAccount->workspace?->account_id) {
            return null;
        }

        return $user;
    }

    private function syncUsage(SocialAccount $socialAccount): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        SyncAccountUsage::dispatch(
            (string) $socialAccount->workspace->account_id,
            (string) $socialAccount->workspace_id,
        );
    }
}
