<?php

declare(strict_types=1);

namespace App\Exceptions\Media;

use RuntimeException;

/**
 * PATCH:story-motion-settings: raised before an image of a size-limited collection (the workspace logo)
 * is decoded, when its pixel count is too large to decode safely within the PHP memory limit. Nothing has
 * been stored or deleted when it is thrown.
 */
class ImageTooLargeException extends RuntimeException
{
    public function __construct(
        public readonly int $width,
        public readonly int $height,
        public readonly int $maxPixels,
    ) {
        parent::__construct("Image of {$width} x {$height} px exceeds {$maxPixels} pixels");
    }
}
