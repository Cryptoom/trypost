<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\SocialAccount\Platform;
use App\Support\PostPlatformMetaRules;
use App\Support\Social\InstagramCollaborators;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Validation\Validator;

/**
 * Instagram-only collaborator shape (max 3, username, not self). Other networks
 * may reuse `meta.collaborators` without inheriting these constraints.
 */
class InstagramCollaboratorsMeta implements DataAwareRule, ValidationRule, ValidatorAwareRule
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

        $account = PostPlatformMetaRules::accountForAttribute($this->data, $attribute);

        if (! in_array($account?->platform, [Platform::Instagram, Platform::InstagramFacebook], true)) {
            return;
        }

        $seen = [];

        foreach ($value as $index => $item) {
            $itemAttribute = "{$attribute}.{$index}";

            if (! is_string($item) || ! InstagramCollaborators::isValidUsername($item)) {
                $this->validator?->errors()->add($itemAttribute, __('posts.form.instagram.collaborators_invalid'));

                continue;
            }

            $key = InstagramCollaborators::key($item);

            if (isset($seen[$key])) {
                $this->validator?->errors()->add($itemAttribute, __('posts.form.instagram.collaborators_duplicate'));

                continue;
            }

            $seen[$key] = true;

            if (InstagramCollaborators::isSameUsername($item, $account->username)) {
                $this->validator?->errors()->add($itemAttribute, __('posts.form.instagram.collaborators_self'));
            }
        }

        if (count($seen) > InstagramCollaborators::MAX) {
            $fail(__('posts.form.instagram.collaborators_max'));
        }
    }
}
