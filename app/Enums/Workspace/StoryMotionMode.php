<?php

declare(strict_types=1);

namespace App\Enums\Workspace;

/**
 * PATCH:story-motion-settings: how a workspace with several selected effects
 * picks one per Story. See App\Services\Media\StoryMotionEffectPicker.
 */
enum StoryMotionMode: string
{
    case Fixed = 'fixed';
    case Rotate = 'rotate';
    case Random = 'random';

    public const DEFAULT = self::Fixed;

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $mode) => $mode->value, self::cases());
    }
}
