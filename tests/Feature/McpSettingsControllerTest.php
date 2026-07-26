<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;
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

it('shows the mcp settings page', function (): void {
    $this->actingAs($this->user)
        ->get(route('app.mcp.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/workspace/Mcp')
            ->where('mcpUrl', url('/mcp/trypost'))
            ->missing('docsUrl')
            ->has('mcpClients', 2)
            ->where('mcpClients.0.id', 'claude')
            ->where('mcpClients.1.id', 'chatgpt')
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
        ->assertRedirect()
        ->assertSessionHas('flash.success', __('mcp.disconnected'));

    expect($token->fresh()->revoked)->toBeTrue();
});

it('does not flash success when disconnecting an unknown client', function (): void {
    $this->actingAs($this->user)
        ->delete(route('app.mcp.disconnect', ['client' => (string) Str::uuid()]))
        ->assertRedirect()
        ->assertSessionMissing('flash.success');
});

it('allows workspace members to view and disconnect their own mcp clients', function (): void {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $clientId = mcpOauthClient('Member Agent');
    $token = mcpAccessToken($member, $clientId);

    $this->actingAs($member->fresh())
        ->get(route('app.mcp.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('connectedClients', 1)
            ->where('connectedClients.0.name', 'Member Agent'));

    $this->actingAs($member->fresh())
        ->delete(route('app.mcp.disconnect', ['client' => $clientId]))
        ->assertRedirect()
        ->assertSessionHas('flash.success');

    expect($token->fresh()->revoked)->toBeTrue();
});

it('does not revoke another users mcp client tokens', function (): void {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $clientId = mcpOauthClient('Owner Agent');
    $token = mcpAccessToken($this->user, $clientId);

    $this->actingAs($member->fresh())
        ->delete(route('app.mcp.disconnect', ['client' => $clientId]))
        ->assertRedirect()
        ->assertSessionMissing('flash.success');

    expect($token->fresh()->revoked)->toBeFalse();
});

it('requires authentication', function (): void {
    $this->get(route('app.mcp.index'))->assertRedirect();
});

it('forbids users without workspace access', function (): void {
    $outsider = User::factory()->create();
    $outsider->update(['current_workspace_id' => $this->workspace->id]);

    $this->actingAs($outsider->fresh())
        ->get(route('app.mcp.index'))
        ->assertForbidden();
});
