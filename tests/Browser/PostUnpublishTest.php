<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\FacebookPublisher;

/**
 * Seeds a Published post with one enabled platform, owned by an admin so
 * canCreatePost passes (PostPolicy::update requires it, same gate as
 * Delete). $seedPlatform attaches the PostPlatform row for the returned post.
 */
function seedUnpublishPost(Platform $platform, Closure $seedPlatform): Post
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $post = Post::factory()->published()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'content' => 'a post to unpublish',
    ]);

    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => $platform,
    ]);

    $seedPlatform($post, $account);

    test()->actingAs($user);

    return $post;
}

/**
 * Poll browser-side until the testid element has laid out (width/height > 0).
 * Pest browser assertions do not auto-wait on SPA paint or on the
 * DropdownMenu portal's open animation.
 */
function waitForPostsTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const sel = '[data-testid="{$testId}"]';
            for (let i = 0; i < 100; i++) {
                const el = document.querySelector(sel);
                if (el && el.getBoundingClientRect().height > 0) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

/**
 * Poll browser-side until the given testid element's text contains $text.
 * Used after the unpublish confirmation, since the resulting status-badge
 * update lands via an Inertia partial reload, not a full navigation.
 */
function waitForPostsRowText(mixed $page, string $testId, string $text): void
{
    $encodedText = json_encode($text);
    $page->script(<<<JS
        (async () => {
            const sel = '[data-testid="{$testId}"]';
            for (let i = 0; i < 100; i++) {
                const el = document.querySelector(sel);
                if (el && el.textContent.includes({$encodedText})) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

test('unpublishing a post removes it from a delete-capable platform and moves it back to draft', function () {
    app()->instance(FacebookPublisher::class, new class extends FacebookPublisher
    {
        public function delete(PostPlatform $postPlatform): void {}
    });

    $post = seedUnpublishPost(Platform::Facebook, function (Post $post, SocialAccount $account) {
        PostPlatform::factory()->facebook()->published()->create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
        ]);
    });

    $page = visit(route('app.posts.index'));
    waitForPostsTestId($page, "post-actions-{$post->id}");

    $page->click("@post-actions-{$post->id}");
    waitForPostsTestId($page, "post-unpublish-{$post->id}");

    $page->click("@post-unpublish-{$post->id}");
    waitForPostsTestId($page, 'post-unpublish-modal-action');

    $page->click('@post-unpublish-modal-action');
    waitForPostsRowText($page, "post-row-{$post->id}", __('posts.status.draft'));

    $page->assertSeeIn("@post-row-{$post->id}", __('posts.status.draft'))
        ->assertNoJavaScriptErrors();

    expect($post->fresh()->status)->toBe(PostStatus::Draft);
});

test('unpublish is disabled for a tiktok-only post, delete stays available', function () {
    $post = seedUnpublishPost(Platform::TikTok, function (Post $post, SocialAccount $account) {
        PostPlatform::factory()->tiktok()->published()->create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
        ]);
    });

    $page = visit(route('app.posts.index'));
    waitForPostsTestId($page, "post-actions-{$post->id}");

    $page->click("@post-actions-{$post->id}");
    waitForPostsTestId($page, "post-unpublish-{$post->id}");

    $page->assertScript(
        "document.querySelector('[data-testid=\"post-unpublish-{$post->id}\"]').hasAttribute('data-disabled')",
        true
    )
        ->assertPresent("@post-delete-{$post->id}")
        ->assertNoJavaScriptErrors();

    // The disabled item is a guaranteed no-op server-side too (see
    // UnpublishPostTest's TikTok-only coverage): confirm it never left the
    // Published status client-side, i.e. nothing was silently triggered.
    expect($post->fresh()->status)->toBe(PostStatus::Published);
});
