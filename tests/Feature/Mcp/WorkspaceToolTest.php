<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Workspace\GetWorkspaceTool;
use App\Mcp\Tools\Workspace\UpdateWorkspaceTool;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Testing\Fluent\AssertableJson;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

test('get workspace returns sanitized WorkspaceResource shape', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(GetWorkspaceTool::class, []);

    $response->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->where('id', $this->workspace->id)
                ->where('name', $this->workspace->name)
                ->hasAll(['created_at', 'updated_at'])
                ->missing('account_id')
                ->missing('user_id')
                ->missing('brand_color')
                ->missing('content_language');
        });
});

test('update workspace sets a valid field and leaves others untouched', function () {
    $this->workspace->members()->updateExistingPivot($this->user->id, ['role' => Role::Admin->value]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UpdateWorkspaceTool::class, [
            'brand_description' => 'A friendly, no hype coffee brand.',
        ]);

    $response->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->where('id', $this->workspace->id)
                ->where('name', $this->workspace->name)
                ->hasAll(['created_at', 'updated_at']);
        });

    $this->workspace->refresh();

    expect($this->workspace->brand_description)->toBe('A friendly, no hype coffee brand.')
        ->and($this->workspace->content_language)->toBe('en');
});

test('update workspace rejects an invalid brand color', function () {
    $this->workspace->members()->updateExistingPivot($this->user->id, ['role' => Role::Admin->value]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UpdateWorkspaceTool::class, [
            'brand_color' => 'not-a-hex-color',
        ]);

    $response->assertHasErrors();

    expect($this->workspace->fresh()->brand_color)->toBeNull();
});

test('update workspace only touches the caller current workspace, other workspaces are untouched', function () {
    $this->workspace->members()->updateExistingPivot($this->user->id, ['role' => Role::Admin->value]);

    $otherWorkspace = Workspace::factory()->create(['name' => 'Untouched Workspace']);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UpdateWorkspaceTool::class, [
            'name' => 'Hijacked',
        ]);

    $response->assertOk();

    expect($otherWorkspace->fresh()->name)->toBe('Untouched Workspace')
        ->and($this->workspace->fresh()->name)->toBe('Hijacked');
});

test('update workspace denies a member without admin or owner role', function () {
    // $this->user auto-owns its own freshly-created account (see UserFactory::configure()),
    // so a real "non-owner member" needs a second user sharing that SAME account, the way
    // McpRoleAuthorizationTest builds its viewer/member fixtures.
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $response = TryPostServer::actingAs($member)
        ->tool(UpdateWorkspaceTool::class, [
            'name' => 'Should Not Apply',
        ]);

    $response->assertHasErrors(['Not authorized to update this workspace.']);

    expect($this->workspace->fresh()->name)->not->toBe('Should Not Apply');
});

test('update workspace coerces brand_voice_traits to a coherent selection', function () {
    $this->workspace->members()->updateExistingPivot($this->user->id, ['role' => Role::Admin->value]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UpdateWorkspaceTool::class, [
            'brand_voice_traits' => ['formal', 'casual', 'direct'],
        ]);

    $response->assertOk();

    $traits = $this->workspace->fresh()->brand_voice_traits;

    expect($traits)->toContain('direct')
        ->and($traits)->toContain('formal')
        ->and($traits)->not->toContain('casual')
        ->and($traits)->toHaveCount(2);
});
