<?php

declare(strict_types=1);

namespace App\Ai\Tools\Post;

use App\Actions\Post\CreatePost;
use App\Ai\Tools\WorkspaceTool;
use App\Http\Resources\Chat\ChatPostResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreatePostTool extends WorkspaceTool
{
    public function name(): string
    {
        return 'create_post';
    }

    public function description(): Stringable|string
    {
        return 'Create a new draft post in the current workspace with the given content. The post starts as a draft with no platforms enabled — use update_post to attach platforms and schedule_post to schedule it.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'content' => $schema->string()->required()->description('The text content of the new draft post.'),
        ];
    }

    protected function run(Request $request): string
    {
        $post = CreatePost::execute($this->workspace, $this->user, [
            'content' => $request->string('content')->value(),
        ]);

        return $this->json([
            'data' => (new ChatPostResource($post->load('postPlatforms.socialAccount')))->resolve(),
        ]);
    }
}
