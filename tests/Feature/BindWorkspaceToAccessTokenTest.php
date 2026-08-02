<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Listeners\BindWorkspaceToAccessToken;
use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;
use App\Passport\AuthCode;
use App\Passport\AuthCodeRepository;
use Illuminate\Support\Str;
use Laravel\Passport\Events\AccessTokenCreated;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use Mockery;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Admin->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user->refresh();
    $this->clientId = mcpOauthClient();
});

test('authorization code grant binds workspace from the auth code', function () {
    $code = Str::random(80);

    AuthCode::query()->forceCreate([
        'id' => $code,
        'user_id' => $this->user->id,
        'client_id' => $this->clientId,
        'workspace_id' => $this->workspace->id,
        'scopes' => '[]',
        'revoked' => true,
        'expires_at' => now()->addMinutes(10),
    ]);

    $token = mcpAccessToken($this->user, $this->clientId, workspace: null);

    expect($token->workspace_id)->toBeNull();

    request()->merge([
        'grant_type' => 'authorization_code',
        'code' => $code,
    ]);

    (new BindWorkspaceToAccessToken)->handle(new AccessTokenCreated(
        $token->id,
        (string) $this->user->id,
        $this->clientId,
    ));

    expect($token->refresh()->workspace_id)->toBe($this->workspace->id);
});

test('refresh grant inherits workspace from the previous token', function () {
    $previous = mcpAccessToken($this->user, $this->clientId, $this->workspace);

    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $otherWorkspace->members()->attach($this->user->id, ['role' => Role::Admin->value]);
    $this->user->update(['current_workspace_id' => $otherWorkspace->id]);

    $refreshed = mcpAccessToken($this->user, $this->clientId, workspace: null);

    request()->merge(['grant_type' => 'refresh_token']);

    (new BindWorkspaceToAccessToken)->handle(new AccessTokenCreated(
        $refreshed->id,
        (string) $this->user->id,
        $this->clientId,
    ));

    expect($refreshed->refresh()->workspace_id)->toBe($previous->workspace_id);
    expect($refreshed->refresh()->workspace_id)->not->toBe($otherWorkspace->id);
});

test('personal access tokens are left alone for controllers to bind', function () {
    $result = $this->user->createToken('PAT');
    $token = AccessToken::query()->find($result->token->id);

    expect($token->workspace_id)->toBeNull();

    request()->merge(['grant_type' => 'personal_access']);

    (new BindWorkspaceToAccessToken)->handle(new AccessTokenCreated(
        $token->id,
        (string) $this->user->id,
        (string) $token->client_id,
    ));

    expect($token->refresh()->workspace_id)->toBeNull();
});

test('auth code repository captures the authorizing user current workspace', function () {
    $this->actingAs($this->user);

    $client = Mockery::mock(ClientEntityInterface::class);
    $client->shouldReceive('getIdentifier')->andReturn($this->clientId);

    $entity = Mockery::mock(AuthCodeEntityInterface::class);
    $entity->shouldReceive('getIdentifier')->andReturn(Str::random(80));
    $entity->shouldReceive('getUserIdentifier')->andReturn((string) $this->user->id);
    $entity->shouldReceive('getClient')->andReturn($client);
    $entity->shouldReceive('getScopes')->andReturn([]);
    $entity->shouldReceive('getExpiryDateTime')->andReturn(now()->addMinutes(10)->toDateTimeImmutable());

    app(AuthCodeRepository::class)->persistNewAuthCode($entity);

    $stored = AuthCode::query()->where('client_id', $this->clientId)->first();

    expect($stored)->not->toBeNull();
    expect($stored->workspace_id)->toBe($this->workspace->id);
});

test('oauth grant without a resolvable workspace is revoked', function () {
    $this->user->update(['current_workspace_id' => null]);
    $this->workspace->members()->detach($this->user->id);

    $token = mcpAccessToken($this->user, $this->clientId, workspace: null);

    request()->merge(['grant_type' => 'authorization_code']);

    (new BindWorkspaceToAccessToken)->handle(new AccessTokenCreated(
        $token->id,
        (string) $this->user->id,
        $this->clientId,
    ));

    expect($token->refresh()->revoked)->toBeTrue();
    expect($token->refresh()->workspace_id)->toBeNull();
});
