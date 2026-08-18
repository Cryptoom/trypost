<?php

declare(strict_types=1);

namespace App\Http\Resources\Chat;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A conversation as listed in the sidebar or opened for a full turn history.
 * `updated_at` is sent as ISO-8601 — the frontend groups conversations by
 * date locally, in the user's timezone.
 */
class ConversationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status?->value,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
