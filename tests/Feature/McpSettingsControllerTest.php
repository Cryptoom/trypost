<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Admin->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user->refresh();
});

function mcpOauthClient(string $name = 'My Agent'): string
{
    $id = (string) Str::uuid();

    DB::table('oauth_clients')->insert([
        'id' => $id,
        'name' => $name,
        'secret' => null,
        'provider' => null,
        'redirect_uris' => '[]',
        'grant_types' => json_encode(['authorization_code']),
        'revoked' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function mcpAccessToken(User $user, string $clientId): AccessToken
{
    $token = new AccessToken;
    $token->forceFill([
        'id' => Str::random(80),
        'user_id' => $user->id,
        'client_id' => $clientId,
        'workspace_id' => null,
        'name' => 'MCP',
        'scopes' => [],
        'revoked' => false,
    ])->save();

    return $token->refresh();
}

it('shows the mcp settings page', function (): void {
    $this->actingAs($this->user)
        ->get(route('app.mcp.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/workspace/Mcp')
            ->where('mcpUrl', url('/mcp/trypost'))
            ->has('docsUrl')
            ->has('connectedClients'));
});

it('lists oauth clients as connected, excluding the personal access client', function (): void {
    $clientId = mcpOauthClient('My Agent');
    mcpAccessToken($this->user, $clientId);

    $pat = $this->user->createToken('API Key');
    AccessToken::query()->findOrFail($pat->token->id)
        ->forceFill(['workspace_id' => $this->workspace->id])
        ->saveQuietly();

    $this->actingAs($this->user)
        ->get(route('app.mcp.index'))
        ->assertInertia(fn ($page) => $page
            ->has('connectedClients', 1)
            ->where('connectedClients.0.name', 'My Agent'));
});

it('disconnects a client by revoking its tokens', function (): void {
    $clientId = mcpOauthClient();
    $token = mcpAccessToken($this->user, $clientId);

    $this->actingAs($this->user)
        ->delete(route('app.mcp.disconnect', ['client' => $clientId]))
        ->assertRedirect();

    expect($token->fresh()->revoked)->toBeTrue();
});

it('requires authentication', function (): void {
    $this->get(route('app.mcp.index'))->assertRedirect();
});
