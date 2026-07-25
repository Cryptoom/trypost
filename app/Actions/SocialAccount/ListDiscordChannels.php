<?php

declare(strict_types=1);

namespace App\Actions\SocialAccount;

use App\Models\SocialAccount;
use App\Services\Social\Discord\DiscordClient;

class ListDiscordChannels
{
    /**
     * @return list<array{id: string, name: string}>
     */
    public static function execute(SocialAccount $account): array
    {
        return app(DiscordClient::class)->channels((string) $account->platform_user_id);
    }
}
