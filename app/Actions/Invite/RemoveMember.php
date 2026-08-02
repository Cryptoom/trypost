<?php

declare(strict_types=1);

namespace App\Actions\Invite;

use App\Actions\AccessToken\RevokeAccessTokens;
use App\Actions\User\ReassignCurrentWorkspace;
use App\Actions\User\SettleStrandedMember;
use App\Actions\User\StrandedSettlement;
use App\Models\AccessToken;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class RemoveMember
{
    public static function execute(Workspace $workspace, string $userId): void
    {
        $settlement = StrandedSettlement::none();

        DB::transaction(function () use ($workspace, $userId, &$settlement): void {
            $account = $workspace->account;

            // Serialize with DeleteWorkspace / other RemoveMember calls on this
            // account so concurrent removals cannot skip stranded cleanup.
            if ($account?->id) {
                Account::query()->whereKey($account->id)->lockForUpdate()->first();
            }

            $user = User::query()->find($userId);

            $workspace->members()->detach($userId);

            if (! $user) {
                return;
            }

            $user->refresh();

            // Workspace-scoped API keys and MCP OAuth grants must not keep
            // working after membership ends (even if the user remains on
            // another workspace of the same account).
            RevokeAccessTokens::execute(
                AccessToken::query()
                    ->where('user_id', $user->id)
                    ->where('workspace_id', $workspace->id)
                    ->where('revoked', false)
                    ->get(),
            );

            if ($user->current_workspace_id === $workspace->id) {
                ReassignCurrentWorkspace::forUserAwayFrom($user, $workspace);
                $user->refresh();
            }

            // Last membership on this shared account — delete the invitee.
            if (
                $account
                && $user->account_id === $account->id
                && $user->id !== $account->owner_id
            ) {
                $settlement = SettleStrandedMember::execute($user, $account);
            }
        });

        $settlement->flush();
    }
}
