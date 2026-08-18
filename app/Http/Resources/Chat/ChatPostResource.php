<?php

declare(strict_types=1);

namespace App\Http\Resources\Chat;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Deliberately leaner than Api\PostResource: every field here is paid for
 * twice, once in the model's context window and once on the wire, so only
 * what the chat tools need to reason about a post is included.
 */
class ChatPostResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'content' => str($this->content)->limit(280)->value(),
            'status' => $this->status?->value,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'published_at' => $this->published_at?->toIso8601String(),
            'platforms' => $this->whenLoaded('postPlatforms', fn (): array => $this->postPlatforms
                ->map(fn ($platform): array => [
                    'platform' => $platform->socialAccount?->platform?->value,
                    'handle' => $platform->socialAccount?->username,
                    'status' => $platform->status?->value,
                ])->all()),
        ];
    }
}
