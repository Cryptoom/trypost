<?php

declare(strict_types=1);

namespace App\Http\Resources\Chat;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Deliberately leaner than Api\PostResource: every field here is paid for
 * twice, once in the model's context window and once on the wire, so only
 * what the chat tools need to reason about a post is included.
 *
 * Content is the one field that cannot be lean everywhere. A LIST of posts
 * carries a preview, because the cap exists to bound token cost across many
 * rows; a SINGLE post carries the whole thing, because that cost does not
 * apply and because update_post writes free-form content back. A model that
 * read 280 characters and rewrote them would persist the preview as the
 * whole post and silently destroy the rest. Where the preview is still what
 * the model sees, `content_truncated` says so explicitly and the system
 * prompt forbids rewriting from it.
 */
class ChatPostResource extends JsonResource
{
    /**
     * How much of a post's content a list entry carries.
     */
    private const PREVIEW_LENGTH = 280;

    private bool $fullContent = false;

    /**
     * Carry the post's whole content rather than a preview. For single-post
     * payloads only — never for a collection.
     */
    public function withFullContent(): static
    {
        $this->fullContent = true;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $content = (string) $this->content;
        $preview = str($content)->limit(self::PREVIEW_LENGTH)->value();
        $truncated = ! $this->fullContent && $preview !== $content;

        return [
            'id' => $this->id,
            'content' => $truncated ? $preview : $content,
            'content_truncated' => $truncated,
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
