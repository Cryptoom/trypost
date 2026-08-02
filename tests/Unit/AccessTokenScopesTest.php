<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;

test('personalAccess scope only returns personal access api keys', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);

    $pat = $user->createToken('PAT')->token;
    $pat->forceFill(['workspace_id' => $workspace->id])->saveQuietly();

    $oauth = mcpAccessToken($user, mcpOauthClient(), $workspace);

    $ids = AccessToken::query()->personalAccess()->pluck('id');

    expect($ids)->toContain($pat->id);
    expect($ids)->not->toContain($oauth->id);
});

test('activeMcpOAuth scope only returns non-revoked oauth grants', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);

    $pat = $user->createToken('PAT')->token;
    $pat->forceFill(['workspace_id' => $workspace->id])->saveQuietly();

    $active = mcpAccessToken($user, mcpOauthClient('Active'), $workspace);
    $revoked = mcpAccessToken($user, mcpOauthClient('Revoked'), $workspace);
    $revoked->forceFill(['revoked' => true])->saveQuietly();

    $ids = AccessToken::query()->activeMcpOAuth()->pluck('id');

    expect($ids)->toContain($active->id);
    expect($ids)->not->toContain($revoked->id);
    expect($ids)->not->toContain($pat->id);
});

test('isMcpOAuthGrant distinguishes oauth from personal access', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);

    $pat = AccessToken::query()->find($user->createToken('PAT')->token->id);
    $oauth = mcpAccessToken($user, mcpOauthClient(), $workspace);

    expect($pat->isMcpOAuthGrant())->toBeFalse();
    expect($oauth->isMcpOAuthGrant())->toBeTrue();
});
