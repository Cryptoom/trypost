<?php

declare(strict_types=1);

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
        Schema::create('checkout_purchase_trackings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->string('session_id');
            $table->string('kind', 20);
            $table->json('payload')->nullable();
            $table->timestamp('verified_at');
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'session_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checkout_purchase_trackings');
    }
};
