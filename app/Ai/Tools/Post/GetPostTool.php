<?php

declare(strict_types=1);

namespace App\Ai\Tools\Post;

use App\Ai\Tools\WorkspaceTool;
use App\Http\Resources\Chat\ChatPostResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetPostTool extends WorkspaceTool
{
    public function name(): string
    {
        return 'get_post';
    }

    public function description(): Stringable|string
    {
        return 'Get a single post from the current workspace by id, including its per-platform status.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('The id of the post to fetch.'),
        ];
    }

    protected function run(Request $request): string
    {
        $post = $this->resolvePost($request->string('post_id')->value());

        if (! $post) {
            return $this->error(__('chat.tools.post_not_found'));
        }

        return $this->json(['data' => (new ChatPostResource($post))->resolve()]);
    }
}
