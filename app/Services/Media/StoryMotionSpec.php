<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Enums\Workspace\StoryMotionEffect;
use InvalidArgumentException;

/**
 * PATCH:story-motion-settings: builds the `spec` the story-render service reads
 * (see docker/story-render/lib/spec.mjs). Pure: no database, no config, no
 * network, so the whole contract with the service can be tested without a
 * workspace.
 *
 * Empty texts become null, so the service leaves the element out. Everything
 * else is passed on as it is; the service validates strictly again and sanitizes
 * every text itself.
 */
final class StoryMotionSpec
{
    public const MIN_DURATION = 3;

    public const MAX_DURATION = 20;

    /**
     * @param  array{headline?: ?string, subline?: ?string, badge?: ?string, headline_alt?: ?string, subline_alt?: ?string}  $texts  the per-Story fields
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException when a value cannot be sent at all
     */
    public static function build(
        StoryMotionSettings $settings,
        StoryMotionEffect $effect,
        array $texts,
        string $accent,
        int $durationSeconds,
        bool $logoLayer,
    ): array {
        if ($durationSeconds < self::MIN_DURATION || $durationSeconds > self::MAX_DURATION) {
            throw new InvalidArgumentException('Story motion duration out of range.');
        }

        if (preg_match('/\A#[0-9A-F]{6}\z/', $accent) !== 1) {
            throw new InvalidArgumentException('Story motion accent colour is not #RRGGBB.');
        }

        $ending = $settings->ending();

        return [
            'duration' => $durationSeconds,
            'effect' => $effect->value,
            'font' => $settings->font->value,
            'accent' => $accent,
            'logoLayer' => $logoLayer,
            'aiNotice' => $settings->aiNotice(),
            'headline' => self::text($texts['headline'] ?? null),
            'subline' => self::text($texts['subline'] ?? null),
            'badge' => self::text($texts['badge'] ?? null),
            'headlineAlt' => self::text($texts['headline_alt'] ?? null),
            'sublineAlt' => self::text($texts['subline_alt'] ?? null),
            'ending' => $ending,
        ];
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
