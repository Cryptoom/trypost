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
