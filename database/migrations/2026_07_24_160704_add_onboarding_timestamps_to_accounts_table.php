<?php

declare(strict_types=1);

use App\Models\Account;
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

        Account::query()
            ->whereHas('subscriptions', function ($query) {
                $query->where('type', Account::SUBSCRIPTION_NAME)
                    ->whereIn('stripe_status', ['active', 'trialing']);
            })
            ->whereNull('onboarding_dismissed_at')
            ->whereNull('onboarding_completed_at')
            ->update(['onboarding_dismissed_at' => now()]);
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
