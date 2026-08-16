<?php

declare(strict_types=1);

namespace App\Support\Social;

use Illuminate\Support\Collection;

final class InstagramCollaborators
{
    public const int MAX = 3;

    private const string USERNAME_PATTERN = '/^(?!.*\.\.)(?!\.)[A-Za-z0-9._]{1,30}(?<!\.)$/';

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

    /** @return list<string> */
    public static function normalize(mixed $value): array
    {
        return self::items($value)
            ->filter(fn (mixed $item): bool => is_string($item) && self::isValidUsername($item))
            ->map(self::bare(...))
            ->unique(self::key(...))
            ->take(self::MAX)
            ->values()
            ->all();
    }

    /** @param  list<string>  $usernames */
    public static function display(array $usernames): string
    {
        return collect($usernames)->map(fn (string $username): string => "@{$username}")->implode(', ');
    }

    public static function isSameUsername(string $left, ?string $right): bool
    {
        return filled($right) && ($key = self::key($left)) !== '' && $key === self::key($right);
    }

    public static function isValidUsername(string $username): bool
    {
        return preg_match(self::USERNAME_PATTERN, self::bare($username)) === 1;
    }

    /**
     * @return array{items: array<int|string, 'invalid'|'duplicate'|'self'>, exceedsMax: bool}
     */
    public static function failures(mixed $value, ?string $ownUsername): array
    {
        $seen = [];
        $items = [];

        foreach (self::items($value) as $index => $item) {
            if (! is_string($item) || ! self::isValidUsername($item)) {
                $items[$index] = 'invalid';

                continue;
            }

            $key = self::key($item);

            if (isset($seen[$key])) {
                $items[$index] = 'duplicate';

                continue;
            }

            $seen[$key] = true;

            if (self::isSameUsername($item, $ownUsername)) {
                $items[$index] = 'self';
            }
        }

        return ['items' => $items, 'exceedsMax' => count($seen) > self::MAX];
    }

    /** @return array<string, string> */
    public static function payload(mixed $value, ?string $ownUsername = null): array
    {
        $usernames = collect(self::normalize($value))
            ->reject(fn (string $username): bool => self::isSameUsername($username, $ownUsername));

        return $usernames->isEmpty() ? [] : ['collaborators' => $usernames->values()->toJson(JSON_THROW_ON_ERROR)];
    }

    private static function items(mixed $value): Collection
    {
        return is_string($value)
            ? str($value)->explode(',')->map(trim(...))->filter()->values()
            : collect(is_array($value) ? $value : [])->values();
    }

    private static function bare(string $username): string
    {
        return str_replace('@', '', trim($username));
    }

    private static function key(string $username): string
    {
        return mb_strtolower(self::bare($username));
    }
}
