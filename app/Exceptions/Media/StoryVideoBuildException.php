<?php

declare(strict_types=1);

namespace App\Exceptions\Media;

use RuntimeException;

/**
 * PATCH:story-motion-instagram: why a Story photo could not be turned into a
 * video. Platform neutral on purpose: each publisher maps it to its own
 * exception (and wording), so the shared builder knows no publisher.
 */
class StoryVideoBuildException extends RuntimeException
{
    public const string DOWNLOAD = 'download';

    public const string CONVERT = 'convert';

    public function __construct(
        public readonly string $reason,
        public readonly ?int $httpStatus = null,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : "Story video could not be built ({$reason})");
    }
}
