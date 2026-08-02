<?php

declare(strict_types=1);

namespace App\Passport;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Bridge\AuthCodeRepository as PassportAuthCodeRepository;
use Laravel\Passport\Passport;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;

/**
 * Persists the authorizing user's current workspace on the auth code so the
 * subsequent token exchange (no browser session) can bind the access token.
 */
class AuthCodeRepository extends PassportAuthCodeRepository
{
    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        Passport::authCode()->forceFill([
            'id' => $authCodeEntity->getIdentifier(),
            'user_id' => $authCodeEntity->getUserIdentifier(),
            'client_id' => $authCodeEntity->getClient()->getIdentifier(),
            'workspace_id' => $this->resolveWorkspaceId($authCodeEntity->getUserIdentifier()),
            'scopes' => json_encode($authCodeEntity->getScopes()),
            'revoked' => false,
            'expires_at' => $authCodeEntity->getExpiryDateTime(),
        ])->save();
    }

    private function resolveWorkspaceId(?string $userId): ?string
    {
        $fromSession = session('oauth_connecting_workspace_id');

        if (is_string($fromSession) && $fromSession !== '') {
            return $fromSession;
        }

        $user = Auth::user();

        if ($user instanceof User && $user->current_workspace_id) {
            return (string) $user->current_workspace_id;
        }

        if (! $userId) {
            return null;
        }

        $persisted = User::query()->find($userId);

        return $persisted?->current_workspace_id
            ? (string) $persisted->current_workspace_id
            : null;
    }
}
