<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Models\SocialAccount;
use App\Services\Social\PinterestPublisher;
use Illuminate\Support\Collection;

class ListPinterestBoards
{
    /**
     * @return array{boards: list<array{id: string, name: string}>, truncated: bool}
     */
    public static function execute(SocialAccount $account): array
    {
        $result = app(PinterestPublisher::class)->getBoards($account);

        $boards = Collection::make(data_get($result, 'boards', []))
            ->map(fn (mixed $board): array => [
                'id' => (string) data_get($board, 'id'),
                'name' => (string) data_get($board, 'name'),
            ])
            ->filter(fn (array $board): bool => $board['id'] !== '')
            ->values()
            ->all();

        return [
            'boards' => $boards,
            'truncated' => (bool) data_get($result, 'truncated', false),
        ];
    }
}
