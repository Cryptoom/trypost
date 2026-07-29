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
use Illuminate\Support\Facades\DB;

class OnboardingStatusUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public string $workspaceId) {}

    /**
     * Notify every workspace channel that onboarding state changed — no sync.
     * Use after complete/dismiss so owner residual banners update immediately
     * even when dispatchForAccount would early-return on stamped timestamps.
     */
    public static function broadcastForAccount(?Account $account): void
    {
        if ($account === null) {
            return;
        }

        foreach ($account->workspaces()->pluck('id') as $workspaceId) {
            static::dispatch((string) $workspaceId);
        }
    }

    /**
     * Broadcast to every workspace on the account and sync progress for the actor.
     * Use when a step is account-scoped (e.g. MCP OAuth).
     *
     * Sync/analytics run afterCommit so Cache/PostHog never outlive a rolled-back
     * CreatePost (or similar) transaction.
     */
    public static function dispatchForAccount(?Account $account, ?User $actor = null): void
    {
        if ($account === null
            || $account->onboarding_completed_at !== null
            || $account->onboarding_dismissed_at !== null
        ) {
            return;
        }

        $accountId = $account->id;
        $actorId = $actor?->id;

        DB::afterCommit(function () use ($accountId, $actorId): void {
            $account = Account::query()->find($accountId);

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
            $actor = $actorId !== null
                ? User::query()->with(['account', 'currentWorkspace'])->find($actorId)
                : null;

            if ($actor !== null) {
                // Steps are account-scoped — syncProgress stamps when MCP + social
                // + post exist anywhere on the account.
                $resolver->syncProgress($actor);
            }
        });
    }

    /**
     * Broadcast only while the workspace account still has active onboarding.
     * Sync the actor first (correct PostHog attribution), then any other users
     * currently on this workspace so completion can stamp if they are ready.
     *
     * Sync/analytics run afterCommit so Cache/PostHog never outlive a rolled-back
     * CreatePost (or similar) transaction.
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

        $actorId = $actor?->id;

        DB::afterCommit(function () use ($workspaceId, $actorId): void {
            $account = Workspace::query()->find($workspaceId)?->account;

            if ($account === null
                || $account->onboarding_completed_at !== null
                || $account->onboarding_dismissed_at !== null
            ) {
                return;
            }

            static::dispatch($workspaceId);

            $resolver = app(ResolveOnboardingStatus::class);
            $syncedActor = false;
            $actor = $actorId !== null
                ? User::query()->with(['account', 'currentWorkspace'])->find($actorId)
                : null;

            if ($actor !== null) {
                // API/MCP tokens often mutate the request user's workspace in memory
                // only — align the actor to this workspace for checklist resolution.
                if ((string) $actor->current_workspace_id !== (string) $workspaceId) {
                    $workspace = Workspace::query()->find($workspaceId);

                    if ($workspace !== null) {
                        $actor->setRelation('currentWorkspace', $workspace);
                        $actor->current_workspace_id = $workspace->id;
                    }
                }

                if ((string) $actor->current_workspace_id === (string) $workspaceId) {
                    $resolver->syncProgress($actor);
                    $account->refresh();
                    $syncedActor = true;

                    if ($account->onboarding_completed_at !== null) {
                        return;
                    }
                }
            }

            User::query()
                ->with(['account', 'currentWorkspace'])
                ->where('current_workspace_id', $workspaceId)
                ->where('account_id', $account->id)
                ->when(
                    $syncedActor && $actor !== null,
                    fn ($query) => $query->whereKeyNot($actor->id),
                )
                ->each(function (User $user) use ($resolver, $account): void {
                    if ($account->onboarding_completed_at !== null) {
                        return;
                    }

                    $resolver->syncProgress($user);
                    $account->refresh();
                });

            // Fan out Echo to sibling workspaces so residual progress updates
            // everywhere (sync already ran for users on this workspace).
            $account->refresh();

            if ($account->onboarding_completed_at === null
                && $account->onboarding_dismissed_at === null
            ) {
                $account->workspaces()
                    ->whereKeyNot($workspaceId)
                    ->pluck('id')
                    ->each(fn ($siblingId) => static::dispatch((string) $siblingId));
            }
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
