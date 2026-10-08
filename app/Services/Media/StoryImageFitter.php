<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\PostPlatform;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PATCH:story-photo-fit
 *
 * Turns any story photo into a 1080 x 1920 JPEG according to the user's
 * `story_fit` choice:
 *
 *  - center (default, also for a missing or unknown mode): center crop
 *  - smart: Gemini suggests the crop, any failure falls back to center
 *  - manual: the user's `story_crop` rectangle, invalid or missing falls back to center
 *  - fit: the whole photo on a blurred background, nothing is cropped
 *
 * Returns a temp file path, the caller owns cleanup.
 */
class StoryImageFitter
{
    public const WIDTH = 1080;

    public const HEIGHT = 1920;

    public function __construct(
        private readonly MediaOptimizer $optimizer,
        private readonly StoryCropSuggester $suggester,
    ) {}

    /**
     * The saved `story_crop`, but only while it still belongs to the photo being published: the
     * frame is stored with `story_crop_media_id`, and a different (or unknown) first photo means the
     * frame was drawn on another image. Returns null then (center crop), never an error.
     *
     * @return array<string, mixed>|null
     */
    public static function boundCrop(PostPlatform $postPlatform, ?string $firstMediaId): ?array
    {
        $rect = data_get($postPlatform->meta, 'story_crop');

        if (! is_array($rect)) {
            return null;
        }

        $boundId = data_get($postPlatform->meta, 'story_crop_media_id');

        if ($firstMediaId !== null && is_string($boundId) && $boundId === $firstMediaId) {
            return $rect;
        }

        // Only story posts in manual mode are worth a log line; this runs for every Facebook post.
        if ($postPlatform->content_type?->autoFitsImage() && data_get($postPlatform->meta, 'story_fit') === 'manual') {
            Log::info('Story crop ignored, it is not bound to the first photo', [
                'post_platform_id' => $postPlatform->id,
                'first_media_id' => $firstMediaId,
                'bound_media_id' => $boundId,
            ]);
        }

        return null;
    }

    /**
     * @param  mixed  $rect  normalized {x, y, w, h} array, only used by `manual`
     */
    public function fit(string $imagePath, mixed $mode, mixed $rect = null): string
    {
        $mode = is_string($mode) ? $mode : null;

        if ($mode === 'fit') {
            return $this->optimizer->fitToCanvas($imagePath, self::WIDTH, self::HEIGHT);
        }

        $cropRect = match ($mode) {
            'smart' => $this->suggester->suggest($imagePath),
            'manual' => is_array($rect) ? $rect : null,
            default => null,
        };

        if ($cropRect !== null) {
            try {
                return $this->optimizer->cropToRect(
                    $imagePath,
                    (float) data_get($cropRect, 'x'),
                    (float) data_get($cropRect, 'y'),
                    (float) data_get($cropRect, 'w'),
                    (float) data_get($cropRect, 'h'),
                    self::WIDTH,
                    self::HEIGHT,
                );
            } catch (Throwable $e) {
                Log::info('Story crop rectangle rejected, using center crop', ['mode' => $mode, 'error' => $e->getMessage()]);
            }
        }

        return $this->optimizer->coverToSize($imagePath, self::WIDTH, self::HEIGHT);
    }

    /**
     * Same as fit(), but returns the untouched source path when the photo
     * cannot be processed at all (undecodable, over the memory budget), so a
     * story publish never starts failing here. The caller must not unlink the
     * result twice when it equals the input.
     */
    public function fitOrKeep(string $imagePath, mixed $mode, mixed $rect = null): string
    {
        try {
            return $this->fit($imagePath, $mode, $rect);
        } catch (Throwable $e) {
            Log::warning('Story image could not be fitted to 9:16, using the original', ['error' => $e->getMessage()]);

            return $imagePath;
        }
    }
}
