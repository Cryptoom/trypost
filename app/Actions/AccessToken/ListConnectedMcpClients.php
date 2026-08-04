<?php

declare(strict_types=1);

namespace App\Actions\AccessToken;

use App\Models\AccessToken;
use App\Models\User;
use Illuminate\Support\Collection;

class ListConnectedMcpClients
{
    /**
     * Account-wide MCP OAuth connections (Passport-style grouping), keyed by
     * client + owning user so teammates see who signed each app in.
     *
     * @see https://laravel.com/docs/passport#managing-tokens
     *
     * @return list<array{client_id: string, name: string, user_id: string, user_name: string, can_disconnect: bool, last_used_at: mixed}>
     */
    public static function forAccount(string $accountId, User $viewer): array
    {
        $tokens = AccessToken::query()
            ->whereIn('user_id', User::query()->select('id')->where('account_id', $accountId))
            ->activeMcpOAuth()
            ->with(['client', 'workspace'])
            ->get();

        $owners = User::query()
            ->with('currentWorkspace')
            ->whereKey($tokens->pluck('user_id')->unique()->filter())
            ->get()
            ->keyBy('id');

        return $tokens
            ->filter(
                fn (AccessToken $token): bool => $token->isUsableMcpGrant($owners->get($token->user_id)),
            )
            ->groupBy(fn (AccessToken $token): string => "{$token->client_id}:{$token->user_id}")
            ->map(function (Collection $group) use ($viewer, $owners): array {
                /** @var AccessToken $token */
                $token = $group->first();
                $owner = $owners->get($token->user_id);

                return [
                    'client_id' => $token->client_id,
                    'name' => $token->client->name,
                    'user_id' => (string) $token->user_id,
                    'user_name' => (string) ($owner?->name ?? ''),
                    'can_disconnect' => $token->user_id === $viewer->id,
                    'last_used_at' => $group->max('last_used_at'),
                ];
            })
            ->values()
            ->all();
    }
}
