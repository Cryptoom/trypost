<?php

declare(strict_types=1);

use App\Actions\Post\UnpublishPost;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\FacebookPublisher;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
});

/**
 * A published row on a platform whose publisher has no delete() method
 * (TikTok, which has no delete/unpublish endpoint at all, see CLAUDE.md)
 * resolves to `unsupported`, not `failed`.
 */
test('a published platform without a delete-capable publisher lands in unsupported', function () {
    $post = Post::factory()->published()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $account = SocialAccount::factory()->tiktok()->create(['workspace_id' => $this->workspace->id]);
    $postPlatform = PostPlatform::factory()->tiktok()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);

    $result = UnpublishPost::execute($post);

    expect($result['unpublished'])->toBe([])
        ->and($result['failed'])->toBe([])
        ->and($result['unsupported'])->toHaveCount(1)
        ->and($result['unsupported'][0]->id)->toBe($postPlatform->id);

    expect($postPlatform->fresh()->status)->toBe(PostPlatformStatus::Published)
        ->and($postPlatform->fresh()->platform_post_id)->not->toBeNull();
});

/**
 * The negative test the plan asks for: a post that only has a TikTok
 * platform (TikTok has no delete/unpublish endpoint at all). Unpublish
 * must not error, and the post's status must stay exactly as it was.
 */
test('a TikTok-only post stays untouched (no delete endpoint exists)', function () {
    $post = Post::factory()->published()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $account = SocialAccount::factory()->tiktok()->create(['workspace_id' => $this->workspace->id]);
    $postPlatform = PostPlatform::factory()->tiktok()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);

    $result = UnpublishPost::execute($post);

    expect($result['unsupported'])->toHaveCount(1)
        ->and($result['unpublished'])->toBe([])
        ->and($result['failed'])->toBe([]);

    expect($post->fresh()->status)->toBe(PostStatus::Published);
    expect($postPlatform->fresh()->status)->toBe(PostPlatformStatus::Published);
});

test('rows without a platform_post_id are not candidates', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);
    PostPlatform::factory()->linkedin()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        // status: Pending, no platform_post_id (default factory state)
    ]);

    $result = UnpublishPost::execute($post);

    expect($result['unpublished'])->toBe([])
        ->and($result['failed'])->toBe([])
        ->and($result['unsupported'])->toBe([]);
});

test('postPlatformIds narrows the candidates to the given subset', function () {
    $post = Post::factory()->published()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $tiktokAccount = SocialAccount::factory()->tiktok()->create(['workspace_id' => $this->workspace->id]);
    $xAccount = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);

    $tiktokRow = PostPlatform::factory()->tiktok()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $tiktokAccount->id,
    ]);
    PostPlatform::factory()->x()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $xAccount->id,
    ]);

    $result = UnpublishPost::execute($post, [$tiktokRow->id]);

    expect($result['unsupported'])->toHaveCount(1)
        ->and($result['unsupported'][0]->id)->toBe($tiktokRow->id);
});

/**
 * Simulates a publisher that CAN delete remotely, using a test double bound
 * into the container (App\Services\Social\FacebookPublisher extended with a
 * delete() method it doesn't have today). This exercises the success path
 * of the real dispatch end-to-end, which is otherwise unreachable until
 * B2a-d add real delete() methods.
 */
test('a platform whose publisher supports delete() is fully reset on success', function () {
    app()->instance(FacebookPublisher::class, new class extends FacebookPublisher
    {
        public int $calls = 0;

        public function delete(PostPlatform $postPlatform): void
        {
            $this->calls++;
        }
    });

    $post = Post::factory()->published()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $account = SocialAccount::factory()->facebook()->create(['workspace_id' => $this->workspace->id]);
    $postPlatform = PostPlatform::factory()->facebook()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);

    $result = UnpublishPost::execute($post);

    expect($result['unpublished'])->toHaveCount(1)
        ->and($result['unpublished'][0]->id)->toBe($postPlatform->id)
        ->and($result['failed'])->toBe([])
        ->and($result['unsupported'])->toBe([]);

    $fresh = $postPlatform->fresh();
    expect($fresh->status)->toBe(PostPlatformStatus::Pending)
        ->and($fresh->platform_post_id)->toBeNull()
        ->and($fresh->platform_url)->toBeNull()
        ->and($fresh->published_at)->toBeNull()
        ->and($fresh->error_message)->toBeNull()
        ->and($fresh->error_context)->toBeNull();

    $freshPost = $post->fresh();
    expect($freshPost->status)->toBe(PostStatus::Draft)
        ->and($freshPost->published_at)->toBeNull();
});

test('the post becomes PartiallyPublished when only some candidates unpublish', function () {
    app()->instance(FacebookPublisher::class, new class extends FacebookPublisher
    {
        public function delete(PostPlatform $postPlatform): void {}
    });

    $post = Post::factory()->published()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $facebookAccount = SocialAccount::factory()->facebook()->create(['workspace_id' => $this->workspace->id]);
    $tiktokAccount = SocialAccount::factory()->tiktok()->create(['workspace_id' => $this->workspace->id]);

    PostPlatform::factory()->facebook()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $facebookAccount->id,
    ]);
    PostPlatform::factory()->tiktok()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $tiktokAccount->id,
    ]);

    $result = UnpublishPost::execute($post);

    expect($result['unpublished'])->toHaveCount(1)
        ->and($result['unsupported'])->toHaveCount(1);

    expect($post->fresh()->status)->toBe(PostStatus::PartiallyPublished);
});

test('a delete() failure is collected without touching the row, and does not block other platforms', function () {
    app()->instance(FacebookPublisher::class, new class extends FacebookPublisher
    {
        public function delete(PostPlatform $postPlatform): void
        {
            throw new RuntimeException('platform rejected the delete');
        }
    });

    $post = Post::factory()->published()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $facebookAccount = SocialAccount::factory()->facebook()->create(['workspace_id' => $this->workspace->id]);
    $tiktokAccount = SocialAccount::factory()->tiktok()->create(['workspace_id' => $this->workspace->id]);

    $facebookRow = PostPlatform::factory()->facebook()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $facebookAccount->id,
    ]);
    PostPlatform::factory()->tiktok()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $tiktokAccount->id,
    ]);

    $result = UnpublishPost::execute($post);

    expect($result['unpublished'])->toBe([])
        ->and($result['failed'])->toHaveCount(1)
        ->and($result['failed'][0]['post_platform']->id)->toBe($facebookRow->id)
        ->and($result['failed'][0]['message'])->toBe('platform rejected the delete')
        ->and($result['unsupported'])->toHaveCount(1);

    expect($facebookRow->fresh()->status)->toBe(PostPlatformStatus::Published)
        ->and($facebookRow->fresh()->platform_post_id)->not->toBeNull();

    // Nothing unpublished, so the post's status stays exactly as it was.
    expect($post->fresh()->status)->toBe(PostStatus::Published);
});
