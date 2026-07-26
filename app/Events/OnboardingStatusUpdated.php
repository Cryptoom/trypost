<?php

declare(strict_types=1);

namespace App\Events;

use App\Actions\Onboarding\ResolveOnboardingStatus;
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
     * Broadcast only while the workspace account still has active onboarding.
     * Sync progress after broadcasting so the last completed step still reaches
     * the UI before onboarding_completed_at is stamped.
     */
    public static function dispatchForWorkspace(?string $workspaceId): void
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

        User::query()
            ->with(['account', 'currentWorkspace'])
            ->where('current_workspace_id', $workspaceId)
            ->where('account_id', $account->id)
            ->each(function (User $user): void {
                app(ResolveOnboardingStatus::class)->syncProgress($user);
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
