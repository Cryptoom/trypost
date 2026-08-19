<?php

declare(strict_types=1);

use App\Models\Post;
use App\Models\SocialAccount;
use Carbon\Carbon;

/**
 * `router.post` triggers a full page navigation (the server responds with
 * `Inertia::location()`), not an SPA visit, so wait on the URL itself rather
 * than a data-testid mount.
 */
function waitForPathToMatch(mixed $page, string $pattern): void
{
    $page->script(<<<JS
        (async () => {
            for (let i = 0; i < 160; i++) {
                if (new RegExp('{$pattern}').test(window.location.pathname)) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

test('the sidebar create post button creates a draft and opens the editor', function (): void {
    [$user, $workspace] = actingAsWorkspaceUser();
    SocialAccount::factory()->for($workspace)->create();

    // The sidebar renders on every authenticated page; the posts index is
    // used here purely as a host so the test isolates the sidebar affordance
    // from the index page's own create button.
    $page = visit(route('app.posts.index'));

    $page->assertVisible('@sidebar-create-post')
        ->click('@sidebar-create-post');

    waitForPathToMatch($page, '^/posts/[^/]+/edit$');

    $post = Post::where('workspace_id', $workspace->id)->firstOrFail();

    $page->assertRoute('app.posts.edit', ['post' => $post]);
    expect($post->scheduled_at)->toBeNull();
});

test('the sidebar create post button guards against a double click and creates only one post', function (): void {
    [$user, $workspace] = actingAsWorkspaceUser();
    SocialAccount::factory()->for($workspace)->create();

    $page = visit(route('app.posts.index'));

    $page->assertVisible('@sidebar-create-post');

    // Two synchronous DOM clicks in the same script tick, so the second
    // fires before any network response can return — this is the actual
    // race a fast real double click produces, not an approximation of it.
    $page->script(<<<'JS'
        (() => {
            const el = document.querySelector('[data-testid="sidebar-create-post"]');
            el.click();
            el.click();
        })();
    JS);

    waitForPathToMatch($page, '^/posts/[^/]+/edit$');

    $page->wait(0.3);

    expect(Post::where('workspace_id', $workspace->id)->count())->toBe(1);
});

test('the posts index create post button creates a draft and opens the editor', function (): void {
    [$user, $workspace] = actingAsWorkspaceUser();
    SocialAccount::factory()->for($workspace)->create();

    $page = visit(route('app.posts.index'));

    $page->assertVisible('@posts-create-post')
        ->click('@posts-create-post');

    waitForPathToMatch($page, '^/posts/[^/]+/edit$');

    $post = Post::where('workspace_id', $workspace->id)->firstOrFail();

    $page->assertRoute('app.posts.edit', ['post' => $post]);
    expect($post->scheduled_at)->toBeNull();
});

test('the posts index create post button redirects to accounts when no accounts are connected', function (): void {
    [$user, $workspace] = actingAsWorkspaceUser();

    $page = visit(route('app.posts.index'));

    $page->assertVisible('@posts-create-post')
        ->click('@posts-create-post');

    waitForPathToMatch($page, '^/accounts$');

    $page->assertRoute('app.accounts');
    expect(Post::where('workspace_id', $workspace->id)->count())->toBe(0);
});

test('the calendar header create post button creates a draft and opens the editor', function (): void {
    [$user, $workspace] = actingAsWorkspaceUser();
    SocialAccount::factory()->for($workspace)->create();

    $page = visit(route('app.calendar'));

    $page->assertVisible('@calendar-create-post-desktop')
        ->click('@calendar-create-post-desktop');

    waitForPathToMatch($page, '^/posts/[^/]+/edit$');

    $post = Post::where('workspace_id', $workspace->id)->firstOrFail();

    $page->assertRoute('app.posts.edit', ['post' => $post]);
    expect($post->scheduled_at)->toBeNull();
});

test('the calendar per-day create post button carries its date through to the created post', function (): void {
    [$user, $workspace] = actingAsWorkspaceUser();
    SocialAccount::factory()->for($workspace)->create();

    // Pin the visible week so the target cell's date is deterministic
    // instead of depending on "today" and the browser's local timezone.
    $weekParam = '2026-08-17';
    $expectedDate = Carbon::parse($weekParam, 'UTC')->startOfWeek()->format('Y-m-d');

    $page = visit(route('app.calendar', ['view' => 'week', 'week' => $weekParam]));

    $testId = "calendar-create-post-day-column-{$expectedDate}";

    $page->assertVisible("@{$testId}")
        ->click("@{$testId}");

    waitForPathToMatch($page, '^/posts/[^/]+/edit$');

    $post = Post::where('workspace_id', $workspace->id)->firstOrFail();

    $page->assertRoute('app.posts.edit', ['post' => $post]);
    expect($post->scheduled_at?->toDateString())->toBe($expectedDate);
});
