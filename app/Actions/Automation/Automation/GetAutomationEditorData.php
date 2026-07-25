<?php

declare(strict_types=1);

namespace App\Actions\Automation\Automation;

use App\Actions\SocialAccount\ListPinterestBoards;
use App\Enums\SocialAccount\Platform;
use App\Models\Automation;
use App\Models\SocialAccount;
use App\Services\Social\TikTokCreatorInfo;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

class GetAutomationEditorData
{
    public function __construct(
        private TikTokCreatorInfo $tikTokCreatorInfo,
    ) {}

    /**
     * @return array{
     *     socialAccounts: Collection<int, SocialAccount>,
     *     pinterestBoards: SupportCollection<string, array{boards: list<array{id: string, name: string}>, truncated: bool}>,
     *     tiktokCreatorInfos: SupportCollection<string, mixed>,
     * }
     */
    public function __invoke(Automation $automation): array
    {
        $socialAccounts = $automation->workspace->socialAccounts()->active()->get();

        $pinterestBoards = $socialAccounts
            ->where('platform', Platform::Pinterest)
            ->mapWithKeys(fn ($account) => [
                $account->id => rescue(
                    fn () => ListPinterestBoards::execute($account),
                    ['boards' => [], 'truncated' => false],
                    report: false,
                ),
            ]);

        $tiktokCreatorInfos = $socialAccounts
            ->where('platform', Platform::TikTok)
            ->mapWithKeys(fn ($account) => [
                $account->id => rescue(
                    fn () => $this->tikTokCreatorInfo->fetch($account),
                    null,
                    report: false,
                ),
            ])
            ->filter();

        return [
            'socialAccounts' => $socialAccounts,
            'pinterestBoards' => $pinterestBoards,
            'tiktokCreatorInfos' => $tiktokCreatorInfos,
        ];
    }
}
