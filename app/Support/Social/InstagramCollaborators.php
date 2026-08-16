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
    public const string USERNAME_PATTERN = '^(?!.*\.\.)(?!\.)[A-Za-z0-9._]{1,30}(?<!\.)$';

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
        $meta['collaborators_with'] = self::display($meta['collaborators']);

        return $meta;
    }

    /**
     * Web may send a comma-separated field; API/MCP send a list. Same shaping either way.
     *
     * @return list<mixed>
     */
    public static function items(mixed $value): array
    {
        if (is_string($value)) {
            return array_values(array_filter(
                array_map(trim(...), preg_split('/[,\n]+/', $value) ?: []),
                fn (string $item): bool => $item !== '',
            ));
        }

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @return list<string>
     */
    public static function normalize(mixed $value): array
    {
        $seen = [];
        $usernames = [];

        foreach (self::items($value) as $item) {
            if (! is_string($item)) {
                continue;
            }

            $username = self::bare($item);
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

    /**
     * @param  list<string>  $usernames
     */
    public static function display(array $usernames): string
    {
        return implode(', ', array_map(
            fn (string $username): string => "@{$username}",
            $usernames,
        ));
    }

    public static function bare(string $username): string
    {
        return str_replace('@', '', trim($username));
    }

    public static function key(string $username): string
    {
        return mb_strtolower(self::bare($username));
    }

    public static function isSameUsername(string $left, ?string $right): bool
    {
        return $right !== null && self::key($left) !== '' && self::key($left) === self::key($right);
    }

    public static function isValidUsername(string $username): bool
    {
        return preg_match('/'.self::USERNAME_PATTERN.'/', self::bare($username)) === 1;
    }

    /**
     * Per-item reject reasons plus whether unique valid names exceed MAX.
     *
     * @return array{items: array<int|string, 'invalid'|'duplicate'|'self'>, exceedsMax: bool}
     */
    public static function failures(mixed $value, ?string $ownUsername): array
    {
        $seen = [];
        $items = [];

        foreach (self::items($value) as $index => $item) {
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
