<?php

declare(strict_types=1);

namespace App\Support;

/**
 * PATCH:story-motion-settings: what a workspace logo must look like to be used as the logo layer of a
 * Story video. One source for the renderer (which reads the stored logo), the logo upload (which scales
 * a larger logo down to fit) and the Brand settings page (which warns about a stored logo that does not fit).
 */
final class StoryMotionLogo
{
    /** Longest side in pixels the render service takes. */
    public const MAX_SIDE = 2000;

    /** Largest file in bytes the render service takes. */
    public const MAX_BYTES = 2 * 1024 * 1024;

    /**
     * Conservative input ceiling. Decoding, PNG buffers and EXIF orientation can hold multiple full-size
     * copies. The separate runtime budget check reserves 16 bytes per pixel plus 64 MiB beyond current PHP
     * allocations. This is a preflight estimate, not a guarantee about an unmeasured production allocator.
     */
    public const MAX_DECODE_PIXELS = 8_000_000;

    public static function decodeFitsMemory(int $pixels, int $memoryLimit, int $memoryUsage): bool
    {
        // An unlimited CLI must still respect a finite budget for allocations in external GD.
        $budget = $memoryLimit > 0 ? $memoryLimit : 256 * 1024 * 1024;

        return $pixels > 0 && $pixels <= intdiv(max(0, $budget - $memoryUsage - 64 * 1024 * 1024), 16);
    }

    /** The render service reads JPEG and PNG by their magic bytes, nothing else. */
    public const MIME_TYPES = ['image/jpeg', 'image/png'];

    /**
     * Whether a stored logo fits, judged from its stored size, dimensions and MIME type alone (no file access).
     */
    public static function usable(mixed $size, mixed $width, mixed $height, mixed $mimeType): bool
    {
        $size = (int) $size;
        $width = (int) $width;
        $height = (int) $height;

        return in_array($mimeType, self::MIME_TYPES, true)
            && $size > 0
            && $size <= self::MAX_BYTES
            && $width >= 1
            && $height >= 1
            && $width <= self::MAX_SIDE
            && $height <= self::MAX_SIDE;
    }
}
