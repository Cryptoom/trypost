<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_conversation_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('workspace_conversation_id');
            $table->string('role', 25);
            $table->text('content');
            $table->json('tool_calls')->nullable();
            $table->json('tool_results')->nullable();
            $table->json('usage')->nullable();
            $table->json('meta')->nullable();
            $table->json('approval_state')->nullable();
            $table->timestamps();

            $table->foreign('workspace_conversation_id')
                ->references('id')->on('workspace_conversations')->cascadeOnDelete();
            $table->index(['workspace_conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_conversation_messages');
    }
};
