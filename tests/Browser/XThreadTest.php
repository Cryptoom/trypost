<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

/**
 * Flag when the composer's debounced autosave PUT finishes so the test can wait
 * on the real round-trip instead of a fixed sleep. Same helper as AltTextTest.
 */
function trackXThreadAutosave(mixed $page): void
{
    $page->script(<<<'JS'
        (() => {
            window.__autosaveDone = false;
            const open = XMLHttpRequest.prototype.open;
            const send = XMLHttpRequest.prototype.send;
            XMLHttpRequest.prototype.open = function (method) {
                this.__method = (method || '').toUpperCase();
                return open.apply(this, arguments);
            };
            XMLHttpRequest.prototype.send = function () {
                this.addEventListener('loadend', () => {
                    if (this.__method === 'PUT') {
                        window.__autosaveDone = true;
                    }
                });
                return send.apply(this, arguments);
            };
        })();
    JS);
}

function waitForXThreadAutosave(mixed $page): void
{
    $page->script(<<<'JS'
        (async () => {
            for (let attempt = 0; attempt < 100 && ! window.__autosaveDone; attempt++) {
                await new Promise((resolve) => setTimeout(resolve, 100));
            }
        })();
    JS);
}

function setUpXThreadPost(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $xAccount = SocialAccount::factory()->x()->create(['workspace_id' => $workspace->id]);

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'content' => 'Main tweet',
    ]);

    $platform = PostPlatform::factory()->x()->create([
        'post_id' => $post->id,
        'social_account_id' => $xAccount->id,
        'enabled' => true,
    ]);

    return [$user, $post, $platform];
}

test('adding thread segments in the composer persists them to post_platform meta', function () {
    [$user, $post, $platform] = setUpXThreadPost();

    $this->actingAs($user);

    $page = visit(route('app.posts.edit', $post));

    trackXThreadAutosave($page);

    $page->click('@tab-schedule')
        ->click('@x-thread-settings-toggle')
        ->click('@x-thread-add-segment')
        ->type('@x-thread-segment-input-0', 'Second tweet')
        ->click('@x-thread-add-segment')
        ->type('@x-thread-segment-input-1', 'Third tweet');

    waitForXThreadAutosave($page);

    $meta = $platform->fresh()->meta;
    expect(data_get($meta, 'thread_segments.0'))->toBe('Second tweet');
    expect(data_get($meta, 'thread_segments.1'))->toBe('Third tweet');
});

test('thread segments survive a page reload', function () {
    [$user, $post, $platform] = setUpXThreadPost();
    $platform->update(['meta' => ['thread_segments' => ['Second tweet', 'Third tweet']]]);

    $this->actingAs($user);

    $page = visit(route('app.posts.edit', $post));

    $page->click('@tab-schedule')
        ->click('@x-thread-settings-toggle');

    $page->assertValue('@x-thread-segment-input-0', 'Second tweet');
    $page->assertValue('@x-thread-segment-input-1', 'Third tweet');
});

test('removing a thread segment persists the removal', function () {
    [$user, $post, $platform] = setUpXThreadPost();
    $platform->update(['meta' => ['thread_segments' => ['Second tweet', 'Third tweet']]]);

    $this->actingAs($user);

    $page = visit(route('app.posts.edit', $post));

    trackXThreadAutosave($page);

    $page->click('@tab-schedule')
        ->click('@x-thread-settings-toggle')
        ->click('@x-thread-remove-segment-0');

    waitForXThreadAutosave($page);

    $meta = $platform->fresh()->meta;
    expect(data_get($meta, 'thread_segments'))->toHaveCount(1);
    expect(data_get($meta, 'thread_segments.0'))->toBe('Third tweet');
});

test('a thread segment past the character limit shows an over-limit count', function () {
    [$user, $post, $platform] = setUpXThreadPost();

    $this->actingAs($user);

    $page = visit(route('app.posts.edit', $post));

    $page->click('@tab-schedule')
        ->click('@x-thread-settings-toggle')
        ->click('@x-thread-add-segment')
        ->type('@x-thread-segment-input-0', str_repeat('a', 281));

    $page->assertSeeIn('@x-thread-segment-count-0', '281/280');
});
