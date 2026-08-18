<?php

declare(strict_types=1);

namespace App\Ai\Tools\Post;

use App\Ai\Tools\WorkspaceTool;
use App\Enums\Post\Status;
use App\Http\Resources\Chat\ChatPostResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListPostsTool extends WorkspaceTool
{
    public function name(): string
    {
        return 'list_posts';
    }

    public function description(): Stringable|string
    {
        return 'List posts in the current workspace, newest first. Filter by status (draft, scheduled, published, failed) and by a free-text search over post content.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(['draft', 'scheduled', 'published', 'failed']),
            'search' => $schema->string(),
            'limit' => $schema->integer()->min(1)->max(25),
        ];
    }

    protected function run(Request $request): string
    {
        $arguments = $request->toArray();

        $query = $this->workspace->posts()->with(['postPlatforms.socialAccount']);

        $query = match (data_get($arguments, 'status')) {
            Status::Draft->value => $query->draft(),
            Status::Scheduled->value => $query->scheduled(),
            Status::Published->value => $query->published(),
            Status::Failed->value => $query->failed(),
            default => $query,
        };

        if ($search = data_get($arguments, 'search')) {
            $query->where('content', 'ilike', "%{$search}%");
        }

        $posts = $query->latest('created_at')->limit((int) data_get($arguments, 'limit', 10))->get();

        return $this->json(['data' => ChatPostResource::collection($posts)->resolve()]);
    }
}
