<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Workspace\StoryMotionEffect;
use App\Enums\Workspace\StoryMotionFont;
use App\Enums\Workspace\StoryMotionMode;
use App\Services\Media\StoryMotionSettings;
use Closure;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * PATCH:story-motion-settings: single source of truth for validating a
 * workspace's Story video settings, shared by the web endpoint
 * (UpdateStoryVideoSettingsRequest) and the MCP workspace tool so both accept
 * exactly the same input.
 *
 * rules() describes the INPUT: every key is optional ("sometimes") because the
 * MCP tool merges, and a section may be sent partially. assertComplete() then
 * checks the MERGED result, which is the only place where "enabled needs text"
 * can be decided.
 */
class StoryMotionSettingsRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(string $prefix = ''): array
    {
        $noContactDetails = fn (string $attribute, mixed $value, Closure $fail) => is_string($value) && self::looksLikeContactDetails($value)
            ? $fail(__('settings.story_video.errors.no_contact_details'))
            : null;

        return [
            $prefix.'enabled' => ['sometimes', 'boolean'],
            $prefix.'font' => ['sometimes', 'string', Rule::in(StoryMotionFont::values())],
            $prefix.'effects' => ['sometimes', 'array', 'min:1', 'max:'.count(StoryMotionEffect::cases())],
            $prefix.'effects.*' => ['string', 'distinct', Rule::in(StoryMotionEffect::values())],
            $prefix.'mode' => ['sometimes', 'string', Rule::in(StoryMotionMode::values())],
            $prefix.'logo_layer' => ['sometimes', 'boolean'],
            $prefix.'ai_notice' => ['sometimes', 'array'],
            $prefix.'ai_notice.enabled' => ['sometimes', 'boolean'],
            $prefix.'ai_notice.text' => ['sometimes', 'nullable', 'string', 'max:'.StoryMotionSettings::AI_NOTICE_MAX],
            $prefix.'ending' => ['sometimes', 'array'],
            $prefix.'ending.enabled' => ['sometimes', 'boolean'],
            $prefix.'ending.name' => ['sometimes', 'nullable', 'string', 'max:'.StoryMotionSettings::ENDING_NAME_MAX, $noContactDetails],
            $prefix.'ending.line' => ['sometimes', 'nullable', 'string', 'max:'.StoryMotionSettings::ENDING_LINE_MAX, $noContactDetails],
        ];
    }

    /**
     * An ending is meant to carry a shop name and a line, not an address or a phone number: a run of six
     * or more digits (separators ignored), a plus sign followed by a digit, or a tel: link.
     */
    public static function looksLikeContactDetails(string $text): bool
    {
        $compact = preg_replace('/(?<=\d)[\s.\-()\/]+(?=\d)/u', '', $text) ?? $text;

        return preg_match('/\d{6,}/', $compact) === 1
            || preg_match('/\+\s*\d/', $text) === 1
            || preg_match('/tel:/i', $text) === 1;
    }

    /**
     * Checks the merged settings: a switched-on notice needs its text, a switched-on ending needs a name or
     * a line. Throws a ValidationException keyed like the input (with `$prefix`), so the caller can show the
     * message at the right field.
     *
     * @param  array<string, mixed>  $merged  the result of StoryMotionSettings::toArray()
     */
    public static function assertComplete(array $merged, string $prefix = ''): void
    {
        $errors = [];

        if (data_get($merged, 'ai_notice.enabled') === true && trim((string) data_get($merged, 'ai_notice.text')) === '') {
            $errors[$prefix.'ai_notice.text'] = [__('settings.story_video.errors.notice_text_required')];
        }

        if (data_get($merged, 'ending.enabled') === true && trim((string) data_get($merged, 'ending.name')) === '' && trim((string) data_get($merged, 'ending.line')) === '') {
            $errors[$prefix.'ending.name'] = [__('settings.story_video.errors.ending_text_required')];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
