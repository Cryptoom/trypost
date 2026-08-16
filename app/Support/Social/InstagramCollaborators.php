<?php

declare(strict_types=1);

namespace App\Support\Social;

use App\Enums\PostPlatform\Status;
use App\Enums\SocialAccount\Platform;
use App\Models\PostPlatform;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class InstagramCollaborators
{
    public const int MAX = 3;

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function applyToMeta(array $meta): array
    {
        if (! array_key_exists('collaborators', $meta)) {
            return $meta;
        }

        $meta['collaborators'] = self::normalize($meta['collaborators']);

        return $meta;
    }

    /**
     * @return list<string>
     */
    public static function normalize(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $seen = [];
        $usernames = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }

            $username = ltrim(trim($item), '@');

            if ($username === '') {
                continue;
            }

            $key = mb_strtolower($username);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $usernames[] = $username;

            if (count($usernames) === self::MAX) {
                break;
            }
        }

        return $usernames;
    }

    public static function isSameUsername(string $left, ?string $right): bool
    {
        $leftUsername = self::normalize([$left])[0] ?? null;
        $rightUsername = self::normalize([$right ?? ''])[0] ?? null;

        if ($leftUsername === null || $rightUsername === null) {
            return false;
        }

        return mb_strtolower($leftUsername) === mb_strtolower($rightUsername);
    }

    /**
     * Graph expects a JSON array string, not a PHP form array (`collaborators[0]=…`).
     *
     * @return array<string, string>
     */
    public static function payload(mixed $value, ?string $ownUsername = null): array
    {
        $usernames = array_values(array_filter(
            self::normalize($value),
            fn (string $username): bool => ! self::isSameUsername($username, $ownUsername),
        ));

        if ($usernames === []) {
            return [];
        }

        return ['collaborators' => json_encode($usernames, JSON_THROW_ON_ERROR)];
    }

    /**
     * @return array{status_available: bool, collaborators: list<array{username: string, invite_status: string|null}>}
     */
    public static function fetchInviteStatus(PostPlatform $postPlatform): array
    {
        $stored = self::normalize(data_get($postPlatform->meta, 'collaborators'));
        $fallback = [
            'status_available' => false,
            'collaborators' => array_map(
                fn (string $username): array => ['username' => $username, 'invite_status' => null],
                $stored,
            ),
        ];

        if (
            $postPlatform->platform !== Platform::InstagramFacebook
            || $postPlatform->status !== Status::Published
            || blank($postPlatform->platform_post_id)
            || $stored === []
        ) {
            return $fallback;
        }

        $account = $postPlatform->socialAccount;

        if ($account === null || blank($account->access_token)) {
            return $fallback;
        }

        $baseUrl = $account->platform->instagramGraphBaseUrl();

        try {
            $response = Http::timeout(30)->get("{$baseUrl}/{$postPlatform->platform_post_id}/collaborators", [
                'access_token' => $account->access_token,
            ]);
        } catch (ConnectionException) {
            return $fallback;
        }

        if ($response->failed()) {
            return $fallback;
        }

        $rows = data_get($response->json(), 'data', []);

        if (! is_array($rows)) {
            return $fallback;
        }

        $byUsername = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $username = self::normalize([data_get($row, 'username')])[0] ?? null;

            if ($username === null) {
                continue;
            }

            $byUsername[mb_strtolower($username)] = [
                'username' => $username,
                'invite_status' => self::normalizeInviteStatus(data_get($row, 'invite_status')),
            ];
        }

        return [
            'status_available' => true,
            'collaborators' => array_map(
                fn (string $username): array => $byUsername[mb_strtolower($username)] ?? [
                    'username' => $username,
                    'invite_status' => null,
                ],
                $stored,
            ),
        ];
    }

    private static function normalizeInviteStatus(mixed $status): ?string
    {
        if (! is_string($status) || $status === '') {
            return null;
        }

        return match (mb_strtolower($status)) {
            'accepted', 'accpeted' => 'Accepted',
            'pending' => 'Pending',
            'declined' => 'Declined',
            default => $status,
        };
    }
}
