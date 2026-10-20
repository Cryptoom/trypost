<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Enums\Workspace\StoryMotionEffect;
use App\Enums\Workspace\StoryMotionFont;
use App\Enums\Workspace\StoryMotionMode;
use App\Models\Workspace;

/**
 * PATCH:story-motion-settings: a workspace's Story video settings, read from
 * the `story_motion_settings` JSON column.
 *
 * Reading never throws and never trusts the column: NULL, an empty array,
 * garbage, a wrong type or an unknown value all fall back to the default for
 * that one value, and the default for "enabled" is off. That is what keeps the
 * publish path safe against a bad row.
 */
final class StoryMotionSettings
{
    public const AI_NOTICE_MAX = 40;

    public const ENDING_NAME_MAX = 40;

    public const ENDING_LINE_MAX = 60;

    /** The notice when the translation of the placeholder cannot be drawn by the render service's fonts. */
    public const DEFAULT_AI_NOTICE = 'AI-generated image';

    /**
     * @param  array<int, StoryMotionEffect>  $effects  at least one, in canonical order
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly StoryMotionFont $font,
        public readonly array $effects,
        public readonly StoryMotionMode $mode,
        public readonly bool $logoLayer,
        public readonly bool $aiNoticeEnabled,
        public readonly string $aiNoticeText,
        public readonly bool $endingEnabled,
        public readonly string $endingName,
        public readonly string $endingLine,
    ) {}

    public static function defaults(?string $brandFont = null): self
    {
        return new self(
            enabled: false,
            font: StoryMotionFont::fromBrandFont($brandFont),
            effects: [StoryMotionEffect::DEFAULT],
            mode: StoryMotionMode::DEFAULT,
            logoLayer: false,
            aiNoticeEnabled: false,
            aiNoticeText: self::defaultAiNotice(),
            endingEnabled: false,
            endingName: '',
            endingLine: '',
        );
    }

    /**
     * The default notice text: the placeholder of the settings form in the current locale, so what the form
     * suggests is what gets stored. The render service draws Latin, Greek and Cyrillic only, so a translation in
     * another script (Arabic, Japanese, Korean, Chinese) or one that is too long falls back to the English text:
     * a notice that the fonts cannot draw would silently be left out of the video.
     */
    public static function defaultAiNotice(): string
    {
        $text = __('settings.story_video.ai_notice_placeholder');

        if (is_string($text) && trim($text) !== '' && mb_strlen($text) <= self::AI_NOTICE_MAX && preg_match('/\A[\p{Latin}\p{Greek}\p{Cyrillic}\p{Common}\p{Inherited}]+\z/u', $text) === 1) {
            return trim($text);
        }

        return self::DEFAULT_AI_NOTICE;
    }

    public static function forWorkspace(Workspace $workspace): self
    {
        $stored = $workspace->story_motion_settings;

        return self::fromArray(is_array($stored) ? $stored : null, is_string($workspace->brand_font) ? $workspace->brand_font : null);
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data, ?string $brandFont = null): self
    {
        $defaults = self::defaults($brandFont);

        if ($data === null) {
            return $defaults;
        }

        $aiNotice = self::section($data, 'ai_notice');
        $ending = self::section($data, 'ending');

        return new self(
            enabled: is_bool($data['enabled'] ?? null) ? $data['enabled'] : $defaults->enabled,
            font: is_string($data['font'] ?? null) ? (StoryMotionFont::tryFrom($data['font']) ?? $defaults->font) : $defaults->font,
            effects: self::effects($data['effects'] ?? null) ?? $defaults->effects,
            mode: is_string($data['mode'] ?? null) ? (StoryMotionMode::tryFrom($data['mode']) ?? $defaults->mode) : $defaults->mode,
            logoLayer: is_bool($data['logo_layer'] ?? null) ? $data['logo_layer'] : $defaults->logoLayer,
            aiNoticeEnabled: is_bool($aiNotice['enabled'] ?? null) ? $aiNotice['enabled'] : $defaults->aiNoticeEnabled,
            aiNoticeText: self::text($aiNotice['text'] ?? null, self::AI_NOTICE_MAX) ?? $defaults->aiNoticeText,
            endingEnabled: is_bool($ending['enabled'] ?? null) ? $ending['enabled'] : $defaults->endingEnabled,
            endingName: self::text($ending['name'] ?? null, self::ENDING_NAME_MAX) ?? $defaults->endingName,
            endingLine: self::text($ending['line'] ?? null, self::ENDING_LINE_MAX) ?? $defaults->endingLine,
        );
    }

    /**
     * The stored shape.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'font' => $this->font->value,
            'effects' => array_map(fn (StoryMotionEffect $effect) => $effect->value, $this->effects),
            'mode' => $this->mode->value,
            'logo_layer' => $this->logoLayer,
            'ai_notice' => ['enabled' => $this->aiNoticeEnabled, 'text' => $this->aiNoticeText],
            'ending' => ['enabled' => $this->endingEnabled, 'name' => $this->endingName, 'line' => $this->endingLine],
        ];
    }

    /**
     * Merges already validated changes into these settings: only the keys the
     * caller sent change, nested sections change key by key.
     *
     * @param  array<string, mixed>  $changes
     */
    public function with(array $changes): self
    {
        $merged = $this->toArray();

        foreach ($changes as $key => $value) {
            if (is_array($value) && is_array($merged[$key] ?? null) && ! array_is_list($merged[$key])) {
                $merged[$key] = [...$merged[$key], ...$value];

                continue;
            }

            $merged[$key] = $value;
        }

        return self::fromArray($merged);
    }

    /**
     * The AI notice text that is actually shown, or null when the layer is off or its text is empty.
     */
    public function aiNotice(): ?string
    {
        return $this->aiNoticeEnabled && trim($this->aiNoticeText) !== '' ? trim($this->aiNoticeText) : null;
    }

    /**
     * The ending name and line that are actually shown, or null when the ending is off or both are empty.
     *
     * @return array{name: ?string, line: ?string}|null
     */
    public function ending(): ?array
    {
        $name = trim($this->endingName);
        $line = trim($this->endingLine);

        if (! $this->endingEnabled || ($name === '' && $line === '')) {
            return null;
        }

        return ['name' => $name === '' ? null : $name, 'line' => $line === '' ? null : $line];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function section(array $data, string $key): array
    {
        $section = $data[$key] ?? null;

        return is_array($section) ? $section : [];
    }

    /**
     * Valid effects in canonical order without duplicates, or null when nothing usable is there.
     *
     * @return array<int, StoryMotionEffect>|null
     */
    private static function effects(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $selected = array_filter(
            StoryMotionEffect::cases(),
            fn (StoryMotionEffect $effect) => in_array($effect->value, $value, true),
        );

        return $selected === [] ? null : array_values($selected);
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_string($value) || mb_strlen($value) > $max) {
            return null;
        }

        return trim($value);
    }
}
