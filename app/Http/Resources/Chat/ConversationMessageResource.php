<?php

declare(strict_types=1);

namespace App\Http\Resources\Chat;

use App\Models\WorkspaceConversationMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A stored turn, with its tool result cards rebuilt for display.
 *
 * `payloads` maps each of this message's tool call ids to the JSON payload
 * the frontend component registry renders — replayed fresh for read tools,
 * or the original stored result for write tools. See ToolReplayer.
 */
class ConversationMessageResource extends JsonResource
{
    /**
     * @param  array<string, string>  $payloads  tool call id => JSON payload, from ToolReplayer::replay()
     */
    public function __construct(WorkspaceConversationMessage $message, private readonly array $payloads = [])
    {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role?->value,
            'content' => $this->content,
            'tool_calls' => $this->tool_calls,
            'payloads' => collect($this->tool_calls ?? [])
                ->mapWithKeys(function (array $call): array {
                    $id = (string) data_get($call, 'id');

                    return [$id => data_get($this->payloads, $id, '')];
                })
                ->all(),
        ];
    }
}
