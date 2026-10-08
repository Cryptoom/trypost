<?php

declare(strict_types=1);

// PATCH:story-photo-fit

use App\Enums\UserWorkspace\Role;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

/**
 * A draft with one or two photos and one Facebook story channel. The photos are real
 * 200x200 images so the cropper has natural dimensions to work with.
 *
 * @param  array<string, mixed>  $meta
 */
function seedStoryFitPost(array $meta = [], int $photos = 1): PostPlatform
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $account = SocialAccount::factory()->facebook()->create(['workspace_id' => $workspace->id]);

    // A small solid landscape PNG: the update endpoint caps media URLs at 2048 characters.
    $image = imagecreatetruecolor(160, 90);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 20, 120, 200));
    ob_start();
    imagepng($image);
    $url = 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    // The update endpoint only accepts media that exist as workspace assets.
    $mediaItems = collect(range(1, $photos))->map(function (int $position) use ($workspace, $url): array {
        $asset = Media::factory()->assets()->create([
            'mediable_type' => (new Workspace)->getMorphClass(),
            'mediable_id' => $workspace->id,
        ]);

        return [
            'id' => $asset->id,
            'type' => 'image',
            'mime_type' => 'image/png',
            'path' => $asset->path,
            'url' => $url,
            'size' => 4096,
            'meta' => ['width' => 160, 'height' => 90],
        ];
    })->all();

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'content' => 'story photo',
        'media' => $mediaItems,
    ]);

    $postPlatform = PostPlatform::factory()->facebookStory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'meta' => $meta,
    ]);

    test()->actingAs($user);

    return $postPlatform;
}

/**
 * Polls from the page (never sleep(): the test's HTTP server only ticks while
 * Pest awaits Playwright) until the element exists and `$condition` holds.
 */
function waitForStoryFit(mixed $page, string $testId, string $condition = 'el.getBoundingClientRect().height > 0'): void
{
    $page->script(<<<JS
        (async () => {
            const sel = '[data-testid="{$testId}"]';
            for (let i = 0; i < 100; i++) {
                const el = document.querySelector(sel);
                if (el && ({$condition})) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

/** The editor autosaves 1.5 s after the last change; wait for that from inside the page. */
function waitForStoryFitAutosave(mixed $page): void
{
    $page->script('(async () => { await new Promise((r) => setTimeout(r, 3000)); })();');
}

test('story photo shows the fit modes, a saved manual crop survives a reload', function () {
    $postPlatform = seedStoryFitPost();

    $page = visit(route('app.posts.edit', $postPlatform->post));
    waitForStoryFit($page, 'facebook-settings-toggle');
    $page->click('@facebook-settings-toggle');
    waitForStoryFit($page, 'story-fit-settings');

    $page->assertPresent('@story-fit-mode-center')
        ->assertPresent('@story-fit-mode-smart')
        ->assertPresent('@story-fit-mode-manual')
        ->assertPresent('@story-fit-mode-fit')
        ->assertAttribute('@story-fit-mode-center', 'aria-pressed', 'true')
        ->assertMissing('@media-rules-warning')
        ->assertPresent('@story-fit-hint');

    $page->click('@story-fit-mode-manual');
    waitForStoryFit($page, 'story-fit-pick-crop');
    $page->click('@story-fit-pick-crop');
    waitForStoryFit($page, 'crop-save', '!el.disabled');
    $page->click('@crop-save');
    waitForStoryFit($page, 'story-fit-crop-status', "el.textContent.includes('Crop saved')");
    waitForStoryFitAutosave($page);

    $meta = $postPlatform->refresh()->meta;
    expect($meta['story_fit'])->toBe('manual')
        ->and(array_keys($meta['story_crop']))->toEqualCanonicalizing(['x', 'y', 'w', 'h']);

    $reloaded = visit(route('app.posts.edit', $postPlatform->post));
    waitForStoryFit($reloaded, 'facebook-settings-toggle');
    $reloaded->click('@facebook-settings-toggle');
    waitForStoryFit($reloaded, 'story-fit-settings');

    $reloaded->assertAttribute('@story-fit-mode-manual', 'aria-pressed', 'true')
        ->assertSeeIn('@story-fit-crop-status', 'Crop saved')
        ->assertNoJavaScriptErrors();
});

test('changing the first story photo discards the saved crop', function () {
    $postPlatform = seedStoryFitPost([
        'story_fit' => 'manual',
        'story_crop' => ['x' => 0.1, 'y' => 0.0, 'w' => 0.5625, 'h' => 1.0],
    ], photos: 2);

    $page = visit(route('app.posts.edit', $postPlatform->post));
    waitForStoryFit($page, 'facebook-settings-toggle');
    $page->click('@facebook-settings-toggle');
    waitForStoryFit($page, 'story-fit-crop-status', "el.textContent.includes('Crop saved')");

    // Deselect the first photo: the channel now publishes the second one first, so the frame drawn on the first no longer applies.
    $firstPhotoId = data_get($postPlatform->post->media, '0.id');
    $page->click("@media-assignment-{$firstPhotoId}");
    waitForStoryFit($page, 'story-fit-crop-status', "el.textContent.includes('No crop chosen')");
    waitForStoryFitAutosave($page);

    $page->assertSeeIn('@story-fit-crop-status', 'No crop chosen')
        ->assertNoJavaScriptErrors();

    expect(data_get($postPlatform->refresh()->meta, 'story_crop'))->toBeNull();
});
