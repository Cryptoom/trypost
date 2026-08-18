<?php

declare(strict_types=1);

namespace App\Http\Resources\Chat;

use App\Services\Post\PostMetricsFetcher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps a Post with its per-platform engagement metrics for the chat tools.
 * The actual fetching (with cache + per-platform dispatch) is delegated to
 * PostMetricsFetcher — the same service the MCP GetPostMetricsTool uses —
 * so there is exactly one metrics code path. Mirrors Api\PostMetricsResource.
 */
class ChatPostMetricsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'post_id' => $this->id,
            'platforms' => app(PostMetricsFetcher::class)->forPost($this->resource)->all(),
        ];
    }
}
