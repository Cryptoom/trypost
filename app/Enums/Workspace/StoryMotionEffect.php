<?php

declare(strict_types=1);

namespace App\Enums\Workspace;

/**
 * PATCH:story-motion-settings: the camera and light effects a workspace can
 * pick for its Story videos. The case order IS the canonical order: "fixed"
 * takes the first selected effect in this order, and the other modes index
 * into the selection in this order.
 */
enum StoryMotionEffect: string
{
    case Drift = 'drift';
    case Focus = 'focus';
    case Snap = 'snap';
    case Sparkle = 'sparkle';

    public const DEFAULT = self::Focus;

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $effect) => $effect->value, self::cases());
    }
}
