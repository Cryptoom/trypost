<?php

declare(strict_types=1);

namespace App\Enums\Workspace;

/**
 * PATCH:story-motion-settings: the fonts shipped inside the story-render image
 * (all SIL OFL, Latin, Latin-ext, Greek and Cyrillic). The value is the key the
 * render service accepts. Not a web font list: nothing here is loaded in the
 * browser.
 */
enum StoryMotionFont: string
{
    case Inter = 'inter';
    case Commissioner = 'commissioner';
    case Comfortaa = 'comfortaa';
    case NotoSans = 'noto-sans';
    case NotoSerif = 'noto-serif';

    public const DEFAULT = self::Inter;

    /**
     * The workspace's brand font when the render service ships it, otherwise Inter.
     */
    public static function fromBrandFont(?string $brandFont): self
    {
        return match ($brandFont) {
            BrandFont::NotoSans->value => self::NotoSans,
            BrandFont::NotoSerif->value => self::NotoSerif,
            default => self::DEFAULT,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(fn (self $font) => $font->value, self::cases());
    }
}
