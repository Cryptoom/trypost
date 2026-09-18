<?php

declare(strict_types=1);

use App\Dto\MediaItem;
use App\Enums\Media\Type as MediaType;
use App\Enums\PostPlatform\ContentType;
use App\Rules\ContentTypeCompatibleWithMedia;
use Illuminate\Support\Str;

function runMediaRule(string $contentType, array $media): array
{
    $errors = [];
    $rule = (new ContentTypeCompatibleWithMedia)->setData(['media' => $media]);
    $rule->validate('platforms.0.content_type', $contentType, function (string $message) use (&$errors): void {
        $errors[] = $message;
    });

    return $errors;
}

test('passes when content type does not require media and none provided', function () {
    expect(runMediaRule(ContentType::LinkedInPost->value, []))->toBe([]);
    expect(runMediaRule(ContentType::FacebookPost->value, []))->toBe([]);
    expect(runMediaRule(ContentType::XPost->value, []))->toBe([]);
});

test('fails when content type requires media and none provided', function () {
    $errors = runMediaRule(ContentType::InstagramReel->value, []);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('requires at least one image or video');
});

test('fails when content type does not support images and an image is present', function () {
    $media = [['type' => MediaType::Image->value, 'mime_type' => 'image/jpeg']];

    $errors = runMediaRule(ContentType::TikTokVideo->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('accepts only videos');
});

test('fails when content type does not support video and a video is present', function () {
    $media = [['type' => MediaType::Video->value, 'mime_type' => 'video/mp4']];

    $errors = runMediaRule(ContentType::PinterestPin->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('does not accept videos');
});

test('youtube short rejects images', function () {
    $media = [['type' => MediaType::Image->value, 'mime_type' => 'image/jpeg']];

    $errors = runMediaRule(ContentType::YouTubeShort->value, $media);

    expect($errors[0])->toContain('accepts only videos');
});

test('passes when image-only content type receives an image', function () {
    $media = [['type' => MediaType::Image->value, 'mime_type' => 'image/png']];

    expect(runMediaRule(ContentType::PinterestPin->value, $media))->toBe([]);
});

test('passes when video-only content type receives a video', function () {
    $media = [['type' => MediaType::Video->value, 'mime_type' => 'video/mp4']];

    expect(runMediaRule(ContentType::TikTokVideo->value, $media))->toBe([]);
    expect(runMediaRule(ContentType::YouTubeShort->value, $media))->toBe([]);
    expect(runMediaRule(ContentType::InstagramReel->value, $media))->toBe([]);
    expect(runMediaRule(ContentType::FacebookStory->value, $media))->toBe([]);
});

test('facebook story accepts images (auto-converted to video server-side)', function () {
    $media = [['type' => MediaType::Image->value, 'mime_type' => 'image/jpeg']];

    expect(runMediaRule(ContentType::FacebookStory->value, $media))->toBe([]);
});

test('instagram story accepts images', function () {
    $media = [['type' => MediaType::Image->value, 'mime_type' => 'image/jpeg']];

    expect(runMediaRule(ContentType::InstagramStory->value, $media))->toBe([]);
});

test('detects media type from mime when type field is missing', function () {
    $media = [['mime_type' => 'image/jpeg']];

    $errors = runMediaRule(ContentType::TikTokVideo->value, $media);

    expect($errors)->toHaveCount(1);
});

test('detects media type from the filename when both type and mime are missing', function () {
    // Same fallback as MediaItem::fromArray(): a video is measured against the video cap, not the image one.
    $overVideoCap = ContentType::MastodonPost->maxVideoBytes() + 1;

    $byPath = runMediaRule(ContentType::MastodonPost->value, [['path' => 'medias/clip.mp4', 'size' => $overVideoCap]]);
    $byName = runMediaRule(ContentType::MastodonPost->value, [['original_filename' => 'Clip.MOV', 'size' => $overVideoCap]]);
    $imageOnly = runMediaRule(ContentType::TikTokVideo->value, [['path' => 'medias/photo.png']]);

    expect($byPath)->toHaveCount(1)->and($byPath[0])->toContain('Video exceeds')
        ->and($byName)->toHaveCount(1)->and($byName[0])->toContain('Video exceeds')
        ->and($imageOnly)->toHaveCount(1)->and($imageOnly[0])->toContain('accepts only videos');
});

test('an explicit type wins over a contradicting mime', function () {
    // Mirrors classify() in mediaType.ts: the server-assigned type is trusted first.
    $media = [['type' => MediaType::Image->value, 'mime_type' => 'video/mp4']];

    expect(runMediaRule(ContentType::TikTokVideo->value, $media))->toHaveCount(1)
        ->and(runMediaRule(ContentType::InstagramStory->value, $media))->toBe([]);
});

test('bluesky rejects an image and a video in the same post', function () {
    $media = [
        ['type' => MediaType::Image->value, 'mime_type' => 'image/jpeg'],
        ['type' => MediaType::Video->value, 'mime_type' => 'video/mp4'],
    ];

    $errors = runMediaRule(ContentType::BlueskyPost->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain("can't be combined in the same post");
});

test('bluesky still accepts an image-only or video-only post', function () {
    $image = [['type' => MediaType::Image->value, 'mime_type' => 'image/jpeg']];
    $video = [['type' => MediaType::Video->value, 'mime_type' => 'video/mp4']];

    expect(runMediaRule(ContentType::BlueskyPost->value, $image))->toBe([]);
    expect(runMediaRule(ContentType::BlueskyPost->value, $video))->toBe([]);
});

test('bluesky accepts a mov video', function () {
    $media = [['type' => MediaType::Video->value, 'mime_type' => 'video/quicktime']];

    expect(runMediaRule(ContentType::BlueskyPost->value, $media))->toBe([]);
});

test('bluesky accepts a mov video identified only by filename', function () {
    $media = [['type' => MediaType::Video->value, 'mime_type' => 'video/mp4', 'original_filename' => 'clip.mov']];

    expect(runMediaRule(ContentType::BlueskyPost->value, $media))->toBe([]);
});

test('x still accepts a mov video', function () {
    $media = [['type' => MediaType::Video->value, 'mime_type' => 'video/quicktime']];

    expect(runMediaRule(ContentType::XPost->value, $media))->toBe([]);
});

test('a gif is rejected on content types that do not accept gifs', function () {
    $media = [['type' => MediaType::Image->value, 'mime_type' => 'image/gif']];

    foreach ([ContentType::InstagramFeed, ContentType::LinkedInPost, ContentType::PinterestPin, ContentType::FacebookPost] as $type) {
        $errors = runMediaRule($type->value, $media);

        expect($errors)->toHaveCount(1, $type->value);
        expect($errors[0])->toContain('does not accept GIF');
    }
});

test('a gif passes on content types that accept gifs', function () {
    $media = [['type' => MediaType::Image->value, 'mime_type' => 'image/gif']];

    foreach ([ContentType::XPost, ContentType::BlueskyPost, ContentType::MastodonPost, ContentType::DiscordMessage, ContentType::TelegramPost] as $type) {
        expect(runMediaRule($type->value, $media))->toBe([], $type->value);
    }
});

test('an image over the content type cap is rejected by size', function () {
    $media = [['type' => MediaType::Image->value, 'mime_type' => 'image/jpeg', 'size' => 5 * 1024 * 1024 + 1]];

    $errors = runMediaRule(ContentType::XPost->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('Image exceeds the');
    expect($errors[0])->toContain('5 MB');
});

test('a kind violation is reported alone and is not overwritten by a size violation on the same item', function () {
    // A 6 MB GIF on LinkedIn breaks two rules; the root cause ("does not accept GIF") must be the one message.
    $media = [['type' => MediaType::Image->value, 'mime_type' => 'image/gif', 'size' => 6 * 1024 * 1024]];

    $errors = runMediaRule(ContentType::LinkedInPost->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('GIF');
});

test('an item that cannot be classified is not measured against any cap', function () {
    // An API `url`-only entry before download: no type, mime or filename, but a stray size.
    $media = [['url' => 'https://example.com/asset', 'size' => 999_999_999]];

    expect(runMediaRule(ContentType::XPost->value, $media))->toBe([]);
});

test('bluesky does not cap the original image because the publisher re-encodes it under the blob limit', function () {
    // A phone JPEG well over Bluesky's 2 MB blob limit published fine before caps existed; it must still schedule.
    $media = [['type' => MediaType::Image->value, 'mime_type' => 'image/jpeg', 'size' => 8 * 1024 * 1024]];

    expect(runMediaRule(ContentType::BlueskyPost->value, $media))->toBe([]);
});

test('decimal caps are reported in decimal units for both the cap and the file', function () {
    $media = [['type' => MediaType::Video->value, 'mime_type' => 'video/mp4', 'size' => 305_000_000]];

    $errors = runMediaRule(ContentType::BlueskyPost->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('300 MB limit for this post type (yours is 305.0 MB)');
});

test('binary caps keep binary units', function () {
    $media = [['type' => MediaType::Video->value, 'mime_type' => 'video/mp4', 'size' => 320 * 1024 * 1024]];

    $errors = runMediaRule(ContentType::InstagramReel->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('300 MB limit for this post type (yours is 320.0 MB)');
});

test('an image exactly at the content type cap passes', function () {
    $media = [['type' => MediaType::Image->value, 'mime_type' => 'image/jpeg', 'size' => 5 * 1024 * 1024]];

    expect(runMediaRule(ContentType::XPost->value, $media))->toBe([]);
});

test('a video over the content type cap is rejected by size', function () {
    $media = [['type' => MediaType::Video->value, 'mime_type' => 'video/mp4', 'size' => 300 * 1024 * 1024 + 1]];

    $errors = runMediaRule(ContentType::InstagramReel->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('Video exceeds the');
});

test('a pdf over the content type cap is rejected by size', function () {
    $max = ContentType::LinkedInPost->maxDocumentBytes();
    $media = [['type' => MediaType::Document->value, 'mime_type' => 'application/pdf', 'size' => $max + 1]];

    $errors = runMediaRule(ContentType::LinkedInPost->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('PDF exceeds the');
});

test('media without a size is not checked against byte caps', function () {
    $media = [['type' => MediaType::Video->value, 'mime_type' => 'video/mp4']];

    expect(runMediaRule(ContentType::InstagramReel->value, $media))->toBe([]);
});

test('a video longer than the content type cap is rejected by duration', function () {
    $media = [['type' => MediaType::Video->value, 'mime_type' => 'video/mp4', 'meta' => ['duration' => 61.4]]];

    $errors = runMediaRule(ContentType::InstagramStory->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('allows up to 1min');
    expect($errors[0])->toContain('Video is 1min 2s long');
});

test('a video within the duration cap passes and a video without duration is not checked', function () {
    $within = [['type' => MediaType::Video->value, 'mime_type' => 'video/mp4', 'meta' => ['duration' => 60]]];
    $unknown = [['type' => MediaType::Video->value, 'mime_type' => 'video/mp4', 'meta' => []]];

    expect(runMediaRule(ContentType::InstagramStory->value, $within))->toBe([]);
    expect(runMediaRule(ContentType::InstagramStory->value, $unknown))->toBe([]);
});

test('duration is ignored on content types without a duration cap', function () {
    $media = [['type' => MediaType::Video->value, 'mime_type' => 'video/mp4', 'meta' => ['duration' => 3 * 60 * 60]]];

    expect(ContentType::DiscordMessage->maxVideoDurationSec())->toBeNull();
    expect(runMediaRule(ContentType::DiscordMessage->value, $media))->toBe([]);
});

test('bluesky rejects an animated gif combined with a video', function () {
    // A GIF counts as an image, so gif + video is still mixed media.
    $media = [
        ['type' => MediaType::Image->value, 'mime_type' => 'image/gif'],
        ['type' => MediaType::Video->value, 'mime_type' => 'video/mp4'],
    ];

    $errors = runMediaRule(ContentType::BlueskyPost->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain("can't be combined in the same post");
});

test('a mixed-media content type accepts an image and a video together', function () {
    $media = [
        ['type' => MediaType::Image->value, 'mime_type' => 'image/jpeg'],
        ['type' => MediaType::Video->value, 'mime_type' => 'video/mp4'],
    ];

    expect(runMediaRule(ContentType::DiscordMessage->value, $media))->toBe([]);
});

test('linkedin post accepts a pdf on its own', function () {
    $media = [['type' => MediaType::Document->value, 'mime_type' => 'application/pdf']];

    expect(runMediaRule(ContentType::LinkedInPost->value, $media))->toBe([]);
    expect(runMediaRule(ContentType::LinkedInPagePost->value, $media))->toBe([]);
});

test('linkedin post detects a pdf from mime when type field is missing', function () {
    $media = [['mime_type' => 'application/pdf']];

    expect(runMediaRule(ContentType::LinkedInPost->value, $media))->toBe([]);
});

test('a pdf must be the only attachment on linkedin', function () {
    $media = [
        ['type' => MediaType::Document->value, 'mime_type' => 'application/pdf'],
        ['type' => MediaType::Image->value, 'mime_type' => 'image/jpeg'],
    ];

    $errors = runMediaRule(ContentType::LinkedInPost->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('must be posted on its own');
});

test('linkedin rejects mixing an image and a video', function () {
    $media = [
        ['type' => MediaType::Image->value, 'mime_type' => 'image/jpeg'],
        ['type' => MediaType::Video->value, 'mime_type' => 'video/mp4'],
    ];

    $errors = runMediaRule(ContentType::LinkedInPost->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain("can't be combined in the same post");
});

test('a pdf is rejected on content types that do not support documents', function () {
    $media = [['type' => MediaType::Document->value, 'mime_type' => 'application/pdf']];

    $errors = runMediaRule(ContentType::XPost->value, $media);

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('does not accept PDF documents');
});

test('falls back to stored media when the request omits the media key', function () {
    // A PDF fallback on a document-capable type passes.
    $errors = [];
    (new ContentTypeCompatibleWithMedia([['type' => 'document', 'mime_type' => 'application/pdf']]))
        ->setData([]) // no 'media' key in the request -> use the fallback
        ->validate('platforms.0.content_type', ContentType::LinkedInPost->value, function (string $message) use (&$errors): void {
            $errors[] = $message;
        });

    expect($errors)->toBe([]);

    // The same PDF fallback on X (no document support) is rejected — proving the fallback is used.
    $xErrors = [];
    (new ContentTypeCompatibleWithMedia([['type' => 'document', 'mime_type' => 'application/pdf']]))
        ->setData([])
        ->validate('platforms.0.content_type', ContentType::XPost->value, function (string $message) use (&$xErrors): void {
            $xErrors[] = $message;
        });

    expect($xErrors)->toHaveCount(1);
    expect($xErrors[0])->toContain('does not accept PDF documents');
});

test('request media takes precedence over the stored fallback', function () {
    // Fallback is a lone PDF (would pass), but the request carries a PDF + image,
    // which must be rejected — proving the request media is used over the fallback.
    $errors = [];
    (new ContentTypeCompatibleWithMedia([['type' => 'document', 'mime_type' => 'application/pdf']]))
        ->setData(['media' => [
            ['type' => 'document', 'mime_type' => 'application/pdf'],
            ['type' => 'image', 'mime_type' => 'image/jpeg'],
        ]])
        ->validate('platforms.0.content_type', ContentType::LinkedInPost->value, function (string $message) use (&$errors): void {
            $errors[] = $message;
        });

    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('must be posted on its own');
});

test('does nothing for invalid content type values', function () {
    expect(runMediaRule('not_a_real_content_type', []))->toBe([]);
});

// entriesForUpdate() / errorsFor(): the per-platform pipeline that resolves
// each entry's own effective media before validating it, instead of every
// platform sharing one media list.

use App\Models\Media;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;

/**
 * A post with the given media (already-hosted Media rows, each mirrored into
 * `posts.media` so PostPlatform::scopedMediaItems() has something to filter).
 */
function postWithMedia(array $mediaModels): Post
{
    $workspace = Workspace::factory()->create();

    return Post::factory()->create([
        'workspace_id' => $workspace->id,
        'media' => collect($mediaModels)->map(fn (Media $media) => MediaItem::fromMedia($media)->toArray())->all(),
    ]);
}

function makeMedia(Workspace $workspace, string $type = 'image'): Media
{
    $factory = match ($type) {
        'video' => Media::factory()->video(),
        'document' => Media::factory()->document(),
        default => Media::factory(),
    };

    return $factory->create(['mediable_type' => Workspace::class, 'mediable_id' => $workspace->id]);
}

function makePostPlatform(Post $post, ContentType $contentType): PostPlatform
{
    return PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => SocialAccount::factory()->create(['workspace_id' => $post->workspace_id]),
        'content_type' => $contentType,
    ]);
}

test('each platform is validated against its own scoped media, not a list shared with every other platform', function () {
    $workspace = Workspace::factory()->create();
    $image = makeMedia($workspace, 'image');
    $video = makeMedia($workspace, 'video');
    $post = postWithMedia([$image, $video]);

    // TikTok requires video and rejects images; Pinterest rejects video. With
    // the OLD shared-media behaviour both would fail because the other
    // platform's item is also present in the pool.
    $tiktok = makePostPlatform($post, ContentType::TikTokVideo);
    $tiktok->media()->attach($video->id);

    $pinterest = makePostPlatform($post, ContentType::PinterestPin);
    $pinterest->media()->attach($image->id);

    $entries = ContentTypeCompatibleWithMedia::entriesForUpdate($post, [
        ['id' => $tiktok->id, 'content_type' => ContentType::TikTokVideo->value],
        ['id' => $pinterest->id, 'content_type' => ContentType::PinterestPin->value],
    ]);

    expect(ContentTypeCompatibleWithMedia::errorsFor($entries))->toBe([]);
});

test('falls back to the platform own scoped media when the request omits media', function () {
    $workspace = Workspace::factory()->create();
    $image = makeMedia($workspace, 'image');
    $video = makeMedia($workspace, 'video');
    $post = postWithMedia([$image, $video]);

    $linkedin = makePostPlatform($post, ContentType::LinkedInPost);
    $linkedin->media()->attach($image->id);

    // No $requestMedia argument: entriesForUpdate must read the platform's
    // own scoped media (image only), not the post's full image+video list,
    // which LinkedIn would reject as mixed media.
    $entries = ContentTypeCompatibleWithMedia::entriesForUpdate($post, [
        ['id' => $linkedin->id, 'content_type' => ContentType::LinkedInPost->value],
    ]);

    expect(ContentTypeCompatibleWithMedia::errorsFor($entries))->toBe([]);
});

test('request media overrides every platform own scoped media', function () {
    $workspace = Workspace::factory()->create();
    $image = makeMedia($workspace, 'image');
    $video = makeMedia($workspace, 'video');
    $post = postWithMedia([$image, $video]);

    $linkedin = makePostPlatform($post, ContentType::LinkedInPost);
    $linkedin->media()->attach($image->id);

    // The platform is scoped to the image alone (would pass on its own), but
    // the request resubmits both items as the new post media, so LinkedIn
    // must reject the mix.
    $requestMedia = [
        MediaItem::fromMedia($image)->toArray(),
        MediaItem::fromMedia($video)->toArray(),
    ];

    $entries = ContentTypeCompatibleWithMedia::entriesForUpdate(
        $post,
        [['id' => $linkedin->id, 'content_type' => ContentType::LinkedInPost->value]],
        $requestMedia,
    );

    $errors = ContentTypeCompatibleWithMedia::errorsFor($entries);

    expect($errors)->toHaveCount(1);
    expect($errors['platforms.0.content_type'])->toContain("can't be combined in the same post");
});

test('falls back to the full post media when no stored platform can be resolved for the entry', function () {
    $workspace = Workspace::factory()->create();
    $image = makeMedia($workspace, 'image');
    $video = makeMedia($workspace, 'video');
    $post = postWithMedia([$image, $video]);

    // No PostPlatform row exists for this id at all (defensive path).
    $entries = ContentTypeCompatibleWithMedia::entriesForUpdate($post, [
        ['id' => (string) Str::uuid(), 'content_type' => ContentType::LinkedInPost->value],
    ]);

    $errors = ContentTypeCompatibleWithMedia::errorsFor($entries);

    expect($errors)->toHaveCount(1);
    expect($errors['platforms.0.content_type'])->toContain("can't be combined in the same post");
});

test('assertStoredPostCompatible validates every enabled platform against its own scoped media', function () {
    $workspace = Workspace::factory()->create();
    $image = makeMedia($workspace, 'image');
    $video = makeMedia($workspace, 'video');
    $post = postWithMedia([$image, $video]);

    $tiktok = makePostPlatform($post, ContentType::TikTokVideo);
    $tiktok->media()->attach($video->id);

    $pinterest = makePostPlatform($post, ContentType::PinterestPin);
    $pinterest->media()->attach($image->id);

    ContentTypeCompatibleWithMedia::assertStoredPostCompatible($post);
})->throwsNoExceptions();

test('a content type requiring media correctly fails when its scoped media resolves to none, this is the correct error, not a bug to soften', function () {
    // Randfall from the plan: a platform's per-platform selection ends up
    // empty (here: it was assigned media that has since been removed from
    // the post's media list, e.g. everything else got reassigned/dropped).
    // Content types that require media must still fail, not be softened.
    $workspace = Workspace::factory()->create();
    $keptOnPost = makeMedia($workspace, 'image');
    $removedFromPost = makeMedia($workspace, 'video');
    $post = postWithMedia([$keptOnPost]); // $removedFromPost is NOT in posts.media

    $facebookStory = makePostPlatform($post, ContentType::FacebookStory);
    $facebookStory->media()->attach($removedFromPost->id);

    $entries = ContentTypeCompatibleWithMedia::entriesForUpdate($post, [
        ['id' => $facebookStory->id, 'content_type' => ContentType::FacebookStory->value],
    ]);

    expect($entries[0]['media'])->toBe([]);

    $errors = ContentTypeCompatibleWithMedia::errorsFor($entries);

    expect($errors)->toHaveCount(1);
    expect($errors['platforms.0.content_type'])->toContain('requires at least one image or video');
});
