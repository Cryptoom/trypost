<?php

declare(strict_types=1);

namespace App\Services\Media;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * PATCH:story-motion-instagram: hosts a rendered Story video on the public
 * disk so Instagram can fetch it by `video_url` (the same idea as the hosted
 * Story photo in CropsImageForAspectRatio::prepareStoryImageUrl).
 *
 * The file name is a random UUID, so the URL cannot be guessed. The file is
 * deleted as soon as the publish is over (published or failed for good), and
 * prune() removes whatever a crashed or exhausted publish left behind after a
 * day. delete() only ever touches a file that has the managed shape
 * (`DIRECTORY/<uuid>.mp4`), never an arbitrary path from a stored checkpoint.
 */
class StoryVideoHost
{
    public const string DIRECTORY = 'social-story-videos';

    /** Hours after which an orphaned hosted video is removed by prune(). */
    public const int MAX_AGE_HOURS = 24;

    /**
     * Copies the local MP4 to the public disk and returns its public URL and
     * storage path. The local file is left alone, the caller owns it.
     *
     * @return array{url: string, path: string}
     *
     * @throws RuntimeException when the file cannot be read or stored
     */
    public function host(string $localPath): array
    {
        $stream = fopen($localPath, 'rb');

        if ($stream === false) {
            throw new RuntimeException('Story video could not be read for hosting.');
        }

        $path = self::DIRECTORY.'/'.Str::uuid()->toString().'.mp4';

        try {
            if (Storage::put($path, $stream) === false) {
                throw new RuntimeException('Story video could not be stored.');
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return ['url' => Storage::url($path), 'path' => $path];
    }

    public function delete(mixed $path): void
    {
        if (! $this->isManagedPath($path)) {
            return;
        }

        try {
            Storage::delete($path);
        } catch (Throwable $e) {
            Log::warning('Failed to delete hosted story video', ['path' => $path, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Deletes hosted Story videos older than MAX_AGE_HOURS and returns how many.
     */
    public function prune(): int
    {
        $limit = Carbon::now()->subHours(self::MAX_AGE_HOURS)->getTimestamp();
        $deleted = 0;

        foreach (Storage::files(self::DIRECTORY) as $path) {
            if (! $this->isManagedPath($path) || Storage::lastModified($path) > $limit) {
                continue;
            }

            $this->delete($path);
            $deleted++;
        }

        return $deleted;
    }

    private function isManagedPath(mixed $path): bool
    {
        return is_string($path)
            && dirname($path) === self::DIRECTORY
            && pathinfo($path, PATHINFO_EXTENSION) === 'mp4'
            && Str::isUuid(pathinfo($path, PATHINFO_FILENAME));
    }
}
