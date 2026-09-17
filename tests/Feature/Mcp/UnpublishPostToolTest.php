<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\UnpublishPostTool;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->socialAccount = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $this->workspace->id,
    ]);
});

test('reports the platform as unsupported, since TikTok has no delete/unpublish endpoint, and leaves the post untouched', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Published,
    ]);
    $platform = PostPlatform::factory()->tiktok()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UnpublishPostTool::class, ['post_id' => $post->id]);

    $response->assertOk()
        ->assertStructuredContent([
            'post_id' => $post->id,
            'unpublished' => [],
            'failed' => [],
            'unsupported_platforms' => [
                ['post_platform_id' => $platform->id, 'platform' => Platform::TikTok->value],
            ],
        ]);

    expect($post->fresh()->status)->toBe(PostStatus::Published)
        ->and($platform->fresh()->status)->toBe(PostPlatformStatus::Published)
        ->and($platform->fresh()->platform_post_id)->not->toBeNull();
});

test('a platform without a platform_post_id is not a candidate at all', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UnpublishPostTool::class, ['post_id' => $post->id]);

    $response->assertOk()
        ->assertStructuredContent([
            'post_id' => $post->id,
            'unpublished' => [],
            'failed' => [],
            'unsupported_platforms' => [],
        ]);
});

test('post_platform_ids narrows the attempt to the given platforms only', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Published,
    ]);
    $target = PostPlatform::factory()->tiktok()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);
    $otherAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Bluesky,
    ]);
    $untouched = PostPlatform::factory()->published()->bluesky()->create([
        'post_id' => $post->id,
        'social_account_id' => $otherAccount->id,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UnpublishPostTool::class, [
            'post_id' => $post->id,
            'post_platform_ids' => [$target->id],
        ]);

    $response->assertOk()
        ->assertStructuredContent([
            'post_id' => $post->id,
            'unpublished' => [],
            'failed' => [],
            'unsupported_platforms' => [
                ['post_platform_id' => $target->id, 'platform' => Platform::TikTok->value],
            ],
        ]);

    expect($untouched->fresh()->platform_post_id)->not->toBeNull();
});

test('rejects a post from another workspace with post not found', function () {
    $otherWorkspace = Workspace::factory()->create();
    $post = Post::factory()->create(['workspace_id' => $otherWorkspace->id, 'user_id' => $this->user->id]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UnpublishPostTool::class, ['post_id' => $post->id]);

    $response->assertHasErrors(['Post not found.']);
});

test('rejects a post_platform_ids value belonging to another post', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Published,
    ]);
    $ownPlatform = PostPlatform::factory()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $otherPost = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Published,
    ]);
    $foreignPlatform = PostPlatform::factory()->published()->create([
        'post_id' => $otherPost->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UnpublishPostTool::class, [
            'post_id' => $post->id,
            'post_platform_ids' => [$foreignPlatform->id],
        ]);

    $response->assertHasErrors();
    expect($ownPlatform->fresh()->status)->toBe(PostPlatformStatus::Published)
        ->and($foreignPlatform->fresh()->status)->toBe(PostPlatformStatus::Published);
});

test('rejects a malformed post_id with a clean validation error instead of throwing', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(UnpublishPostTool::class, ['post_id' => 'not-a-uuid']);

    $response->assertHasErrors();
});

test('rejects a non-scalar post_id with a clean validation error instead of throwing', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(UnpublishPostTool::class, ['post_id' => [$post->id]]);

    $response->assertHasErrors();
});

test('validates post_id required', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(UnpublishPostTool::class, []);

    $response->assertHasErrors();
});

test('viewers cannot unpublish posts via mcp', function () {
    $viewer = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value]);
    $viewer->update(['current_workspace_id' => $this->workspace->id]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::Published,
    ]);
    $platform = PostPlatform::factory()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $this->socialAccount->id,
    ]);

    TryPostServer::actingAs($viewer)
        ->tool(UnpublishPostTool::class, ['post_id' => $post->id])
        ->assertHasErrors(['Not authorized to update this post.']);

    expect($platform->fresh()->status)->toBe(PostPlatformStatus::Published);
});
