<?php

declare(strict_types=1);

use App\Ai\Tools\Post\CreatePostTool;
use App\Ai\Tools\Post\SchedulePostTool;
use App\Ai\Tools\Post\UpdatePostTool;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\Status;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostStatusRules;
use Illuminate\Support\Facades\Validator;
use Laravel\Ai\Tools\Request;

test('create_post creates a draft in the tool workspace', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();

    $output = json_decode((new CreatePostTool($workspace, $user))->handle(
        new Request(['content' => 'A new draft'])
    ), true);

    $post = Post::find($output['data']['id']);

    expect($post)->not->toBeNull()
        ->and($post->workspace_id)->toBe($workspace->id)
        ->and($post->status)->toBe(Status::Draft)
        ->and($post->content)->toBe('A new draft')
        ->and($post->created_via)->toBe(CreatedVia::Chat);
});

test('update_post updates content on a post in the tool workspace', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $post = Post::factory()->for($workspace)->create(['content' => 'Before']);

    $output = json_decode((new UpdatePostTool($workspace, $user))->handle(
        new Request(['post_id' => $post->id, 'content' => 'After'])
    ), true);

    expect($output['data']['id'])->toBe($post->id)
        ->and($post->fresh()->content)->toBe('After');
});

test('update_post leaves content untouched when no content argument is given', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $post = Post::factory()->for($workspace)->create(['content' => 'Unchanged']);

    (new UpdatePostTool($workspace, $user))->handle(
        new Request(['post_id' => $post->id])
    );

    expect($post->fresh()->content)->toBe('Unchanged');
});

test('update_post refuses a post from another workspace', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $foreign = Post::factory()->for(Workspace::factory())->create(['content' => 'Untouched']);

    $output = (new UpdatePostTool($workspace, $user))->handle(
        new Request(['post_id' => $foreign->id, 'content' => 'Hacked'])
    );

    expect($output)->toContain('error')
        ->and($foreign->fresh()->content)->toBe('Untouched');
});

test('update_post refuses to edit an already published post', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $post = Post::factory()->for($workspace)->create([
        'status' => Status::Published,
        'content' => 'Already live',
    ]);

    $output = json_decode((new UpdatePostTool($workspace, $user))->handle(
        new Request(['post_id' => $post->id, 'content' => 'Hacked'])
    ), true);

    expect($output['error'])->toBe(PostStatusRules::editBlockedMessage())
        ->and($post->fresh()->content)->toBe('Already live');
});

test('schedule_post sets the scheduled date and status', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $post = Post::factory()->for($workspace)->create(['status' => Status::Draft]);

    (new SchedulePostTool($workspace, $user))->handle(
        new Request(['post_id' => $post->id, 'scheduled_at' => '2026-09-01T10:00:00+00:00'])
    );

    expect($post->fresh()->status)->toBe(Status::Scheduled)
        ->and($post->fresh()->scheduled_at->toIso8601String())->toBe('2026-09-01T10:00:00+00:00');
});

test('schedule_post rejects a missing scheduled_at with an actionable, non-generic message', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $post = Post::factory()->for($workspace)->create(['status' => Status::Draft]);

    $output = json_decode((new SchedulePostTool($workspace, $user))->handle(
        new Request(['post_id' => $post->id])
    ), true);

    $expectedMessage = Validator::make(
        ['scheduled_at' => null],
        ['scheduled_at' => PostStatusRules::scheduledAtRules($post, Status::Scheduled->value)],
    )->errors()->first('scheduled_at');

    expect($output['error'])->toBe($expectedMessage)
        ->and($output['error'])->not->toBe(__('chat.tools.error'))
        ->and($post->fresh()->status)->toBe(Status::Draft);
});

test('schedule_post rejects a past scheduled_at with an actionable, non-generic message', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $post = Post::factory()->for($workspace)->create(['status' => Status::Draft]);

    $pastDate = now()->subDay()->toIso8601String();

    $output = json_decode((new SchedulePostTool($workspace, $user))->handle(
        new Request(['post_id' => $post->id, 'scheduled_at' => $pastDate])
    ), true);

    $expectedMessage = Validator::make(
        ['scheduled_at' => $pastDate],
        ['scheduled_at' => PostStatusRules::scheduledAtRules($post, Status::Scheduled->value)],
    )->errors()->first('scheduled_at');

    expect($output['error'])->toBe($expectedMessage)
        ->and($output['error'])->not->toBe(__('chat.tools.error'))
        ->and($post->fresh()->status)->toBe(Status::Draft);
});

test('schedule_post refuses to schedule an already published post', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $post = Post::factory()->for($workspace)->create([
        'status' => Status::Published,
        'scheduled_at' => null,
    ]);

    $output = json_decode((new SchedulePostTool($workspace, $user))->handle(
        new Request(['post_id' => $post->id, 'scheduled_at' => now()->addDay()->toIso8601String()])
    ), true);

    expect($output['error'])->toBe(PostStatusRules::editBlockedMessage())
        ->and($post->fresh()->status)->toBe(Status::Published);
});

test('schedule_post refuses a post from another workspace', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $foreign = Post::factory()->for(Workspace::factory())->create(['status' => Status::Draft]);

    $output = (new SchedulePostTool($workspace, $user))->handle(
        new Request(['post_id' => $foreign->id, 'scheduled_at' => '2026-09-01T10:00:00+00:00'])
    );

    expect($output)->toContain('error')
        ->and($foreign->fresh()->status)->toBe(Status::Draft);
});
