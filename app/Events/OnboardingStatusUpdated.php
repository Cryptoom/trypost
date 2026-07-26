<?php

declare(strict_types=1);

namespace App\Events;

use App\Actions\Onboarding\ResolveOnboardingStatus;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OnboardingStatusUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public string $workspaceId) {}

    /**
     * Broadcast to every workspace on the account and sync progress for the actor.
     * Use when a step is account-scoped (e.g. MCP OAuth).
     */
    public static function dispatchForAccount(?Account $account, ?User $actor = null): void
    {
        if ($account === null
            || $account->onboarding_completed_at !== null
            || $account->onboarding_dismissed_at !== null
        ) {
            return;
        }

        $workspaceIds = $account->workspaces()->pluck('id');

        foreach ($workspaceIds as $workspaceId) {
            static::dispatch((string) $workspaceId);
        }

        $resolver = app(ResolveOnboardingStatus::class);

        if ($actor !== null) {
            $actor->loadMissing(['account', 'currentWorkspace']);
            $resolver->syncProgress($actor);
            $account->refresh();
        }

        // Actor's current workspace may lack social/post while another workspace
        // on the account is already ready — still stamp account completion.
        if ($account->onboarding_completed_at === null && $actor !== null) {
            $resolver->tryMarkAccountComplete($account, $actor);
        }
    }

    /**
     * Broadcast only while the workspace account still has active onboarding.
     * Sync the actor first (correct PostHog attribution), then any other users
     * currently on this workspace so completion can stamp if they are ready.
     */
    public static function dispatchForWorkspace(?string $workspaceId, ?User $actor = null): void
    {
        if (blank($workspaceId)) {
            return;
        }

        $account = Workspace::query()->find($workspaceId)?->account;

        if ($account === null
            || $account->onboarding_completed_at !== null
            || $account->onboarding_dismissed_at !== null
        ) {
            return;
        }

        static::dispatch($workspaceId);

        $resolver = app(ResolveOnboardingStatus::class);

        if ($actor !== null && (string) $actor->current_workspace_id === (string) $workspaceId) {
            $actor->loadMissing(['account', 'currentWorkspace']);
            $resolver->syncProgress($actor);
            $account->refresh();

            if ($account->onboarding_completed_at !== null) {
                return;
            }
        }

        User::query()
            ->with(['account', 'currentWorkspace'])
            ->where('current_workspace_id', $workspaceId)
            ->where('account_id', $account->id)
            ->when(
                $actor !== null && (string) $actor->current_workspace_id === (string) $workspaceId,
                fn ($query) => $query->whereKeyNot($actor->id),
            )
            ->each(function (User $user) use ($resolver, $account): void {
                if ($account->onboarding_completed_at !== null) {
                    return;
                }

                $resolver->syncProgress($user);
                $account->refresh();
            });
    }

    public function broadcastAs(): string
    {
        return 'onboarding.status.updated';
    }

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("workspace.{$this->workspaceId}"),
        ];
    }

    /**
     * @return array{workspace_id: string}
     */
    public function broadcastWith(): array
    {
        return [
            'workspace_id' => $this->workspaceId,
        ];
    }

    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }
}
