<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pivot for per-platform media selection: which of a post's media items
     * apply to a given platform. Additive and fully backward compatible:
     * an empty pivot set for a platform means "all of the post's media",
     * today's behaviour, so no special-casing is needed anywhere reading it.
     *
     * A dedicated `id` primary key (instead of the plain composite-key pivot
     * used by `post_workspace_label`) is deliberate: it makes an individual
     * row directly addressable for debugging (single id in a log line,
     * `firstOrFail()` by id) rather than needing both foreign keys every
     * time. Uniqueness is still enforced separately below.
     */
    public function up(): void
    {
        Schema::create('media_post_platform', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('media_id');
            $table->uuid('post_platform_id');
            $table->timestamps();

            $table->foreign('media_id')->references('id')->on('medias')->cascadeOnDelete();
            $table->foreign('post_platform_id')->references('id')->on('post_platforms')->cascadeOnDelete();

            $table->unique(['media_id', 'post_platform_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_post_platform');
    }
};
