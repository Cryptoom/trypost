<?php

declare(strict_types=1);

namespace App\Support;

/**
 * PATCH:story-motion-settings: picks the accent colour of a Story video from the
 * workspace's brand colour.
 *
 * The accent colours one headline word, the dots of the subline, the badge and the
 * ending line. The headline, subline and badge sit on the UPPER text veil of the render
 * service, which reaches at most rgba(10,42,61,0.72); over a white photo that is
 * #4F6673 (the lightest background those texts can have), over a dark photo it is close
 * to black. The colour is therefore tested on both sides, WCAG contrast of at least 3:1
 * against #4F6673 AND against black. The ending block (name, accent line) sits on the
 * LOWER veil, which the render service places so that its full strength of 0.80 covers
 * the whole block; over a white photo that is #3B5564, darker than the reference, so the
 * same check holds for it with room to spare. A brand colour that fails falls back to
 * gold, and gold to white. The service itself only checks the shape of the value.
 */
final class StoryMotionAccent
{
    public const GOLD = '#FDB31B';

    public const WHITE = '#FFFFFF';

    /** The textual background at its lightest: the 0.72 veil of #0A2A3D over white. */
    public const LIGHT_BACKGROUND = '#4F6673';

    public const DARK_BACKGROUND = '#000000';

    public const MIN_CONTRAST = 3.0;

    public static function resolve(?string $brandColor): string
    {
        foreach ([self::normalize($brandColor), self::GOLD, self::WHITE] as $candidate) {
            if ($candidate !== null && self::readable($candidate)) {
                return $candidate;
            }
        }

        return self::WHITE;
    }

    /**
     * `#RGB`, `#RRGGBB` and `#RRGGBBAA` become upper-case `#RRGGBB` (alpha is dropped). Anything else is null.
     */
    public static function normalize(?string $color): ?string
    {
        if ($color === null || preg_match('/\A#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})\z/', $color, $matches) !== 1) {
            return null;
        }

        $digits = strtoupper($matches[1]);

        if (strlen($digits) === 3) {
            $digits = $digits[0].$digits[0].$digits[1].$digits[1].$digits[2].$digits[2];
        }

        return '#'.substr($digits, 0, 6);
    }

    public static function readable(string $hex): bool
    {
        return self::contrast($hex, self::LIGHT_BACKGROUND) >= self::MIN_CONTRAST
            && self::contrast($hex, self::DARK_BACKGROUND) >= self::MIN_CONTRAST;
    }

    /**
     * WCAG 2 contrast ratio of two `#RRGGBB` colours (sRGB linearized relative luminance).
     */
    public static function contrast(string $a, string $b): float
    {
        $first = self::luminance($a);
        $second = self::luminance($b);

        return (max($first, $second) + 0.05) / (min($first, $second) + 0.05);
    }

    public static function luminance(string $hex): float
    {
        $value = hexdec(substr($hex, 1, 6));
        $channels = [($value >> 16) & 255, ($value >> 8) & 255, $value & 255];
        [$r, $g, $b] = array_map(function (int $channel): float {
            $c = $channel / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $channels);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }
}
