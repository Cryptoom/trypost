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
        $query = $this->workspace->posts()->with(['postPlatforms.socialAccount']);

        $query = match ($request->enum('status', Status::class)) {
            Status::Draft => $query->draft(),
            Status::Scheduled => $query->scheduled(),
            Status::Published => $query->published(),
            Status::Failed => $query->failed(),
            default => $query,
        };

        $search = $request->string('search')->value();

        if ($search !== '') {
            $query->whereLike('content', "%{$search}%", caseSensitive: false);
        }

        $limit = (int) $request->clamp('limit', 1, 25, 10);

        $posts = $query->latest('created_at')->limit($limit)->get();

        return $this->json(['data' => ChatPostResource::collection($posts)->resolve()]);
    }
}
