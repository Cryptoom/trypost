<?php

declare(strict_types=1);

use App\Ai\Tools\Post\DeletePostTool;
use App\Ai\Tools\Post\PublishPostTool;
use App\Enums\Post\Status;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\User;
use App\Models\Workspace;
use App\Support\PostStatusRules;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Tools\Request;

test('deleting a draft does not need approval and deletes it', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $draft = Post::factory()->for($workspace)->create(['status' => Status::Draft]);

    $tool = new DeletePostTool($workspace, $user);
    $request = new Request(['post_id' => $draft->id]);

    expect($tool->shouldRequestApproval($request))->toBeNull();

    $output = json_decode($tool->handle($request), true);

    expect($output)->toBe(['data' => ['id' => $draft->id, 'deleted' => true]]);
    $this->assertDatabaseMissing('posts', ['id' => $draft->id]);
});

test('deleting a published post needs no approval and refuses immediately', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $published = Post::factory()->for($workspace)->create(['status' => Status::Published]);

    $tool = new DeletePostTool($workspace, $user);
    $request = new Request(['post_id' => $published->id]);

    expect($tool->shouldRequestApproval($request))->toBeNull();

    $output = json_decode($tool->handle($request), true);

    expect($output)->toBe(['error' => __('chat.tools.delete_blocked')]);
    $this->assertDatabaseHas('posts', ['id' => $published->id]);
});

test('deleting a scheduled post needs approval and deletes once approved', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $scheduled = Post::factory()->for($workspace)->create([
        'status' => Status::Scheduled,
        'scheduled_at' => now()->addDay(),
    ]);

    $tool = new DeletePostTool($workspace, $user);
    $request = new Request(['post_id' => $scheduled->id]);

    expect($tool->shouldRequestApproval($request))->toBeInstanceOf(Approval::class);

    $output = json_decode($tool->handle($request), true);

    expect($output)->toBe(['data' => ['id' => $scheduled->id, 'deleted' => true]]);
    $this->assertDatabaseMissing('posts', ['id' => $scheduled->id]);
});

test('publishing a ready draft needs approval', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $post = Post::factory()->for($workspace)
        ->has(PostPlatform::factory())
        ->create(['status' => Status::Draft]);

    $approval = (new PublishPostTool($workspace, $user))
        ->shouldRequestApproval(new Request(['post_id' => $post->id]));

    expect($approval)->toBeInstanceOf(Approval::class);
});

test('publishing an already-finalized post needs no approval and refuses immediately', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $published = Post::factory()->for($workspace)->create(['status' => Status::Published]);

    $tool = new PublishPostTool($workspace, $user);
    $request = new Request(['post_id' => $published->id]);

    expect($tool->shouldRequestApproval($request))->toBeNull();

    $output = json_decode($tool->handle($request), true);

    expect($output)->toBe(['error' => PostStatusRules::editBlockedMessage()]);
});

test('publishing a post with no enabled platforms needs no approval and refuses immediately', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $post = Post::factory()->for($workspace)->create(['status' => Status::Draft]);

    $tool = new PublishPostTool($workspace, $user);
    $request = new Request(['post_id' => $post->id]);

    expect($tool->shouldRequestApproval($request))->toBeNull();

    $output = json_decode($tool->handle($request), true);

    expect($output)->toBe(['error' => __('chat.tools.publish_no_enabled_platforms')]);
});
