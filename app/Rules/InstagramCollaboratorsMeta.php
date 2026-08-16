<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\SocialAccount\Platform;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Support\PostPlatformMetaRules;
use App\Support\Social\InstagramCollaborators;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\ValidatorAwareRule;
use Illuminate\Support\Str;
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

        $platform = PostPlatformMetaRules::platformForAttribute($this->data, $attribute);

        if (! in_array($platform, [Platform::Instagram, Platform::InstagramFacebook], true)) {
            return;
        }

        if (count($value) > InstagramCollaborators::MAX) {
            $fail(__('posts.form.instagram.collaborators_max'));

            return;
        }

        $seen = [];
        $ownUsername = $this->ownUsernameFor($attribute);

        foreach ($value as $index => $item) {
            $itemAttribute = "{$attribute}.{$index}";

            if (! is_string($item) || preg_match('/^@?[A-Za-z0-9._]{1,30}$/', $item) !== 1) {
                $this->addItemError($itemAttribute, __('posts.form.instagram.collaborators_invalid'));

                continue;
            }

            $key = mb_strtolower(ltrim(trim($item), '@'));

            if (isset($seen[$key])) {
                $this->addItemError($itemAttribute, __('posts.form.instagram.collaborators_invalid'));

                continue;
            }

            $seen[$key] = true;

            if ($ownUsername !== null && InstagramCollaborators::isSameUsername($item, $ownUsername)) {
                $this->addItemError($itemAttribute, __('posts.form.instagram.collaborators_self'));
            }
        }
    }

    private function addItemError(string $attribute, string $message): void
    {
        $this->validator?->errors()->add($attribute, $message);
    }

    private function ownUsernameFor(string $attribute): ?string
    {
        $platformKey = Str::before($attribute, '.meta.');
        $accountId = data_get($this->data, "{$platformKey}.social_account_id");

        if (is_string($accountId) && Str::isUuid($accountId)) {
            return $this->normalizedUsername(SocialAccount::query()->find($accountId)?->username);
        }

        $postPlatformId = data_get($this->data, "{$platformKey}.id");

        if (is_string($postPlatformId) && Str::isUuid($postPlatformId)) {
            return $this->normalizedUsername(
                PostPlatform::query()->with('socialAccount')->find($postPlatformId)?->socialAccount?->username,
            );
        }

        return null;
    }

    private function normalizedUsername(mixed $username): ?string
    {
        return InstagramCollaborators::normalize(is_string($username) ? [$username] : [])[0] ?? null;
    }
}
