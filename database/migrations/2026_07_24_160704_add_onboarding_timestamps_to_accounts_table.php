<?php

declare(strict_types=1);

use App\Support\Onboarding\DismissAccountsWithAppAccess;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->timestamp('onboarding_completed_at')->nullable()->after('trial_ends_at');
            $table->timestamp('onboarding_dismissed_at')->nullable()->after('onboarding_completed_at');
        });

        // Match hasAppAccess() — active/trialing/past_due/grace + generic trial —
        // so existing customers never see the new residual activation banner.
        DismissAccountsWithAppAccess::run();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['onboarding_completed_at', 'onboarding_dismissed_at']);
        });
    }
};
