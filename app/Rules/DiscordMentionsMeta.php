<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\SocialAccount\Platform;
use App\Support\PostPlatformMetaRules;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Support\Arr;
use Illuminate\Validation\Validator;

/**
 * Discord-only mention shape (`[{token, label}]`). Other networks may reuse
 * `meta.mentions` without inheriting Discord's object items.
 */
class DiscordMentionsMeta implements DataAwareRule, ValidationRule, ValidatorAwareRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    private ?Validator $validator = null;

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function setValidator(Validator $validator): static
    {
        $this->validator = $validator;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || PostPlatformMetaRules::platformForAttribute($this->data, $attribute) !== Platform::Discord) {
            return;
        }

        $data = [];
        Arr::set($data, $attribute, $value);

        $nested = validator($data, [
            "{$attribute}.*.token" => ['required', 'string'],
            "{$attribute}.*.label" => ['nullable', 'string'],
        ]);

        if ($nested->fails()) {
            $this->validator?->errors()->merge($nested->errors());
        }
    }
}
