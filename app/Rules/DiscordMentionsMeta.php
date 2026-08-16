<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\SocialAccount\Platform;
use App\Support\PostPlatformMetaRules;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
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
        if (! is_array($value)) {
            return;
        }

        if (PostPlatformMetaRules::platformForAttribute($this->data, $attribute) !== Platform::Discord) {
            return;
        }

        foreach ($value as $index => $item) {
            if (! is_array($item) || ! is_string(data_get($item, 'token')) || data_get($item, 'token') === '') {
                $this->validator?->errors()->add("{$attribute}.{$index}.token", __('validation.required', [
                    'attribute' => 'token',
                ]));

                continue;
            }

            $label = data_get($item, 'label');

            if ($label !== null && ! is_string($label)) {
                $this->validator?->errors()->add("{$attribute}.{$index}.label", __('validation.string', [
                    'attribute' => 'label',
                ]));
            }
        }
    }
}
