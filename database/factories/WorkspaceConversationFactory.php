<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WorkspaceConversation\Status;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceConversation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceConversation>
 */
class WorkspaceConversationFactory extends Factory
{
    protected $model = WorkspaceConversation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'status' => Status::Idle,
        ];
    }

    public function untitled(): static
    {
        return $this->state(fn (): array => ['title' => null]);
    }

    public function inProgress(): static
    {
        return $this->state(fn (): array => ['status' => Status::InProgress]);
    }
}
