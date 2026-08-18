<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WorkspaceConversation\Message\Role;
use App\Models\WorkspaceConversation;
use App\Models\WorkspaceConversationMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceConversationMessage>
 */
class WorkspaceConversationMessageFactory extends Factory
{
    protected $model = WorkspaceConversationMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_conversation_id' => WorkspaceConversation::factory(),
            'role' => Role::User,
            'content' => fake()->sentence(),
        ];
    }
}
