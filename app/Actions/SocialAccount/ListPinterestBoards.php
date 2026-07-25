<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Models\SocialAccount;
use App\Services\Social\PinterestPublisher;
use Illuminate\Support\Collection;

class ListPinterestBoards
{
    /**
     * @return list<array{id: string, name: string}>
     */
    public static function execute(SocialAccount $account): array
    {
        $boards = app(PinterestPublisher::class)->getBoards($account);

        return Collection::make($boards)
            ->map(fn (mixed $board): array => [
                'id' => (string) data_get($board, 'id'),
                'name' => (string) data_get($board, 'name'),
            ])
            ->filter(fn (array $board): bool => $board['id'] !== '')
            ->values()
            ->all();
    }
}
