<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\Media;
use App\Models\MediaPostPlatform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * TPX-04: covers the `media_post_platform` pivot (migration + model relation)
 * and `PostPlatform::scopedMediaItems()`. Companion to
 * tests/Feature/Api/PostMediaExistsValidationTest.php, which covers the
 * media-id existence/IDOR validation ported from TPX-03 (PR #8).
 */
beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    $this->socialAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);
});

function makePostWithMedia(Workspace $workspace, SocialAccount $socialAccount, array $mediaItems): array
{
    $media = collect($mediaItems)->map(fn () => Media::factory()->assets()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $workspace->id,
    ]));

    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'media' => $media->map(fn (Media $m) => [
            'id' => $m->id,
            'path' => $m->path,
            'url' => $m->url,
            'type' => $m->type->value,
        ])->all(),
    ]);

    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $socialAccount->id,
    ]);

    return [$post, $postPlatform, $media];
}

it('scopedMediaItems returns every post media item when no pivot rows exist for the platform', function () {
    [$post, $postPlatform, $media] = makePostWithMedia($this->workspace, $this->socialAccount, [1, 2, 3]);

    $scoped = $postPlatform->scopedMediaItems();

    expect($scoped)->toHaveCount(3)
        ->and($scoped->pluck('id')->sort()->values()->all())
        ->toBe($media->pluck('id')->sort()->values()->all());
});

it('scopedMediaItems returns only the pivoted media items when a selection exists', function () {
    [$post, $postPlatform, $media] = makePostWithMedia($this->workspace, $this->socialAccount, [1, 2, 3]);

    $postPlatform->media()->attach($media->first()->id);

    $scoped = $postPlatform->scopedMediaItems();

    expect($scoped)->toHaveCount(1)
        ->and($scoped->first()->id)->toBe($media->first()->id);
});

it('scopedMediaItems can select more than one media item for a platform', function () {
    [$post, $postPlatform, $media] = makePostWithMedia($this->workspace, $this->socialAccount, [1, 2, 3]);

    $postPlatform->media()->attach($media->take(2)->pluck('id'));

    $scoped = $postPlatform->scopedMediaItems();

    expect($scoped)->toHaveCount(2)
        ->and($scoped->pluck('id')->sort()->values()->all())
        ->toBe($media->take(2)->pluck('id')->sort()->values()->all());
});

it('allows the same media item to be selected for two platforms of the same post', function () {
    $media = Media::factory()->assets()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
    ]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'media' => [['id' => $media->id, 'path' => $media->path, 'url' => $media->url, 'type' => $media->type->value]],
    ]);

    $otherAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
    ]);

    $platformA = PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $this->socialAccount->id]);
    $platformB = PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $otherAccount->id]);

    $platformA->media()->attach($media->id);
    $platformB->media()->attach($media->id);

    expect($platformA->media()->count())->toBe(1)
        ->and($platformB->media()->count())->toBe(1);
});

it('generates a uuid primary key on the pivot row via HasUuids', function () {
    [$post, $postPlatform, $media] = makePostWithMedia($this->workspace, $this->socialAccount, [1]);

    $postPlatform->media()->attach($media->first()->id);

    $pivotRow = MediaPostPlatform::query()->firstOrFail();

    expect($pivotRow->id)->not->toBeNull()
        ->and(Str::isUuid($pivotRow->id))->toBeTrue();
});

it('enforces a unique constraint on media_id and post_platform_id', function () {
    [$post, $postPlatform, $media] = makePostWithMedia($this->workspace, $this->socialAccount, [1]);

    $postPlatform->media()->attach($media->first()->id);

    expect(fn () => $postPlatform->media()->attach($media->first()->id))
        ->toThrow(QueryException::class);
});

it('cascades delete when the media item is removed', function () {
    [$post, $postPlatform, $media] = makePostWithMedia($this->workspace, $this->socialAccount, [1]);

    $postPlatform->media()->attach($media->first()->id);
    expect(MediaPostPlatform::query()->count())->toBe(1);

    $media->first()->delete();

    expect(MediaPostPlatform::query()->count())->toBe(0);
});

it('cascades delete when the post platform is removed', function () {
    [$post, $postPlatform, $media] = makePostWithMedia($this->workspace, $this->socialAccount, [1]);

    $postPlatform->media()->attach($media->first()->id);
    expect(MediaPostPlatform::query()->count())->toBe(1);

    $postPlatform->delete();

    expect(MediaPostPlatform::query()->count())->toBe(0);
});
