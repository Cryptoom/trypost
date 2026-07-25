<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class SocialAccountPolicy
{
    /**
     * Authorize access to a social account. Must live in the user's current
     * workspace; cross-workspace lookups deny as 404 so we don't leak
     * existence across tenants (same tenancy pattern as PostPolicy).
     */
    public function view(User $user, SocialAccount $account): bool|Response
    {
        if ($account->workspace_id !== $user->current_workspace_id) {
            return Response::denyAsNotFound();
        }

        return true;
    }
}
