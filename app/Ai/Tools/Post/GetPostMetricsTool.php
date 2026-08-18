<?php

declare(strict_types=1);

namespace App\Ai\Tools\Post;

use App\Ai\Tools\WorkspaceTool;
use App\Http\Resources\Chat\ChatPostMetricsResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetPostMetricsTool extends WorkspaceTool
{
    public function name(): string
    {
        return 'get_post_metrics';
    }

    public function description(): Stringable|string
    {
        return 'Fetch engagement metrics (likes, comments, shares, etc.) for a published post across all platforms it was posted to. Returns an "unsupported" entry for platforms that do not expose post-level metrics or that have not published this post yet.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('The id of the post to fetch metrics for.'),
        ];
    }

    protected function run(Request $request): string
    {
        $post = $this->resolvePost(data_get($request->toArray(), 'post_id'));

        if (! $post) {
            return $this->error(__('chat.tools.post_not_found'));
        }

        return $this->json(['data' => (new ChatPostMetricsResource($post))->resolve()]);
    }
}
