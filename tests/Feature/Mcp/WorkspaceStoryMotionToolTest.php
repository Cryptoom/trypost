<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Workspace\UpdateWorkspaceTool;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Testing\Fluent\AssertableJson;

// PATCH:story-motion-settings: the Story video settings through the MCP workspace tool.

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Admin->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->update = fn (array $arguments) => TryPostServer::actingAs($this->user)->tool(UpdateWorkspaceTool::class, $arguments);
});

test('mcp story motion: only the sent keys change and the result carries the current settings', function () {
    ($this->update)(['story_motion_settings' => ['enabled' => true, 'font' => 'noto-sans', 'ending' => ['enabled' => true, 'name' => 'A', 'line' => 'B']]])->assertOk();

    ($this->update)(['story_motion_settings' => ['ending' => ['line' => 'C']]])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) {
            $json->where('story_motion_settings.enabled', true)
                ->where('story_motion_settings.font', 'noto-sans')
                ->where('story_motion_settings.ending', ['enabled' => true, 'name' => 'A', 'line' => 'C'])
                ->etc();
        });

    expect($this->workspace->fresh()->story_motion_settings['ending'])->toBe(['enabled' => true, 'name' => 'A', 'line' => 'C']);
});

test('mcp story motion: the Instagram switch is off by default and can be switched on', function () {
    ($this->update)(['brand_description' => 'x'])->assertOk()->assertStructuredContent(function (AssertableJson $json) {
        $json->where('story_motion_settings.instagram', false)->etc();
    });

    ($this->update)(['story_motion_settings' => ['enabled' => true, 'instagram' => true]])->assertOk();

    expect($this->workspace->fresh()->story_motion_settings['instagram'])->toBeTrue();

    ($this->update)(['story_motion_settings' => ['instagram' => 'maybe']])->assertHasErrors();
});

test('mcp story motion: a call without the key leaves the stored settings alone', function () {
    ($this->update)(['story_motion_settings' => ['enabled' => true]])->assertOk();
    ($this->update)(['brand_description' => 'Only text'])->assertOk();

    expect($this->workspace->fresh()->story_motion_settings['enabled'])->toBeTrue();
});

test('mcp story motion: a read of a workspace without settings shows the defaults, which are off', function () {
    ($this->update)(['brand_description' => 'x'])->assertOk()->assertStructuredContent(function (AssertableJson $json) {
        $json->where('story_motion_settings.enabled', false)->where('story_motion_settings.effects', ['focus'])->etc();
    });
});

test('mcp story motion: the same validation as the web form, nothing is stored on an error', function (array $settings) {
    ($this->update)(['story_motion_settings' => $settings])->assertHasErrors();

    expect($this->workspace->fresh()->story_motion_settings)->toBeNull();
})->with([
    'unknown font' => [['font' => 'comic-sans']],
    'unknown mode' => [['mode' => 'shuffle']],
    'unknown effect' => [['effects' => ['wobble']]],
    'empty effects' => [['effects' => []]],
    'long notice' => [['ai_notice' => ['text' => str_repeat('x', 41)]]],
    'long ending name' => [['ending' => ['name' => str_repeat('x', 41)]]],
    'phone number in the ending' => [['ending' => ['enabled' => true, 'name' => 'Call +357 99 123 456']]],
    'notice on with a blank text' => [['ai_notice' => ['enabled' => true, 'text' => '   ']]],
    'ending on without name and line' => [['ending' => ['enabled' => true]]],
]);

test('mcp story motion: switching a section on is checked against the merged result, not only the sent keys', function () {
    ($this->update)(['story_motion_settings' => ['ai_notice' => ['enabled' => false, 'text' => 'Stored text']]])->assertOk();

    ($this->update)(['story_motion_settings' => ['ai_notice' => ['enabled' => true]]])->assertOk();

    expect($this->workspace->fresh()->story_motion_settings['ai_notice'])->toBe(['enabled' => true, 'text' => 'Stored text']);
});

test('mcp story motion: a notice switched on without any text gets the default text', function () {
    ($this->update)(['story_motion_settings' => ['ai_notice' => ['enabled' => true]]])->assertOk();

    expect($this->workspace->fresh()->story_motion_settings['ai_notice'])->toBe(['enabled' => true, 'text' => 'AI-generated image']);
});

test('mcp story motion: a member without admin or owner role is denied', function () {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    TryPostServer::actingAs($member)->tool(UpdateWorkspaceTool::class, ['story_motion_settings' => ['enabled' => true]])
        ->assertHasErrors(['Not authorized to update this workspace.']);

    expect($this->workspace->fresh()->story_motion_settings)->toBeNull();
});

test('mcp story motion: only the current workspace is touched', function () {
    $other = Workspace::factory()->create();

    ($this->update)(['story_motion_settings' => ['enabled' => true]])->assertOk();

    expect($other->fresh()->story_motion_settings)->toBeNull();
});
