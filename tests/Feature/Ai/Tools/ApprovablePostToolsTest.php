<?php

declare(strict_types=1);

use App\Ai\Tools\Post\DeletePostTool;
use App\Ai\Tools\Post\PublishPostTool;
use App\Enums\Post\Status;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Tools\Request;

test('deleting a draft does not need approval', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $draft = Post::factory()->for($workspace)->create(['status' => Status::Draft]);

    $approval = (new DeletePostTool($workspace, $user))
        ->shouldRequestApproval(new Request(['post_id' => $draft->id]));

    expect($approval)->toBeNull();
});

test('deleting a published post needs approval', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $published = Post::factory()->for($workspace)->create(['status' => Status::Published]);

    $approval = (new DeletePostTool($workspace, $user))
        ->shouldRequestApproval(new Request(['post_id' => $published->id]));

    expect($approval)->toBeInstanceOf(Approval::class);
});

test('publishing always needs approval', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $post = Post::factory()->for($workspace)->create(['status' => Status::Draft]);

    $approval = (new PublishPostTool($workspace, $user))
        ->shouldRequestApproval(new Request(['post_id' => $post->id]));

    expect($approval)->toBeInstanceOf(Approval::class);
});
