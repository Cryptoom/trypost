<?php

declare(strict_types=1);

namespace App\Ai\Tools\Post;

use App\Actions\Post\UpdatePost;
use App\Ai\Tools\WorkspaceTool;
use App\Enums\Post\Action as PostAction;
use App\Enums\Post\Status;
use App\Http\Resources\Chat\ChatPostResource;
use App\Support\PostStatusRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class SchedulePostTool extends WorkspaceTool
{
    public function name(): string
    {
        return 'schedule_post';
    }

    public function description(): Stringable|string
    {
        return 'Schedule a draft post in the current workspace to publish at a future date and time. Only draft or already-scheduled posts can be scheduled.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('The id of the post to schedule.'),
            'scheduled_at' => $schema->string()->required()->description('The date and time to publish the post, in ISO 8601 format.'),
        ];
    }

    protected function run(Request $request): string
    {
        $post = $this->resolvePost($request->string('post_id')->value());

        if ($post === null) {
            return $this->error(__('chat.tools.post_not_found'));
        }

        if (! $request->filled('scheduled_at')) {
            return $this->error(__('chat.tools.scheduled_at_required'));
        }

        $result = UpdatePost::execute($this->workspace, $post, [
            'scheduled_at' => $request->string('scheduled_at')->value(),
            'status' => Status::Scheduled->value,
        ]);

        if (data_get($result, 'action') === PostAction::Finalized) {
            return $this->error(PostStatusRules::editBlockedMessage());
        }

        return $this->json([
            'data' => (new ChatPostResource($post->fresh()->load('postPlatforms.socialAccount')))->resolve(),
        ]);
    }
}
