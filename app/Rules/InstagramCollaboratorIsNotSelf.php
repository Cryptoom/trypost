<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Support\Social\InstagramCollaborators;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

/**
 * Rejects tagging the connected Instagram account as a collaborator on itself.
 *
 * Create flows send `platforms[].social_account_id`; update flows send
 * `platforms[].id` (the post_platform row). Either is enough to resolve the
 * account username.
 */
class InstagramCollaboratorIsNotSelf implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    /** @var array<string, string|null> */
    private array $ownUsernames = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $ownUsername = $this->ownUsernameFor($attribute);

        if ($ownUsername === null) {
            return;
        }

        if (InstagramCollaborators::isSameUsername($value, $ownUsername)) {
            $fail(__('posts.form.instagram.collaborators_self'));
        }
    }

    private function ownUsernameFor(string $attribute): ?string
    {
        $platformKey = Str::before($attribute, '.meta.');
        $cacheKey = $platformKey;

        if (array_key_exists($cacheKey, $this->ownUsernames)) {
            return $this->ownUsernames[$cacheKey];
        }

        $accountId = data_get($this->data, "{$platformKey}.social_account_id");

        if (is_string($accountId) && Str::isUuid($accountId)) {
            return $this->ownUsernames[$cacheKey] = $this->normalizedUsername(
                SocialAccount::query()->find($accountId)?->username,
            );
        }

        $postPlatformId = data_get($this->data, "{$platformKey}.id");

        if (is_string($postPlatformId) && Str::isUuid($postPlatformId)) {
            return $this->ownUsernames[$cacheKey] = $this->normalizedUsername(
                PostPlatform::query()->with('socialAccount')->find($postPlatformId)?->socialAccount?->username,
            );
        }

        return $this->ownUsernames[$cacheKey] = null;
    }

    private function normalizedUsername(mixed $username): ?string
    {
        $normalized = InstagramCollaborators::normalize(is_string($username) ? [$username] : []);

        return $normalized[0] ?? null;
    }
}
