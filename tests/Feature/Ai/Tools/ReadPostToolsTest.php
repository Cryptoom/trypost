<?php

declare(strict_types=1);

use App\Ai\Tools\Post\GetPostTool;
use App\Ai\Tools\Post\ListPostsTool;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Ai\Tools\Request;

test('list_posts only returns posts from the tool workspace', function () {
    $workspace = Workspace::factory()->create();
    $otherWorkspace = Workspace::factory()->create();
    $user = User::factory()->create();

    $mine = Post::factory()->for($workspace)->create(['content' => 'Mine']);
    Post::factory()->for($otherWorkspace)->create(['content' => 'Theirs']);

    $output = json_decode((new ListPostsTool($workspace, $user))->handle(new Request([])), true);

    expect($output['data'])->toHaveCount(1)
        ->and($output['data'][0]['id'])->toBe($mine->id);
});

test('get_post refuses a post from another workspace with an error string, not an exception', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $foreign = Post::factory()->for(Workspace::factory())->create();

    $output = (new GetPostTool($workspace, $user))->handle(new Request(['post_id' => $foreign->id]));

    expect($output)->toContain('error');
});

test('a tool that throws returns an error string instead of bubbling', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();

    $output = (new GetPostTool($workspace, $user))->handle(new Request(['post_id' => 'not-a-uuid']));

    expect($output)->toContain('error');
});
