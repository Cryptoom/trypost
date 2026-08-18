<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use App\Ai\Tools\Post\CreatePostTool;
use App\Ai\Tools\Post\DeletePostTool;
use App\Ai\Tools\Post\GetPostMetricsTool;
use App\Ai\Tools\Post\GetPostTool;
use App\Ai\Tools\Post\ListPostsTool;
use App\Ai\Tools\Post\PublishPostTool;
use App\Ai\Tools\Post\SchedulePostTool;
use App\Ai\Tools\Post\UpdatePostTool;
use App\Models\User;
use App\Models\Workspace;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;

/**
 * The workspace chat agent: carries the system prompt, exposes the eight
 * post tools scoped to the workspace, and remembers conversation history
 * via the SDK's conversation store.
 *
 * Deliberately does NOT define messages() — that would take precedence over
 * RemembersConversations and silently disable history loading.
 */
#[MaxSteps(8)]
#[Timeout(180)]
class WorkspaceConversationAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(
        public Workspace $workspace,
        public User $user,
    ) {}

    public function instructions(): string
    {
        return view('prompts.conversation.assistant', [
            'brand_name' => $this->workspace->name ?? '',
            'brand_description' => $this->workspace->brand_description ?? '',
            'brand_voice_traits' => $this->workspace->brand_voice_traits ?? [],
            'content_language' => $this->workspace->content_language,
            'connected_platforms' => $this->workspace->socialAccounts()
                ->get()
                ->map(fn ($account): string => $account->platform->value)
                ->unique()
                ->values()
                ->all(),
        ])->render();
    }

    /**
     * @return iterable<int, Tool>
     */
    public function tools(): iterable
    {
        return [
            new ListPostsTool($this->workspace, $this->user),
            new GetPostTool($this->workspace, $this->user),
            new GetPostMetricsTool($this->workspace, $this->user),
            new CreatePostTool($this->workspace, $this->user),
            new UpdatePostTool($this->workspace, $this->user),
            new SchedulePostTool($this->workspace, $this->user),
            new PublishPostTool($this->workspace, $this->user),
            new DeletePostTool($this->workspace, $this->user),
        ];
    }
}
