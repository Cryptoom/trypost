<?php

declare(strict_types=1);

use App\Ai\Tools\ToolReplayer;
use App\Enums\Post\Status;
use App\Enums\SocialAccount\Platform;
use App\Enums\WorkspaceConversation\Message\Role;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceConversation;
use App\Models\WorkspaceConversationMessage;
use Illuminate\Support\Facades\Http;

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

test('a read tool whose record was deleted falls back to the stored result instead of the fresh error', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();
    $post = Post::factory()->for($workspace)->create(['content' => 'Will be deleted']);

    $stored = json_encode(['data' => ['id' => $post->id, 'content' => 'Will be deleted']]);

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Here it is.',
        'tool_calls' => [['id' => 'call_3', 'name' => 'get_post', 'arguments' => ['post_id' => $post->id]]],
        'tool_results' => [['id' => 'call_3', 'result' => $stored]],
    ]);

    $post->delete();

    $payloads = app(ToolReplayer::class)->replay($conversation);

    expect($payloads['call_3'])->toBe($stored);
});

test('a tool call missing an id does not throw', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Ok.',
        'tool_calls' => [['name' => 'list_posts', 'arguments' => []]],
        'tool_results' => [],
    ]);

    $payloads = app(ToolReplayer::class)->replay($conversation);

    expect($payloads)->toBeArray();
});

test('a tool call with null arguments does not throw', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Ok.',
        'tool_calls' => [['id' => 'call_4', 'name' => 'list_posts', 'arguments' => null]],
        'tool_results' => [['id' => 'call_4', 'result' => '{"data":[]}']],
    ]);

    $payloads = app(ToolReplayer::class)->replay($conversation);

    expect(json_decode($payloads['call_4'], true))->toHaveKey('data');
});

test('an unknown tool name falls back to the stored result without being invoked', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Ok.',
        'tool_calls' => [['id' => 'call_5', 'name' => 'some_future_tool', 'arguments' => []]],
        'tool_results' => [['id' => 'call_5', 'result' => '{"data":"whatever"}']],
    ]);

    $payloads = app(ToolReplayer::class)->replay($conversation);

    expect($payloads['call_5'])->toBe('{"data":"whatever"}');
});

test('get_post_metrics is not replayed and keeps its stored result without calling any platform', function () {
    Http::preventStrayRequests();

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();
    $post = Post::factory()->for($workspace)->create(['status' => Status::Published]);

    $stored = json_encode(['data' => ['id' => $post->id, 'platforms' => []]]);

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Here are the numbers.',
        'tool_calls' => [['id' => 'call_6', 'name' => 'get_post_metrics', 'arguments' => ['post_id' => $post->id]]],
        'tool_results' => [['id' => 'call_6', 'result' => $stored]],
    ]);

    $payloads = app(ToolReplayer::class)->replay($conversation);

    expect($payloads['call_6'])->toBe($stored);
});

test('start_post_generation replays so a disconnected account is no longer offered', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    $account = SocialAccount::factory()->for($workspace)->x()->create(['display_name' => 'Acme X']);

    $stored = json_encode(['data' => [
        'formats' => [[
            'value' => 'x_post',
            'platform' => Platform::X->value,
            'accounts' => [['id' => $account->id, 'label' => 'Acme X']],
        ]],
        'styles' => [],
        'applies_brand_visuals_default' => true,
    ]]);

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Pick a format.',
        'tool_calls' => [['id' => 'call_7', 'name' => 'start_post_generation', 'arguments' => []]],
        'tool_results' => [['id' => 'call_7', 'result' => $stored]],
    ]);

    $account->update(['is_active' => false]);

    $payloads = app(ToolReplayer::class)->replay($conversation);

    $replayed = json_decode($payloads['call_7'], true);

    expect($payloads['call_7'])->not->toBe($stored)
        ->and(data_get($replayed, 'data.formats'))->toBe([])
        ->and(data_get($replayed, 'data.styles'))->not->toBeEmpty();
});

test('start_post_generation replays a newly connected account into an old conversation', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    $stored = json_encode(['data' => [
        'formats' => [],
        'styles' => [],
        'applies_brand_visuals_default' => true,
    ]]);

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Pick a format.',
        'tool_calls' => [['id' => 'call_8', 'name' => 'start_post_generation', 'arguments' => []]],
        'tool_results' => [['id' => 'call_8', 'result' => $stored]],
    ]);

    $account = SocialAccount::factory()->for($workspace)->x()->create(['display_name' => 'Acme X']);

    $payloads = app(ToolReplayer::class)->replay($conversation);

    $formats = data_get(json_decode($payloads['call_8'], true), 'data.formats');

    expect(array_column($formats, 'value'))->toContain('x_post')
        ->and(data_get($formats, '0.accounts.0.id'))->toBe($account->id);
});
