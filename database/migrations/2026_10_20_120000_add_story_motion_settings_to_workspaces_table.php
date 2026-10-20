<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            // PATCH:story-motion-settings: the workspace's Story video look as one JSON
            // object (App\Services\Media\StoryMotionSettings). NULL means the defaults,
            // and the default is off, so existing workspaces are unchanged.
            $table->json('story_motion_settings')->nullable()->after('content_language');
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropColumn('story_motion_settings');
        });
    }
};
