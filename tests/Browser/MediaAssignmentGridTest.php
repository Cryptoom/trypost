<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

/**
 * A post with 3 media items and one enabled LinkedIn channel. `media_ids` on
 * the seeded PostPlatform starts empty, which MediaAssignmentGrid reads as
 * "unscoped, every item included" (see isIncluded()), so all three tiles
 * render as selected before any toggle happens.
 */
function seedMediaAssignmentGridPost(): PostPlatform
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::LinkedIn,
    ]);

    $mediaItem = fn (string $id) => [
        'id' => $id,
        'type' => 'image',
        'mime_type' => 'image/png',
        'path' => "uploads/{$id}.png",
        'url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        'size' => 128,
    ];

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'content' => 'three media items',
        'media' => [$mediaItem('m1'), $mediaItem('m2'), $mediaItem('m3')],
    ]);

    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::LinkedIn,
    ]);

    test()->actingAs($user);

    return $postPlatform;
}

/**
 * Poll browser-side until the testid element has laid out (width/height > 0).
 * Pest browser assertions do not auto-wait on SPA paint.
 */
function waitForMediaAssignmentTestId(mixed $page, string $testId): void
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
 * Poll browser-side until the testid element is removed from the DOM (used
 * after a toggle-off click, where the "-on" badge unmounts).
 */
function waitForMediaAssignmentGone(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const sel = '[data-testid="{$testId}"]';
            for (let i = 0; i < 100; i++) {
                if (! document.querySelector(sel)) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

/**
 * A fixed dwell so a click that is expected to be a no-op (the guarded third
 * toggle) still gives Vue's reactivity a chance to flush before asserting
 * nothing changed. Unlike waitForMediaAssignmentGone(), there is no DOM
 * transition to poll for when the click is correctly rejected.
 */
function settleMediaAssignmentGrid(mixed $page): void
{
    $page->script(<<<'JS'
        (async () => {
            await new Promise((r) => setTimeout(r, 300));
        })();
    JS);
}

test('the last remaining media item for a channel cannot be toggled off', function () {
    $postPlatform = seedMediaAssignmentGridPost();

    $page = visit(route('app.posts.edit', $postPlatform->post));
    waitForMediaAssignmentTestId($page, 'media-assignment-m1-on');

    // All three items start unscoped/included.
    $page->assertPresent('@media-assignment-m1-on')
        ->assertPresent('@media-assignment-m2-on')
        ->assertPresent('@media-assignment-m3-on');

    $page->click('@media-assignment-m1');
    waitForMediaAssignmentGone($page, 'media-assignment-m1-on');

    $page->assertMissing('@media-assignment-m1-on')
        ->assertPresent('@media-assignment-m2-on')
        ->assertPresent('@media-assignment-m3-on');

    $page->click('@media-assignment-m2');
    waitForMediaAssignmentGone($page, 'media-assignment-m2-on');

    $page->assertMissing('@media-assignment-m1-on')
        ->assertMissing('@media-assignment-m2-on')
        ->assertPresent('@media-assignment-m3-on');

    // m3 is the last remaining selected item: the toggle guard must refuse
    // to clear it, rather than silently flipping the selection back to
    // "unscoped" (which would re-include m1 and m2 too).
    $page->click('@media-assignment-m3');
    settleMediaAssignmentGrid($page);

    $page->assertPresent('@media-assignment-m3-on')
        ->assertMissing('@media-assignment-m1-on')
        ->assertMissing('@media-assignment-m2-on')
        ->assertNoJavaScriptErrors();
});
