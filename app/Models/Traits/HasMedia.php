<?php

declare(strict_types=1);

namespace App\Models\Traits;

use App\Enums\Media\Type;
use App\Exceptions\Media\ImageTooLargeException;
use App\Models\Media;
use App\Models\User;
use App\Models\Workspace;
use App\Support\StoryMotionLogo;
use App\Support\VideoDurationProbe;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use InvalidArgumentException;
use RuntimeException;

trait HasMedia
{
    /**
     * Media collections configuration.
     * 'single' = only one media per collection (replaces existing)
     * 'multiple' = unlimited media per collection
     */
    protected static array $mediaCollections = [
        Workspace::class => [
            'logo' => 'single',
            'assets' => 'multiple',
        ],
        User::class => [
            'avatar' => 'single',
        ],
    ];

    /**
     * PATCH:story-motion-settings: images of these collections are scaled down (proportionally, never up)
     * until their longest side and their file size fit. The logo must fit what the Story render service takes.
     *
     * An image with more than `pixels` pixels is refused before it is decoded (ImageTooLargeException).
     *
     * @var array<class-string, array<string, array{side: int, bytes: int, pixels: int}>>
     */
    protected static array $mediaImageLimits = [
        Workspace::class => [
            'logo' => ['side' => StoryMotionLogo::MAX_SIDE, 'bytes' => StoryMotionLogo::MAX_BYTES, 'pixels' => StoryMotionLogo::MAX_DECODE_PIXELS],
        ],
    ];

    public function media(): MorphMany
    {
        return $this->morphMany(Media::class, 'mediable')->orderBy('order');
    }

    public function getMedia(string $collection = 'default'): MorphMany
    {
        return $this->media()->where('collection', $collection);
    }

    public function getFirstMedia(string $collection = 'default'): ?Media
    {
        return $this->getMedia($collection)->first();
    }

    public function getFirstMediaUrl(string $collection = 'default', ?string $default = null): ?string
    {
        $media = $this->getFirstMedia($collection);

        return $media?->url ?? $default;
    }

    /**
     * Generate a DiceBear fallback URL using initials.
     */
    public function getFallbackAvatarUrl(string $seed): string
    {
        return 'https://api.dicebear.com/9.x/initials/svg?backgroundColor=777777&fontFamily=Verdana&fontSize=40&seed='.urlencode($seed);
    }

    /**
     * Add media to a collection.
     * If the collection is configured as 'single', the existing media is removed once the new one is stored,
     * so a failed upload never loses it.
     */
    public function addMedia(UploadedFile $file, string $collection = 'default', array $meta = [], ?string $groupId = null): Media
    {
        $previous = $this->previousSingleMediaIds($collection);

        $mimeType = $file->getMimeType();
        $type = $this->getMediaType($mimeType);

        // Normalize non-JPEG still images to JPEG q100 for universal platform compatibility.
        // GIF is preserved (animation kept for X/Bluesky/Mastodon).
        [$normalizedBytes, $normalizedMime, $normalizedExt] = $this->normalizeImageFormat(
            $file->getPathname(),
            $mimeType,
            $type,
            $file->getClientOriginalExtension(),
            $this->imageLimitsFor($collection),
        );

        $filename = Str::uuid().'.'.$normalizedExt;
        $path = "medias/{$filename}";
        $meta = $this->withVideoDuration($meta, $type, $file->getPathname());

        Storage::put($path, $normalizedBytes);

        $media = $this->media()->create([
            'group_id' => $groupId ?? Str::uuid()->toString(),
            'collection' => $collection,
            'type' => $type,
            'path' => $path,
            'original_filename' => $this->sanitizeOriginalFilename($file->getClientOriginalName()),
            'mime_type' => $normalizedMime,
            'size' => strlen($normalizedBytes),
            'order' => 0,
            'meta' => [...$this->imageDimensions($normalizedBytes, $type), ...$meta],
        ]);

        $this->deleteMediaByIds($previous);

        return $media;
    }

    /**
     * Add media from a file path (used for chunked uploads).
     * Images are normalized in memory; videos/PDFs are streamed to storage.
     * A 'single' collection keeps its existing media until the new one is stored.
     */
    public function addMediaFromPath(string $filePath, string $originalFilename, string $collection = 'default', array $meta = [], ?string $groupId = null, ?string $mimeType = null): Media
    {
        $previous = $this->previousSingleMediaIds($collection);

        // Prefer an explicit MIME (e.g. from UploadedFile after FormRequest
        // validation) — sniffing misclassifies empty test fakes and some
        // freshly written temps as application/x-empty.
        $mimeType ??= File::mimeType($filePath) ?: null;

        if ($mimeType === null) {
            throw new InvalidArgumentException("Unable to determine MIME type for media file: {$filePath}");
        }

        $type = $this->getMediaType($mimeType);
        $extension = File::extension($originalFilename);
        $meta = $this->withVideoDuration($meta, $type, $filePath);

        $stored = $type === Type::Image
            ? $this->storeImageFromPath($filePath, $mimeType, $type, $extension, $meta, $this->imageLimitsFor($collection))
            : $this->streamFileToStorage($filePath, $mimeType, $extension, $meta);

        $media = $this->media()->create([
            'group_id' => $groupId ?? Str::uuid()->toString(),
            'collection' => $collection,
            'type' => $type,
            'path' => $stored['path'],
            'original_filename' => $this->sanitizeOriginalFilename($originalFilename),
            'mime_type' => $stored['mime_type'],
            'size' => $stored['size'],
            'order' => 0,
            'meta' => $stored['meta'],
        ]);

        $this->deleteMediaByIds($previous);

        return $media;
    }

    /**
     * Register media that is already stored on the default disk (e.g. after a
     * multipart cloud upload).
     */
    public function addMediaFromStoredPath(
        string $storagePath,
        string $originalFilename,
        string $mimeType,
        int $size,
        string $collection = 'default',
        array $meta = [],
        ?string $groupId = null,
    ): Media {
        if ($this->isSingleMediaCollection($collection)) {
            $this->clearMediaCollection($collection);
        }

        $type = $this->getMediaType($mimeType);

        return $this->media()->create([
            'group_id' => $groupId ?? Str::uuid()->toString(),
            'collection' => $collection,
            'type' => $type,
            'path' => $storagePath,
            'original_filename' => $this->sanitizeOriginalFilename($originalFilename),
            'mime_type' => $mimeType,
            'size' => $size,
            'order' => 0,
            'meta' => $meta,
        ]);
    }

    public function clearMediaCollection(string $collection = 'default'): void
    {
        $this->getMedia($collection)->each(fn (Media $media) => $media->delete());
    }

    /**
     * The media a 'single' collection holds before a new item is added (removed only after it is stored).
     *
     * @return array<int, int|string>
     */
    private function previousSingleMediaIds(string $collection): array
    {
        return $this->isSingleMediaCollection($collection)
            ? $this->getMedia($collection)->pluck('id')->all()
            : [];
    }

    /**
     * @param  array<int, int|string>  $ids
     */
    private function deleteMediaByIds(array $ids): void
    {
        if ($ids === []) {
            return;
        }

        $this->media()->whereKey($ids)->get()->each(fn (Media $media) => $media->delete());
    }

    public function isSingleMediaCollection(string $collection): bool
    {
        $modelClass = static::class;
        $config = self::$mediaCollections[$modelClass][$collection] ?? 'multiple';

        return $config === 'single';
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array{side: int, bytes: int, pixels: int}|null  $limits
     * @return array{path: string, mime_type: string, size: int, meta: array<string, mixed>}
     */
    private function storeImageFromPath(string $filePath, string $mimeType, Type $type, string $extension, array $meta, ?array $limits = null): array
    {
        [$bytes, $storedMime, $storedExt] = $this->normalizeImageFormat(
            $filePath,
            $mimeType,
            $type,
            $extension,
            $limits,
        );

        $filename = Str::uuid().".{$storedExt}";
        $path = "medias/{$filename}";
        Storage::put($path, $bytes);

        return [
            'path' => $path,
            'mime_type' => $storedMime,
            'size' => strlen($bytes),
            'meta' => [...$this->imageDimensions($bytes, $type), ...$meta],
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{path: string, mime_type: string, size: int, meta: array<string, mixed>}
     */
    private function streamFileToStorage(string $filePath, string $mimeType, string $extension, array $meta): array
    {
        $filename = Str::uuid().".{$extension}";
        $path = "medias/{$filename}";
        $stream = fopen($filePath, 'rb');

        if ($stream === false) {
            throw new RuntimeException("Unable to open media file for reading: {$filePath}");
        }

        try {
            Storage::writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return [
            'path' => $path,
            'mime_type' => $mimeType,
            'size' => (int) filesize($filePath),
            'meta' => $meta,
        ];
    }

    private function getMediaType(string $mimeType): Type
    {
        return Type::classify($mimeType)
            ?? throw new InvalidArgumentException("Unsupported media MIME type: {$mimeType}");
    }

    /**
     * Client-supplied filenames may contain byte sequences that aren't valid
     * UTF-8 (e.g. a raw Windows-1252 byte for an em dash). Postgres rejects
     * those outright on insert, so replace invalid sequences before storing.
     */
    private function sanitizeOriginalFilename(string $filename): string
    {
        return mb_scrub($filename, 'UTF-8');
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function withVideoDuration(array $meta, Type $type, string $filePath): array
    {
        return $type === Type::Video
            ? VideoDurationProbe::mergeInto($meta, VideoDurationProbe::fromFile($filePath))
            : $meta;
    }

    /**
     * Pixel width / height of raw image bytes (read after format normalization,
     * when the original file path is gone); nothing for other types.
     *
     * @return array<string, int>
     */
    private function imageDimensions(string $bytes, Type $type): array
    {
        $info = $type === Type::Image ? @getimagesizefromstring($bytes) : false;

        return $info ? ['width' => $info[0], 'height' => $info[1]] : [];
    }

    /**
     * @return array{side: int, bytes: int, pixels: int}|null
     */
    private function imageLimitsFor(string $collection): ?array
    {
        return self::$mediaImageLimits[static::class][$collection] ?? null;
    }

    /**
     * Convert PNG/WebP/HEIC/AVIF to JPEG at q100 (keeps dimensions). GIF and
     * JPEG are returned untouched. Non-image types are passed through.
     *
     * With `$limits` (PATCH:story-motion-settings, logo collection) a still image whose longest side is
     * larger is scaled down proportionally, and the JPEG quality is lowered step by step until the file
     * fits the byte limit. A JPEG that already fits is stored untouched. GIF is never scaled (animation).
     * Before anything is decoded, an image with more than `$limits['pixels']` pixels is refused
     * (ImageTooLargeException), and one whose dimensions cannot be read is stored untouched.
     *
     * @param  array{side: int, bytes: int, pixels: int}|null  $limits
     * @return array{0: string, 1: string, 2: string} [bytes, mime_type, extension]
     */
    private function normalizeImageFormat(string $filePath, string $mimeType, Type $type, string $originalExtension, ?array $limits = null): array
    {
        if ($type !== Type::Image) {
            return [file_get_contents($filePath), $mimeType, $originalExtension];
        }

        if ($mimeType === 'image/gif') {
            return [file_get_contents($filePath), $mimeType, $originalExtension];
        }

        if ($limits !== null) {
            $info = @getimagesize($filePath);

            if ($info === false) {
                return [file_get_contents($filePath), $mimeType, $originalExtension];
            }

            if ($limits['pixels'] < $info[0] * $info[1]) {
                throw new ImageTooLargeException($info[0], $info[1], $limits['pixels']);
            }
        }

        // Formats that publish safely everywhere (JPEG is universal). A JPEG is only re-encoded when it
        // is larger than the limits of its collection.
        if (in_array($mimeType, ['image/jpeg', 'image/jpg'], true) && ! $this->exceedsImageLimits($filePath, $limits)) {
            return [file_get_contents($filePath), $mimeType, $originalExtension];
        }

        try {
            $manager = new ImageManager(new Driver);
            $image = $manager->decodePath($filePath);

            if ($limits !== null && ($image->width() > $limits['side'] || $image->height() > $limits['side'])) {
                $image = $image->scaleDown($limits['side'], $limits['side']);
            }

            $encoded = (string) $image->encode(new JpegEncoder(quality: 100));

            if ($limits !== null) {
                foreach ([92, 85, 75, 65, 55] as $quality) {
                    if (strlen($encoded) <= $limits['bytes']) {
                        break;
                    }

                    $encoded = (string) $image->encode(new JpegEncoder(quality: $quality));
                }
            }

            return [$encoded, 'image/jpeg', 'jpg'];
        } catch (\Throwable $e) {
            Log::warning('HasMedia: image normalization failed, storing original', [
                'mime' => $mimeType,
                'error' => $e->getMessage(),
            ]);

            return [file_get_contents($filePath), $mimeType, $originalExtension];
        }
    }

    /**
     * @param  array{side: int, bytes: int, pixels: int}|null  $limits
     */
    private function exceedsImageLimits(string $filePath, ?array $limits): bool
    {
        if ($limits === null) {
            return false;
        }

        $info = @getimagesize($filePath);

        return $info !== false && ($info[0] > $limits['side'] || $info[1] > $limits['side'] || filesize($filePath) > $limits['bytes']);
    }
}
