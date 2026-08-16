<?php

declare(strict_types=1);

namespace App\Support\Social;

final class InstagramCollaborators
{
    public const int MAX = 3;

    /**
     * Instagram usernames: 1–30 letters, numbers, periods, underscores.
     * No leading/trailing period and no consecutive periods.
     */
    public const string USERNAME_PATTERN = '/^(?!.*\.\.)(?!\.)[A-Za-z0-9._]{1,30}(?<!\.)$/';

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
            $key = self::key($username);

            if ($key === '' || isset($seen[$key]) || ! self::isValidUsername($username)) {
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

    public static function key(string $username): string
    {
        return mb_strtolower(ltrim(trim($username), '@'));
    }

    public static function isSameUsername(string $left, ?string $right): bool
    {
        return $right !== null && self::key($left) !== '' && self::key($left) === self::key($right);
    }

    public static function isValidUsername(string $username): bool
    {
        return preg_match(self::USERNAME_PATTERN, ltrim(trim($username), '@')) === 1;
    }

    /**
     * Per-item reject reasons plus whether unique valid names exceed MAX.
     *
     * @param  array<int|string, mixed>  $usernames
     * @return array{items: array<int|string, 'invalid'|'duplicate'|'self'>, exceedsMax: bool}
     */
    public static function failures(array $usernames, ?string $ownUsername): array
    {
        $seen = [];
        $items = [];

        foreach ($usernames as $index => $item) {
            $reason = self::itemFailure($item, $seen, $ownUsername);

            if ($reason !== null) {
                $items[$index] = $reason;
            }
        }

        return [
            'items' => $items,
            'exceedsMax' => count($seen) > self::MAX,
        ];
    }

    /**
     * @param  array<string, true>  $seen
     * @return 'invalid'|'duplicate'|'self'|null
     */
    private static function itemFailure(mixed $item, array &$seen, ?string $ownUsername): ?string
    {
        if (! is_string($item) || ! self::isValidUsername($item)) {
            return 'invalid';
        }

        $key = self::key($item);

        if (isset($seen[$key])) {
            return 'duplicate';
        }

        $seen[$key] = true;

        return self::isSameUsername($item, $ownUsername) ? 'self' : null;
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
}
