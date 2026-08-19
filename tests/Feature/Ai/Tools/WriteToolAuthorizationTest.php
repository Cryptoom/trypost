<?php

declare(strict_types=1);

use App\Ai\Agents\WorkspaceConversationAgent;
use App\Ai\Tools\Post\CreatePostTool;
use App\Ai\Tools\Post\DeletePostTool;
use App\Ai\Tools\Post\GeneratePostTool;
use App\Ai\Tools\Post\GetPostMetricsTool;
use App\Ai\Tools\Post\GetPostTool;
use App\Ai\Tools\Post\ListPostsTool;
use App\Ai\Tools\Post\PublishPostTool;
use App\Ai\Tools\Post\SchedulePostTool;
use App\Ai\Tools\Post\StartPostGenerationTool;
use App\Ai\Tools\Post\UpdatePostTool;
use App\Ai\Tools\WorkspaceWriteTool;
use App\Enums\Post\Status;
use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Jobs\Ai\StreamPostCreation;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Bus;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

test('create_post refuses a viewer and creates nothing', function () {
    [$user, $workspace] = workspaceUserWithRole(Role::Viewer);

    $output = json_decode((new CreatePostTool($workspace, $user))->handle(
        new Request(['content' => 'A draft a viewer should not be able to create'])
    ), true);

    expect($output)->toBe(['error' => __('chat.tools.forbidden')])
        ->and(Post::count())->toBe(0);
});

test('update_post refuses a viewer and leaves the post unchanged', function () {
    [$user, $workspace] = workspaceUserWithRole(Role::Viewer);
    $post = Post::factory()->for($workspace)->create(['content' => 'Untouched']);

    $output = json_decode((new UpdatePostTool($workspace, $user))->handle(
        new Request(['post_id' => $post->id, 'content' => 'Rewritten'])
    ), true);

    expect($output)->toBe(['error' => __('chat.tools.forbidden')])
        ->and($post->fresh()->content)->toBe('Untouched');
});

test('schedule_post refuses a viewer and leaves the post a draft', function () {
    [$user, $workspace] = workspaceUserWithRole(Role::Viewer);
    $post = Post::factory()->for($workspace)->create([
        'status' => Status::Draft,
        'scheduled_at' => null,
    ]);

    $output = json_decode((new SchedulePostTool($workspace, $user))->handle(
        new Request(['post_id' => $post->id, 'scheduled_at' => now()->addDay()->toIso8601String()])
    ), true);

    expect($output)->toBe(['error' => __('chat.tools.forbidden')])
        ->and($post->fresh()->status)->toBe(Status::Draft)
        ->and($post->fresh()->scheduled_at)->toBeNull();
});

test('publish_post refuses a viewer, never asks for approval, and leaves the post a draft', function () {
    [$user, $workspace] = workspaceUserWithRole(Role::Viewer);
    $post = Post::factory()->for($workspace)->create(['status' => Status::Draft]);
    $account = SocialAccount::factory()->for($workspace)->create();
    PostPlatform::factory()->for($post)->for($account)->create(['enabled' => true]);

    $tool = new PublishPostTool($workspace, $user);
    $request = new Request(['post_id' => $post->id]);

    expect($tool->shouldRequestApproval($request))->toBeNull();

    $output = json_decode($tool->handle($request), true);

    expect($output)->toBe(['error' => __('chat.tools.forbidden')])
        ->and($post->fresh()->status)->toBe(Status::Draft);
});

test('delete_post refuses a viewer, never asks for approval, and keeps the post', function () {
    [$user, $workspace] = workspaceUserWithRole(Role::Viewer);
    $scheduled = Post::factory()->for($workspace)->create([
        'status' => Status::Scheduled,
        'scheduled_at' => now()->addDay(),
    ]);

    $tool = new DeletePostTool($workspace, $user);
    $request = new Request(['post_id' => $scheduled->id]);

    expect($tool->shouldRequestApproval($request))->toBeNull();

    $output = json_decode($tool->handle($request), true);

    expect($output)->toBe(['error' => __('chat.tools.forbidden')]);
    $this->assertDatabaseHas('posts', ['id' => $scheduled->id, 'status' => Status::Scheduled->value]);
});

test('delete_post refuses a viewer even for a draft, which needs no approval to delete', function () {
    [$user, $workspace] = workspaceUserWithRole(Role::Viewer);
    $draft = Post::factory()->for($workspace)->create(['status' => Status::Draft]);

    $output = json_decode((new DeletePostTool($workspace, $user))->handle(
        new Request(['post_id' => $draft->id])
    ), true);

    expect($output)->toBe(['error' => __('chat.tools.forbidden')]);
    $this->assertDatabaseHas('posts', ['id' => $draft->id]);
});

test('generate_post refuses a viewer and dispatches no generation', function () {
    Bus::fake();

    [$user, $workspace] = workspaceUserWithRole(Role::Viewer);
    $account = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Threads]);

    $output = json_decode((new GeneratePostTool($workspace, $user))->handle(new Request([
        'prompt' => 'A post a viewer should not be able to generate',
        'format' => 'threads_post',
        'style' => 'image_card',
        'social_account_id' => $account->id,
    ])), true);

    expect($output)->toBe(['error' => __('chat.tools.forbidden')]);

    Bus::assertNotDispatched(StreamPostCreation::class);
});

test('read tools stay open to a viewer', function () {
    [$user, $workspace] = workspaceUserWithRole(Role::Viewer);
    $post = Post::factory()->for($workspace)->create(['content' => 'Readable']);

    $list = json_decode((new ListPostsTool($workspace, $user))->handle(new Request([])), true);
    $single = json_decode((new GetPostTool($workspace, $user))->handle(new Request(['post_id' => $post->id])), true);
    $metrics = json_decode((new GetPostMetricsTool($workspace, $user))->handle(new Request(['post_id' => $post->id])), true);

    expect($list['data'])->toHaveCount(1)
        ->and($single['data']['id'])->toBe($post->id)
        ->and($metrics)->toHaveKey('data');
});

test('a workspace admin may write', function () {
    [$user, $workspace] = workspaceUserWithRole(Role::Admin);

    $output = json_decode((new CreatePostTool($workspace, $user))->handle(
        new Request(['content' => 'An admin draft'])
    ), true);

    expect($output)->toHaveKey('data')
        ->and(Post::count())->toBe(1);
});

test('every mutating tool the agent exposes extends WorkspaceWriteTool', function () {
    $readOnly = [
        ListPostsTool::class,
        GetPostTool::class,
        GetPostMetricsTool::class,
        StartPostGenerationTool::class,
    ];

    [$user, $workspace] = workspaceUserWithRole(Role::Member);
    $agent = new WorkspaceConversationAgent($workspace, $user);

    $gated = collect($agent->tools())
        ->reject(fn (Tool $tool): bool => in_array($tool::class, $readOnly, true))
        ->every(fn (Tool $tool): bool => $tool instanceof WorkspaceWriteTool);

    expect($gated)->toBeTrue()
        ->and(collect($agent->tools())->count())->toBe(10);
});
