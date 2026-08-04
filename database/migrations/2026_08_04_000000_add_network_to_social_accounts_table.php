<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->string('network')->nullable()->after('platform');
        });

        if (! config('trypost.self_hosted')) {
            $seen = [];

            DB::table('social_accounts')
                ->select(['id', 'workspace_id', 'platform'])
                ->orderBy('id')
                ->get()
                ->each(function (object $account) use (&$seen): void {
                    $platform = Platform::tryFrom((string) $account->platform);

                    if ($platform === null) {
                        return;
                    }

                    $network = $platform->network();
                    $key = "{$account->workspace_id}:{$network}";

                    if (isset($seen[$key])) {
                        return;
                    }

                    DB::table('social_accounts')
                        ->where('id', $account->id)
                        ->update(['network' => $network]);
                    $seen[$key] = true;
                });
        }

        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->unique(
                ['workspace_id', 'network'],
                'social_accounts_workspace_network_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table): void {
            $table->dropUnique('social_accounts_workspace_network_unique');
            $table->dropColumn('network');
        });
    }
};
