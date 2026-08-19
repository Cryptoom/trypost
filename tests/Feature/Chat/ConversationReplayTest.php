<?php

declare(strict_types=1);

use App\Ai\Tools\ToolReplayer;
use App\Enums\Post\Status;
use App\Enums\SocialAccount\Platform;
use App\Enums\WorkspaceConversation\Message\Role;
use App\Jobs\Ai\StreamPostCreation;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceConversation;
use App\Models\WorkspaceConversationMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

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
        'tool_calls' => [['id' => 'call_7', 'name' => 'start_post_generation', 'arguments' => ['topic' => 'the pricing launch']]],
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
        'tool_calls' => [['id' => 'call_8', 'name' => 'start_post_generation', 'arguments' => ['topic' => 'the pricing launch']]],
        'tool_results' => [['id' => 'call_8', 'result' => $stored]],
    ]);

    $account = SocialAccount::factory()->for($workspace)->x()->create(['display_name' => 'Acme X']);

    $payloads = app(ToolReplayer::class)->replay($conversation);

    $formats = data_get(json_decode($payloads['call_8'], true), 'data.formats');

    expect(array_column($formats, 'value'))->toContain('x_post')
        ->and(data_get($formats, '0.accounts.0.id'))->toBe($account->id);
});

test('a finished generation resolves its post into the generate_post payload', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    $account = SocialAccount::factory()->for($workspace)->x()->create(['username' => 'acme']);

    $post = Post::factory()->for($workspace)->create([
        'content' => 'The generated post',
        'creation_id' => 'call_generate',
    ]);

    PostPlatform::factory()->for($post)->for($account)->create();

    $stored = json_encode(['data' => ['creation_id' => 'call_generate', 'channel' => "user.{$user->id}.ai-creation.call_generate"]]);

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Generating it now.',
        'tool_calls' => [['id' => 'call_generate', 'name' => 'generate_post', 'arguments' => ['prompt' => 'hello']]],
        'tool_results' => [['id' => 'call_generate', 'result' => $stored]],
    ]);

    $payloads = app(ToolReplayer::class)->replay($conversation);

    $data = data_get(json_decode($payloads['call_generate'], true), 'data');

    expect(data_get($data, 'post.id'))->toBe($post->id)
        ->and(data_get($data, 'post.content'))->toBe('The generated post')
        ->and(data_get($data, 'post.platforms.0.platform'))->toBe(Platform::X->value)
        ->and(data_get($data, 'creation_id'))->toBe('call_generate')
        ->and(data_get($data, 'channel'))->toBe("user.{$user->id}.ai-creation.call_generate");
});

test('a generation still in flight passes its payload through unchanged so the card keeps waiting', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    $stored = json_encode(['data' => ['creation_id' => 'call_pending', 'channel' => "user.{$user->id}.ai-creation.call_pending"]]);

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Generating it now.',
        'tool_calls' => [['id' => 'call_pending', 'name' => 'generate_post', 'arguments' => ['prompt' => 'hello']]],
        'tool_results' => [['id' => 'call_pending', 'result' => $stored]],
    ]);

    $payloads = app(ToolReplayer::class)->replay($conversation);

    expect($payloads['call_pending'])->toBe($stored);
});

test('generate_post is never re-run, so reopening neither dispatches a generation nor creates a post', function () {
    Queue::fake();

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    SocialAccount::factory()->for($workspace)->x()->create();

    $stored = json_encode(['data' => ['creation_id' => 'call_once', 'channel' => "user.{$user->id}.ai-creation.call_once"]]);

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Generating it now.',
        'tool_calls' => [['id' => 'call_once', 'name' => 'generate_post', 'arguments' => [
            'prompt' => 'A post about our new pricing page and what changed',
            'format' => 'x_post',
            'style' => 'image_card',
        ]]],
        'tool_results' => [['id' => 'call_once', 'result' => $stored]],
    ]);

    app(ToolReplayer::class)->replay($conversation);

    Queue::assertNotPushed(StreamPostCreation::class);
    expect(Post::count())->toBe(0);
});

test('a post from another workspace is never resolved into the payload', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    Post::factory()->for(Workspace::factory()->create())->create([
        'content' => 'Another workspace post',
        'creation_id' => 'call_foreign',
    ]);

    $stored = json_encode(['data' => ['creation_id' => 'call_foreign', 'channel' => "user.{$user->id}.ai-creation.call_foreign"]]);

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Generating it now.',
        'tool_calls' => [['id' => 'call_foreign', 'name' => 'generate_post', 'arguments' => ['prompt' => 'hello']]],
        'tool_results' => [['id' => 'call_foreign', 'result' => $stored]],
    ]);

    $payloads = app(ToolReplayer::class)->replay($conversation);

    expect($payloads['call_foreign'])->toBe($stored);
});

test('a generate_post call that errored keeps its error payload', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    $stored = json_encode(['error' => 'No connected accounts.']);

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'That did not work.',
        'tool_calls' => [['id' => 'call_error', 'name' => 'generate_post', 'arguments' => []]],
        'tool_results' => [['id' => 'call_error', 'result' => $stored]],
    ]);

    $payloads = app(ToolReplayer::class)->replay($conversation);

    expect($payloads['call_error'])->toBe($stored);
});

test('a generation whose turn outlived the generation window serializes as settled', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    $stored = json_encode(['data' => ['creation_id' => 'call_stale', 'channel' => "user.{$user->id}.ai-creation.call_stale"]]);

    $message = WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Generating it now.',
        'tool_calls' => [['id' => 'call_stale', 'name' => 'generate_post', 'arguments' => ['prompt' => 'hello']]],
        'tool_results' => [['id' => 'call_stale', 'result' => $stored]],
    ]);

    $message->forceFill(['created_at' => now()->subMinutes(17)])->saveQuietly();

    $payloads = app(ToolReplayer::class)->replay($conversation->fresh(['messages']));

    $data = data_get(json_decode($payloads['call_stale'], true), 'data');

    expect(data_get($data, 'settled'))->toBeTrue()
        ->and(data_get($data, 'post'))->toBeNull()
        ->and(data_get($data, 'creation_id'))->toBe('call_stale');
});

test('a generation still inside the generation window is left in flight', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    $stored = json_encode(['data' => ['creation_id' => 'call_recent', 'channel' => "user.{$user->id}.ai-creation.call_recent"]]);

    $message = WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Generating it now.',
        'tool_calls' => [['id' => 'call_recent', 'name' => 'generate_post', 'arguments' => ['prompt' => 'hello']]],
        'tool_results' => [['id' => 'call_recent', 'result' => $stored]],
    ]);

    $message->forceFill(['created_at' => now()->subMinutes(15)])->saveQuietly();

    $payloads = app(ToolReplayer::class)->replay($conversation->fresh(['messages']));

    expect($payloads['call_recent'])->toBe($stored);
});

test('an outlived generation that did produce a post resolves it rather than settling', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    $post = Post::factory()->for($workspace)->create([
        'content' => 'Generated days ago',
        'creation_id' => 'call_old_success',
    ]);

    $stored = json_encode(['data' => ['creation_id' => 'call_old_success', 'channel' => "user.{$user->id}.ai-creation.call_old_success"]]);

    $message = WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Generating it now.',
        'tool_calls' => [['id' => 'call_old_success', 'name' => 'generate_post', 'arguments' => ['prompt' => 'hello']]],
        'tool_results' => [['id' => 'call_old_success', 'result' => $stored]],
    ]);

    $message->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();

    $payloads = app(ToolReplayer::class)->replay($conversation->fresh(['messages']));

    $data = data_get(json_decode($payloads['call_old_success'], true), 'data');

    expect(data_get($data, 'post.id'))->toBe($post->id)
        ->and(data_get($data, 'settled'))->toBeNull();
});

test('a generation whose turn has no timestamp is never declared settled', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    $stored = json_encode(['data' => ['creation_id' => 'call_undated', 'channel' => "user.{$user->id}.ai-creation.call_undated"]]);

    $message = WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Generating it now.',
        'tool_calls' => [['id' => 'call_undated', 'name' => 'generate_post', 'arguments' => ['prompt' => 'hello']]],
        'tool_results' => [['id' => 'call_undated', 'result' => $stored]],
    ]);

    $message->forceFill(['created_at' => null])->saveQuietly();

    $payloads = app(ToolReplayer::class)->replay($conversation->fresh(['messages']));

    expect($payloads['call_undated'])->toBe($stored);
});

test('a generation exactly at the window boundary is still treated as in flight', function () {
    // Whole-second instant on purpose. now() carries microseconds but the
    // stored created_at is truncated to seconds, so at any other instant
    // "exactly 16 minutes ago" reads back as fractionally MORE than 16 minutes
    // and the boundary can never be observed. Frozen here so the comparison is
    // a true equality, which is what pins hasOutlivedGenerationWindow()'s
    // strict lt(): swap it for lte() and this test fails, which is the point.
    $this->travelTo(CarbonImmutable::parse('2026-08-19 12:00:00'));

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    $stored = json_encode(['data' => ['creation_id' => 'call_boundary', 'channel' => "user.{$user->id}.ai-creation.call_boundary"]]);

    $message = WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Generating it now.',
        'tool_calls' => [['id' => 'call_boundary', 'name' => 'generate_post', 'arguments' => ['prompt' => 'hello']]],
        'tool_results' => [['id' => 'call_boundary', 'result' => $stored]],
    ]);

    $message->forceFill(['created_at' => now()->subMinutes(16)])->saveQuietly();

    $payloads = app(ToolReplayer::class)->replay($conversation->fresh(['messages']));

    expect($payloads['call_boundary'])->toBe($stored);
});

test('a generation one second past the window boundary is settled', function () {
    $this->travelTo(CarbonImmutable::parse('2026-08-19 12:00:00'));

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    $stored = json_encode(['data' => ['creation_id' => 'call_past_boundary', 'channel' => "user.{$user->id}.ai-creation.call_past_boundary"]]);

    $message = WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Generating it now.',
        'tool_calls' => [['id' => 'call_past_boundary', 'name' => 'generate_post', 'arguments' => ['prompt' => 'hello']]],
        'tool_results' => [['id' => 'call_past_boundary', 'result' => $stored]],
    ]);

    $message->forceFill(['created_at' => now()->subMinutes(16)->subSecond()])->saveQuietly();

    $payloads = app(ToolReplayer::class)->replay($conversation->fresh(['messages']));

    expect(data_get(json_decode($payloads['call_past_boundary'], true), 'data.settled'))->toBeTrue();
});

test('a generation card the conversation already acted on replays as spent', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    SocialAccount::factory()->for($workspace)->x()->create(['display_name' => 'Acme X']);

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Pick a format.',
        'tool_calls' => [['id' => 'call_start', 'name' => 'start_post_generation', 'arguments' => ['topic' => 'the pricing launch']]],
        'tool_results' => [['id' => 'call_start', 'result' => '{"data":{"formats":[],"styles":[],"applies_brand_visuals_default":true}}']],
    ]);

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Generating it.',
        'tool_calls' => [['id' => 'call_generate', 'name' => 'generate_post', 'arguments' => []]],
        'tool_results' => [['id' => 'call_generate', 'result' => '{"data":{"creation_id":"call_generate","channel":"c"}}']],
    ]);

    $payloads = app(ToolReplayer::class)->replay($conversation);

    expect(data_get(json_decode($payloads['call_start'], true), 'data.spent'))->toBeTrue();
});

test('a generation card still awaiting its choices replays interactive', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    SocialAccount::factory()->for($workspace)->x()->create(['display_name' => 'Acme X']);

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Pick a format.',
        'tool_calls' => [['id' => 'call_start', 'name' => 'start_post_generation', 'arguments' => ['topic' => 'the pricing launch']]],
        'tool_results' => [['id' => 'call_start', 'result' => '{"data":{"formats":[],"styles":[],"applies_brand_visuals_default":true}}']],
    ]);

    $payloads = app(ToolReplayer::class)->replay($conversation);

    expect(data_get(json_decode($payloads['call_start'], true), 'data.spent'))->toBeNull();
});

test('a second generation card offered after the last generation stays interactive', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    SocialAccount::factory()->for($workspace)->x()->create(['display_name' => 'Acme X']);

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Pick a format.',
        'tool_calls' => [['id' => 'call_start_one', 'name' => 'start_post_generation', 'arguments' => ['topic' => 'the pricing launch']]],
        'tool_results' => [['id' => 'call_start_one', 'result' => '{"data":{"formats":[],"styles":[],"applies_brand_visuals_default":true}}']],
    ]);

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Generating it.',
        'tool_calls' => [['id' => 'call_generate', 'name' => 'generate_post', 'arguments' => []]],
        'tool_results' => [['id' => 'call_generate', 'result' => '{"data":{"creation_id":"call_generate","channel":"c"}}']],
    ]);

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Want another one?',
        'tool_calls' => [['id' => 'call_start_two', 'name' => 'start_post_generation', 'arguments' => ['topic' => 'the pricing launch']]],
        'tool_results' => [['id' => 'call_start_two', 'result' => '{"data":{"formats":[],"styles":[],"applies_brand_visuals_default":true}}']],
    ]);

    $payloads = app(ToolReplayer::class)->replay($conversation);

    expect(data_get(json_decode($payloads['call_start_one'], true), 'data.spent'))->toBeTrue()
        ->and(data_get(json_decode($payloads['call_start_two'], true), 'data.spent'))->toBeNull();
});

test('a generation the tool refused leaves its card interactive', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    SocialAccount::factory()->for($workspace)->x()->create(['display_name' => 'Acme X']);

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Pick a format.',
        'tool_calls' => [['id' => 'call_start', 'name' => 'start_post_generation', 'arguments' => ['topic' => 'the pricing launch']]],
        'tool_results' => [['id' => 'call_start', 'result' => '{"data":{"formats":[],"styles":[],"applies_brand_visuals_default":true}}']],
    ]);

    // Refused for lack of credits: nothing was generated and nothing billed,
    // so the choices the user already made must stay resubmittable.
    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'I could not start it.',
        'tool_calls' => [['id' => 'call_generate', 'name' => 'generate_post', 'arguments' => []]],
        'tool_results' => [['id' => 'call_generate', 'result' => '{"error":"You have no AI credits left."}']],
    ]);

    $payloads = app(ToolReplayer::class)->replay($conversation);

    expect(data_get(json_decode($payloads['call_start'], true), 'data.spent'))->toBeNull();
});

test('a refusal after a real generation does not un-settle the card', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    SocialAccount::factory()->for($workspace)->x()->create(['display_name' => 'Acme X']);

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Pick a format.',
        'tool_calls' => [['id' => 'call_start', 'name' => 'start_post_generation', 'arguments' => ['topic' => 'the pricing launch']]],
        'tool_results' => [['id' => 'call_start', 'result' => '{"data":{"formats":[],"styles":[],"applies_brand_visuals_default":true}}']],
    ]);

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Generating it.',
        'tool_calls' => [['id' => 'call_generate', 'name' => 'generate_post', 'arguments' => []]],
        'tool_results' => [['id' => 'call_generate', 'result' => '{"data":{"creation_id":"call_generate","channel":"c"}}']],
    ]);

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'That second one I could not start.',
        'tool_calls' => [['id' => 'call_generate_refused', 'name' => 'generate_post', 'arguments' => []]],
        'tool_results' => [['id' => 'call_generate_refused', 'result' => '{"error":"You have no AI credits left."}']],
    ]);

    $payloads = app(ToolReplayer::class)->replay($conversation);

    expect(data_get(json_decode($payloads['call_start'], true), 'data.spent'))->toBeTrue();
});

test('the resource exposes a stored turn parts in order with its tool payloads resolved', function () {
    [$user, $workspace] = actingAsWorkspaceUser();

    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => "Let me look.\n\nHere they are.",
        'parts' => [
            ['type' => 'text', 'text' => 'Let me look.'],
            ['type' => 'tool', 'id' => 'call_parts', 'name' => 'list_posts'],
            ['type' => 'text', 'text' => 'Here they are.'],
        ],
        'tool_calls' => [['id' => 'call_parts', 'name' => 'list_posts', 'arguments' => []]],
        'tool_results' => [['id' => 'call_parts', 'result' => '{"data":[]}']],
    ]);

    $response = $this->get(route('app.chat.show', $conversation));

    $message = data_get($response->viewData('page'), 'props.messages.0');

    expect(data_get($message, 'parts'))->toBe([
        ['type' => 'text', 'text' => 'Let me look.'],
        ['type' => 'tool', 'id' => 'call_parts', 'name' => 'list_posts'],
        ['type' => 'text', 'text' => 'Here they are.'],
    ])
        ->and(json_decode(data_get($message, 'payloads.call_parts'), true))->toHaveKey('data');
});

test('a turn stored before the parts column still exposes null parts', function () {
    [$user, $workspace] = actingAsWorkspaceUser();

    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Here they are.',
        'tool_calls' => [['id' => 'call_legacy', 'name' => 'list_posts', 'arguments' => []]],
        'tool_results' => [['id' => 'call_legacy', 'result' => '{"data":[]}']],
    ]);

    $response = $this->get(route('app.chat.show', $conversation));

    $message = data_get($response->viewData('page'), 'props.messages.0');

    expect($message)->toHaveKey('parts')
        ->and(data_get($message, 'parts'))->toBeNull()
        ->and(data_get($message, 'tool_calls.0.id'))->toBe('call_legacy');
});
