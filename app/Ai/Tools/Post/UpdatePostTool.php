<?php

declare(strict_types=1);

namespace App\Ai\Tools\Post;

use App\Actions\Post\UpdatePost;
use App\Ai\Tools\WorkspaceWriteTool;
use App\Enums\Post\Action as PostAction;
use App\Http\Resources\Chat\ChatPostResource;
use App\Support\PostStatusRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class UpdatePostTool extends WorkspaceWriteTool
{
    public function name(): string
    {
        return 'update_post';
    }

    public function description(): Stringable|string
    {
        return "Update an existing post's content in the current workspace. Only draft or scheduled posts can be edited — a post that has already been published, is currently publishing, or failed cannot be changed.";
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('The id of the post to update.'),
            'content' => $schema->string()->description('The new text content for the post.'),
        ];
    }

    protected function run(Request $request): string
    {
        $post = $this->resolvePost($request->string('post_id')->value());

        if ($post === null) {
            return $this->error(__('chat.tools.post_not_found'));
        }

        $data = [];

        if ($request->filled('content')) {
            $data['content'] = $request->string('content')->value();
        }

        $result = UpdatePost::execute($this->workspace, $post, $data);

        if (data_get($result, 'action') === PostAction::Finalized) {
            return $this->error(PostStatusRules::editBlockedMessage());
        }

        return $this->json([
            'data' => (new ChatPostResource($post->fresh()->load('postPlatforms.socialAccount')))->withFullContent()->resolve(),
        ]);
    }
}
