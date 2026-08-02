<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;
use App\Passport\AuthCode;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Events\AccessTokenCreated;

/**
 * Bind MCP OAuth access tokens to a workspace (mirroring personal API keys).
 *
 * Authorization-code grants copy workspace_id from the auth code captured at
 * consent. Refresh grants inherit the previous token's workspace so switching
 * the user's current workspace cannot silently retarget an existing connection.
 */
class BindWorkspaceToAccessToken
{
    public function handle(AccessTokenCreated $event): void
    {
        $token = AccessToken::query()->find($event->tokenId);

        if ($token === null || $token->workspace_id !== null) {
            return;
        }

        $token->loadMissing('client');

        if ($token->client === null || $token->client->hasGrantType('personal_access')) {
            return;
        }

        $workspaceId = $this->resolveWorkspaceId($event, $token);

        if ($workspaceId === null) {
            $this->revoke($token);

            return;
        }

        $token->forceFill(['workspace_id' => $workspaceId])->saveQuietly();
    }

    private function resolveWorkspaceId(AccessTokenCreated $event, AccessToken $token): ?string
    {
        $grantType = request()->input('grant_type');

        if ($grantType === 'refresh_token') {
            return $this->workspaceFromPreviousToken($event);
        }

        if ($grantType === 'authorization_code') {
            $fromAuthCode = $this->workspaceFromAuthCode();

            if ($fromAuthCode !== null && $this->userBelongsToWorkspace($event->userId, $fromAuthCode)) {
                return $fromAuthCode;
            }
        }

        return $this->workspaceFromUser($event->userId);
    }

    private function workspaceFromAuthCode(): ?string
    {
        $code = request()->input('code');

        if (! is_string($code) || $code === '') {
            return null;
        }

        $authCode = AuthCode::query()->find($code);

        return $authCode?->workspace_id
            ? (string) $authCode->workspace_id
            : null;
    }

    private function workspaceFromPreviousToken(AccessTokenCreated $event): ?string
    {
        $previous = AccessToken::query()
            ->where('user_id', $event->userId)
            ->where('client_id', $event->clientId)
            ->where('id', '!=', $event->tokenId)
            ->whereNotNull('workspace_id')
            ->latest('created_at')
            ->first();

        return $previous?->workspace_id
            ? (string) $previous->workspace_id
            : null;
    }

    private function workspaceFromUser(?string $userId): ?string
    {
        if (! $userId) {
            return null;
        }

        $user = User::query()->find($userId);

        if ($user === null) {
            return null;
        }

        $candidateId = $user->current_workspace_id
            ? (string) $user->current_workspace_id
            : null;

        if ($candidateId !== null && $this->userBelongsToWorkspace($userId, $candidateId)) {
            return $candidateId;
        }

        $fallback = $user->accountWorkspaces()->orderBy('workspaces.created_at')->first();

        return $fallback?->id ? (string) $fallback->id : null;
    }

    private function userBelongsToWorkspace(?string $userId, string $workspaceId): bool
    {
        if (! $userId) {
            return false;
        }

        $user = User::query()->find($userId);
        $workspace = Workspace::query()->find($workspaceId);

        if ($user === null || $workspace === null) {
            return false;
        }

        return $user->belongsToWorkspace($workspace);
    }

    private function revoke(AccessToken $token): void
    {
        DB::table('oauth_refresh_tokens')
            ->where('access_token_id', $token->id)
            ->update(['revoked' => true]);

        $token->forceFill(['revoked' => true])->saveQuietly();
    }
}
