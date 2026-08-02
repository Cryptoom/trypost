<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\AccessToken;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspaceA = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
        'name' => 'Workspace A',
    ]);
    $this->workspaceB = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
        'name' => 'Workspace B',
    ]);
    $this->workspaceA->members()->attach($this->user->id, ['role' => Role::Admin->value]);
    $this->workspaceB->members()->attach($this->user->id, ['role' => Role::Admin->value]);
    $this->user->update(['current_workspace_id' => $this->workspaceA->id]);
});

test('token uses its bound workspace even when the user switched current workspace', function () {
    $plain = passportToken($this->user, $this->workspaceA);

    $this->user->update(['current_workspace_id' => $this->workspaceB->id]);

    $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->getJson(route('api.workspace.show'))
        ->assertOk()
        ->assertJsonPath('id', $this->workspaceA->id)
        ->assertJsonPath('name', 'Workspace A');
});

test('token without workspace_id is rejected', function () {
    $result = $this->user->createToken('Unbound');
    $plain = $result->accessToken;

    AccessToken::query()
        ->whereKey($result->token->id)
        ->update(['workspace_id' => null]);

    $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->getJson(route('api.workspace.show'))
        ->assertUnauthorized()
        ->assertJsonPath('message', 'No workspace selected.');
});

test('token bound to a workspace the user no longer belongs to is rejected', function () {
    $plain = passportToken($this->user, $this->workspaceA);

    $this->workspaceA->members()->detach($this->user->id);
    $this->user->update(['current_workspace_id' => $this->workspaceB->id]);

    $this->withHeaders(['Authorization' => "Bearer {$plain}"])
        ->getJson(route('api.workspace.show'))
        ->assertUnauthorized()
        ->assertJsonPath('message', 'No workspace selected.');
});
