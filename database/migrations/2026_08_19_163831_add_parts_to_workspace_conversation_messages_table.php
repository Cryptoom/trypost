<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_conversation_messages', function (Blueprint $table) {
            $table->json('parts')->nullable()->after('tool_results');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_conversation_messages', function (Blueprint $table) {
            $table->dropColumn('parts');
        });
    }
};
