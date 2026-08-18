<?php

declare(strict_types=1);

use App\Ai\Tools\ToolReplayer;
use App\Enums\WorkspaceConversation\Message\Role;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceConversation;
use App\Models\WorkspaceConversationMessage;

test('reopening re-executes a read tool with fresh data', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Here they are.',
        'tool_calls' => [['id' => 'call_1', 'name' => 'list_posts', 'arguments' => []]],
        'tool_results' => [['id' => 'call_1', 'result' => '{"data":[]}']],
    ]);

    Post::factory()->for($workspace)->create(['content' => 'Created after the conversation']);

    $payloads = app(ToolReplayer::class)->replay($conversation);

    expect(json_decode($payloads['call_1'], true)['data'])->toHaveCount(1);
});

test('a write tool is not replayed and keeps its stored result', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Created it.',
        'tool_calls' => [['id' => 'call_2', 'name' => 'create_post', 'arguments' => ['content' => 'x']]],
        'tool_results' => [['id' => 'call_2', 'result' => '{"data":{"id":"stored"}}']],
    ]);

    $payloads = app(ToolReplayer::class)->replay($conversation);

    expect(json_decode($payloads['call_2'], true)['data']['id'])->toBe('stored')
        ->and(Post::count())->toBe(0);
});
