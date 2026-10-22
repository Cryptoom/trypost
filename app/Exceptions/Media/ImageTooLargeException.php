<?php

declare(strict_types=1);

namespace App\Exceptions\Media;

use RuntimeException;

/**
 * PATCH:story-motion-settings: raised before an image of a size-limited collection (the workspace logo)
 * is decoded, when it exceeds the input ceiling or the estimated memory budget. Nothing has been stored
 * or deleted when it is thrown. It does not claim a measured bound for every decoder or allocator.
 */
class ImageTooLargeException extends RuntimeException
{
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly int $maxPixels,
    ) {
        parent::__construct("Image of {$width} x {$height} px exceeds the processing budget (pixel ceiling: {$maxPixels})");
    }
}
