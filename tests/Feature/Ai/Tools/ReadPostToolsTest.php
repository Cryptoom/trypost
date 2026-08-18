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

test('a tool that throws returns a generic error string instead of leaking database internals', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();

    $output = (new GetPostTool($workspace, $user))->handle(new Request(['post_id' => 'not-a-uuid']));
    $decoded = json_decode($output, true);

    expect($decoded['error'])->toBe(__('chat.tools.error'))
        ->and($output)->not->toContain('select')
        ->and($output)->not->toContain('pgsql')
        ->and($output)->not->toContain('posts')
        ->and($output)->not->toContain((string) config('database.connections.pgsql.host'));
});

test('list_posts clamps an out-of-range limit instead of trusting the schema', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();

    Post::factory()->for($workspace)->count(30)->create();

    $output = json_decode((new ListPostsTool($workspace, $user))->handle(new Request(['limit' => 999999])), true);

    expect($output['data'])->toHaveCount(25);
});
