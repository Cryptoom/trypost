<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Single source of truth for the user-supplied AI generation prompt length,
 * shared by the chat's generate_post tool and the editor's content generation
 * (GeneratePostContentRequest).
 */
class AiPromptRules
{
    /**
     * Minimum prompt length (characters) for AI post generation.
     */
    public const PROMPT_MIN_LENGTH = 3;

    /**
     * Maximum prompt length (characters).
     */
    public const PROMPT_MAX_LENGTH = 2000;

    /**
     * Validation rules for the generation prompt. The editor's content
     * generation reuses only PROMPT_MAX_LENGTH — it has no minimum, since it
     * has no character counter to mirror one.
     *
     * @return array<int, string>
     */
    public static function generationPromptRule(): array
    {
        return ['required', 'string', 'min:'.self::PROMPT_MIN_LENGTH, 'max:'.self::PROMPT_MAX_LENGTH];
    }
}
