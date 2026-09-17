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
use App\Services\Social\InstagramPublisher;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
});

/**
 * A published row on a platform whose publisher has no delete() method
 * (every publisher today) resolves to `unsupported`, not `failed`.
 */
test('a published platform without a delete-capable publisher lands in unsupported', function () {
    $post = Post::factory()->published()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);
    $postPlatform = PostPlatform::factory()->linkedin()->published()->create([
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
    $linkedinAccount = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);
    $xAccount = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);

    $linkedinRow = PostPlatform::factory()->linkedin()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $linkedinAccount->id,
    ]);
    PostPlatform::factory()->x()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $xAccount->id,
    ]);

    $result = UnpublishPost::execute($post, [$linkedinRow->id]);

    expect($result['unsupported'])->toHaveCount(1)
        ->and($result['unsupported'][0]->id)->toBe($linkedinRow->id);
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
    $linkedinAccount = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);

    PostPlatform::factory()->facebook()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $facebookAccount->id,
    ]);
    PostPlatform::factory()->linkedin()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $linkedinAccount->id,
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
    $linkedinAccount = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);

    $facebookRow = PostPlatform::factory()->facebook()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $facebookAccount->id,
    ]);
    PostPlatform::factory()->linkedin()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $linkedinAccount->id,
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

/**
 * The B1-review Pflicht-Nacharbeit fix: a partial unpublish must leave the
 * post's ORIGINAL published_at untouched. Post::markAsPartiallyPublished()
 * stamps `published_at = now()`, which is correct when a platform finishes
 * publishing but wrong here, at least one platform is still live from its
 * original publish.
 */
test('published_at is left untouched when the post becomes PartiallyPublished', function () {
    app()->instance(FacebookPublisher::class, new class extends FacebookPublisher
    {
        public function delete(PostPlatform $postPlatform): void {}
    });

    // startOfSecond(): posts.published_at is a `timestamp` column with the
    // default 0 fractional-second precision (see the posts migration), so a
    // value with microseconds would silently lose them on the round trip
    // and fail the equalTo() comparison below for a reason unrelated to the
    // fix being tested.
    $originalPublishedAt = now()->subDays(3)->startOfSecond();

    $post = Post::factory()->published()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'published_at' => $originalPublishedAt,
    ]);
    $facebookAccount = SocialAccount::factory()->facebook()->create(['workspace_id' => $this->workspace->id]);
    $linkedinAccount = SocialAccount::factory()->linkedin()->create(['workspace_id' => $this->workspace->id]);

    PostPlatform::factory()->facebook()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $facebookAccount->id,
    ]);
    PostPlatform::factory()->linkedin()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $linkedinAccount->id,
    ]);

    UnpublishPost::execute($post);

    $fresh = $post->fresh();
    expect($fresh->status)->toBe(PostStatus::PartiallyPublished)
        ->and($fresh->published_at->equalTo($originalPublishedAt))->toBeTrue();
});

/**
 * Runde-4-Entscheid (Punkt 9): `Platform::Instagram` (direct login) must
 * resolve to `unsupported`, never attempted, even though InstagramPublisher
 * (shared with InstagramFacebook) has a real delete() method. Meta's delete
 * API only works for Instagram accounts connected via a Facebook Page.
 */
test('a published Instagram (direct login) platform is unsupported even though InstagramPublisher implements delete()', function () {
    app()->instance(InstagramPublisher::class, new class extends InstagramPublisher
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
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $postPlatform = PostPlatform::factory()->instagram()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);

    $result = UnpublishPost::execute($post);

    expect($result['unpublished'])->toBe([])
        ->and($result['failed'])->toBe([])
        ->and($result['unsupported'])->toHaveCount(1)
        ->and($result['unsupported'][0]->id)->toBe($postPlatform->id);

    /** @var object{calls: int} $publisher */
    $publisher = app(InstagramPublisher::class);
    expect($publisher->calls)->toBe(0);

    expect($postPlatform->fresh()->status)->toBe(PostPlatformStatus::Published);
});

/**
 * The InstagramFacebook counterpart to the test above: the SAME publisher
 * instance, but for the account type Meta's delete API actually supports,
 * IS attempted and resets the row on success.
 */
test('a published InstagramFacebook platform is fully reset on delete success', function () {
    app()->instance(InstagramPublisher::class, new class extends InstagramPublisher
    {
        public function delete(PostPlatform $postPlatform): void {}
    });

    $post = Post::factory()->published()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $account = SocialAccount::factory()->instagramFacebook()->create(['workspace_id' => $this->workspace->id]);
    $postPlatform = PostPlatform::factory()->instagramFacebook()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);

    $result = UnpublishPost::execute($post);

    expect($result['unpublished'])->toHaveCount(1)
        ->and($result['unpublished'][0]->id)->toBe($postPlatform->id)
        ->and($result['failed'])->toBe([])
        ->and($result['unsupported'])->toBe([]);

    expect($postPlatform->fresh()->status)->toBe(PostPlatformStatus::Pending);
});

/**
 * An account missing the required delete scope (e.g. connected before
 * `instagram_manage_contents` was added, or Facebook missing
 * `pages_manage_posts`) must fail with a clear reconnect message, never a
 * raw API permission error, and must NOT even attempt the delete() call.
 */
test('a platform missing the required delete scope fails with a reconnect message without calling delete()', function () {
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
    $account = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $this->workspace->id,
        'scopes' => [], // missing pages_manage_posts
    ]);
    $postPlatform = PostPlatform::factory()->facebook()->published()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);

    $result = UnpublishPost::execute($post);

    expect($result['unpublished'])->toBe([])
        ->and($result['unsupported'])->toBe([])
        ->and($result['failed'])->toHaveCount(1)
        ->and($result['failed'][0]['post_platform']->id)->toBe($postPlatform->id)
        ->and($result['failed'][0]['message'])->toBe('Missing permissions: pages_manage_posts. Please reconnect your account.');

    /** @var object{calls: int} $publisher */
    $publisher = app(FacebookPublisher::class);
    expect($publisher->calls)->toBe(0);

    expect($postPlatform->fresh()->status)->toBe(PostPlatformStatus::Published)
        ->and($postPlatform->fresh()->platform_post_id)->not->toBeNull();
});
