<?php

declare(strict_types=1);

namespace App\Actions\AccessToken;

use App\Models\AccessToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Assign existing MCP OAuth tokens (workspace_id null) to a workspace, or revoke
 * them when no confident mapping exists.
 *
 * @return array{bound: int, revoked: int}
 */
class BackfillMcpOAuthWorkspaces
{
    /**
     * @return array{bound: int, revoked: int}
     */
    public static function execute(): array
    {
        $bound = 0;
        $revoked = 0;

        AccessToken::query()
            ->whereNull('workspace_id')
            ->where('revoked', false)
            ->whereHas(
                'client',
                fn ($client) => $client
                    ->where('revoked', false)
                    ->whereJsonDoesntContain('grant_types', 'personal_access'),
            )
            ->orderBy('id')
            ->chunkById(100, function ($tokens) use (&$bound, &$revoked): void {
                foreach ($tokens as $token) {
                    $workspaceId = self::resolveWorkspaceId($token);

                    if ($workspaceId === null) {
                        self::revoke($token);
                        $revoked++;

                        continue;
                    }

                    $token->forceFill(['workspace_id' => $workspaceId])->saveQuietly();
                    $bound++;
                }
            });

        return ['bound' => $bound, 'revoked' => $revoked];
    }

    private static function resolveWorkspaceId(AccessToken $token): ?string
    {
        $user = User::query()->find($token->user_id);

        if ($user === null) {
            return null;
        }

        if ($user->current_workspace_id) {
            $current = $user->accountWorkspaces()
                ->where('workspaces.id', $user->current_workspace_id)
                ->first();

            if ($current) {
                return (string) $current->id;
            }
        }

        $fallback = $user->accountWorkspaces()
            ->orderBy('workspaces.created_at')
            ->first();

        return $fallback?->id ? (string) $fallback->id : null;
    }

    private static function revoke(AccessToken $token): void
    {
        DB::table('oauth_refresh_tokens')
            ->where('access_token_id', $token->id)
            ->update(['revoked' => true]);

        $token->forceFill(['revoked' => true])->saveQuietly();
    }
}
