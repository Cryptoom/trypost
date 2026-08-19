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

test('get_post with an absent post_id returns post_not_found, not the generic error', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();

    $output = json_decode((new GetPostTool($workspace, $user))->handle(new Request([])), true);

    expect($output['error'])->toBe(__('chat.tools.post_not_found'));
});

test('get_post carries the full content while list_posts carries a flagged preview', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    $content = str_repeat('a', 900);
    $post = Post::factory()->for($workspace)->create(['content' => $content]);

    $single = json_decode((new GetPostTool($workspace, $user))->handle(
        new Request(['post_id' => $post->id])
    ), true);

    $list = json_decode((new ListPostsTool($workspace, $user))->handle(new Request([])), true);

    expect($single['data']['content'])->toBe($content)
        ->and($single['data']['content_truncated'])->toBeFalse()
        ->and($list['data'][0]['content'])->not->toBe($content)
        ->and(mb_strlen($list['data'][0]['content']))->toBeLessThan(mb_strlen($content))
        ->and($list['data'][0]['content_truncated'])->toBeTrue();
});

test('a short post is never flagged as truncated in a list', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();
    Post::factory()->for($workspace)->create(['content' => 'Short enough']);

    $list = json_decode((new ListPostsTool($workspace, $user))->handle(new Request([])), true);

    expect($list['data'][0]['content'])->toBe('Short enough')
        ->and($list['data'][0]['content_truncated'])->toBeFalse();
});
