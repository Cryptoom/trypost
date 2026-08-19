<?php

declare(strict_types=1);

use App\Models\Workspace;
use App\Models\WorkspaceConversation;

test('the sidebar lists only this users titled conversations, newest first', function () {
    [$user, $workspace] = actingAsWorkspaceUser();

    $older = WorkspaceConversation::factory()->for($workspace)->for($user)->create(['title' => 'Older']);
    $older->forceFill(['updated_at' => now()->subDay()])->saveQuietly();

    $newer = WorkspaceConversation::factory()->for($workspace)->for($user)->create(['title' => 'Newer']);
    WorkspaceConversation::factory()->for($workspace)->for($user)->untitled()->create();
    WorkspaceConversation::factory()->for($workspace)->create(['title' => 'Someone else']);

    $this->get(route('app.chat'))
        ->assertInertia(fn ($page) => $page
            ->component('chat/Index')
            ->has('conversations', 2)
            ->where('conversations.0.title', 'Newer')
            ->where('conversations.1.title', 'Older'));
});

test('another users conversation cannot be opened', function () {
    [$user, $workspace] = actingAsWorkspaceUser();
    $foreign = WorkspaceConversation::factory()->for($workspace)->create(['title' => 'Not yours']);

    $this->get(route('app.chat.show', $foreign->id))->assertNotFound();
});

test('a conversation can be renamed and soft deleted', function () {
    [$user, $workspace] = actingAsWorkspaceUser();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create(['title' => 'Before']);

    $this->patch(route('app.chat.update', $conversation->id), ['title' => 'After']);
    expect($conversation->fresh()->title)->toBe('After');

    $this->delete(route('app.chat.destroy', $conversation->id));
    expect($conversation->fresh()->trashed())->toBeTrue();
});

test('an untitled conversation can still be opened by its owner', function () {
    [$user, $workspace] = actingAsWorkspaceUser();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->untitled()->create();

    $this->get(route('app.chat.show', $conversation->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('chat/Index')
            ->where('conversation.id', $conversation->id)
            ->where('conversation.title', null));
});

test('an untitled conversation still does not appear in the sidebar list', function () {
    [$user, $workspace] = actingAsWorkspaceUser();
    WorkspaceConversation::factory()->for($workspace)->for($user)->untitled()->create();

    $this->get(route('app.chat'))
        ->assertInertia(fn ($page) => $page
            ->component('chat/Index')
            ->has('conversations', 0));
});

test('another users untitled conversation still cannot be opened', function () {
    [$user, $workspace] = actingAsWorkspaceUser();
    $foreign = WorkspaceConversation::factory()->for($workspace)->untitled()->create();

    $this->get(route('app.chat.show', $foreign->id))->assertNotFound();
});

test('this users conversation in a different workspace cannot be opened', function () {
    [$user, $workspace] = actingAsWorkspaceUser();
    $otherWorkspace = Workspace::factory()->create();
    $elsewhere = WorkspaceConversation::factory()->for($otherWorkspace)->for($user)->create(['title' => 'Wrong workspace']);

    $this->get(route('app.chat.show', $elsewhere->id))->assertNotFound();
});
