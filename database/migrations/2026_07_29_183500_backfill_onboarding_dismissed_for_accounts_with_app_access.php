<?php

declare(strict_types=1);

use App\Support\Onboarding\DismissAccountsWithAppAccess;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Re-run the legacy backfill for environments that already applied the
     * narrower active/trialing-only stamp before past_due/grace/generic trial
     * accounts were included.
     */
    public function up(): void
    {
        DismissAccountsWithAppAccess::run();
    }
};
