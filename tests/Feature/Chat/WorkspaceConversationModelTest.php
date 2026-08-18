<?php

declare(strict_types=1);

use App\Enums\WorkspaceConversation\Message\Role;
use App\Enums\WorkspaceConversation\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceConversation;
use App\Models\WorkspaceConversationMessage;

test('a conversation casts its status and owns messages', function () {
    $conversation = WorkspaceConversation::factory()->create();

    WorkspaceConversationMessage::factory()
        ->for($conversation, 'conversation')
        ->create(['role' => Role::User, 'content' => 'Hi']);

    expect($conversation->status)->toBe(Status::Idle)
        ->and($conversation->messages)->toHaveCount(1)
        ->and($conversation->messages->first()->role)->toBe(Role::User);
});

test('a message round-trips its json columns', function () {
    $message = WorkspaceConversationMessage::factory()->create([
        'tool_calls' => [['id' => 'call_1', 'name' => 'list_posts', 'arguments' => ['status' => 'draft']]],
        'tool_results' => [['id' => 'call_1', 'result' => '{"data":[]}']],
    ]);

    expect($message->fresh()->tool_calls[0]['name'])->toBe('list_posts')
        ->and($message->fresh()->tool_results[0]['id'])->toBe('call_1');
});

test('the listable scope hides untitled, soft deleted and other users conversations', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $other = User::factory()->create();

    $visible = WorkspaceConversation::factory()->for($workspace)->for($user)->create(['title' => 'Visible']);
    WorkspaceConversation::factory()->for($workspace)->for($user)->create(['title' => null]);
    WorkspaceConversation::factory()->for($workspace)->for($other)->create(['title' => 'Other user']);
    WorkspaceConversation::factory()->for($workspace)->for($user)->create(['title' => 'Deleted'])->delete();

    $listable = WorkspaceConversation::query()->listable($workspace->id, $user->id)->get();

    expect($listable->pluck('id')->all())->toBe([$visible->id]);
});
