<?php

declare(strict_types=1);

use App\Actions\Post\DeletePost;
use App\Events\PostDeleted;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\FacebookPublisher;
use Illuminate\Support\Facades\Event;

test('execute deletes the post', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    DeletePost::execute($post);

    expect(Post::find($post->id))->toBeNull();
});

test('execute dispatches PostDeleted with the post and workspace ids', function () {
    Event::fake([PostDeleted::class]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    $postId = $post->id;
    $workspaceId = $post->workspace_id;

    DeletePost::execute($post);

    Event::assertDispatched(
        PostDeleted::class,
        fn (PostDeleted $event) => $event->postId === $postId
            && $event->workspaceId === $workspaceId,
    );
});

test('execute unpublishes published platforms before deleting the post', function () {
    app()->instance(FacebookPublisher::class, new class extends FacebookPublisher
    {
        public int $calls = 0;

        public function delete(PostPlatform $postPlatform): void
        {
            $this->calls++;
        }
    });

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $post = Post::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);
    $account = SocialAccount::factory()->facebook()->create(['workspace_id' => $workspace->id]);
    PostPlatform::factory()->facebook()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);

    DeletePost::execute($post);

    expect(app(FacebookPublisher::class)->calls)->toBe(1);
    expect(Post::find($post->id))->toBeNull();
});

test('execute still deletes the post when unpublishing a platform fails', function () {
    app()->instance(FacebookPublisher::class, new class extends FacebookPublisher
    {
        public function delete(PostPlatform $postPlatform): void
        {
            throw new RuntimeException('platform rejected the delete');
        }
    });

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $post = Post::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);
    $account = SocialAccount::factory()->facebook()->create(['workspace_id' => $workspace->id]);
    PostPlatform::factory()->facebook()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);

    DeletePost::execute($post);

    expect(Post::find($post->id))->toBeNull();
});

/**
 * The negative test the plan asks for: a post that only has a TikTok
 * platform (no delete/unpublish endpoint exists at all) is still deleted
 * locally, even though the remote platform can never be unpublished.
 */
test('execute still deletes a post whose only platform is unsupported (TikTok)', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $post = Post::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);
    $account = SocialAccount::factory()->tiktok()->create(['workspace_id' => $workspace->id]);
    PostPlatform::factory()->tiktok()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);

    DeletePost::execute($post);

    expect(Post::find($post->id))->toBeNull();
});
