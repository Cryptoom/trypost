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
 *
 * `parts` is the turn's text and tool cards in the order the model produced
 * them, so a sentence said before a tool call renders above the card it
 * introduces. A tool part names its call id only; its payload is read from
 * `payloads` under that id. Null on every row stored before the column
 * existed, which the frontend falls back to `tool_calls` + `content` for.
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
            'parts' => $this->parts,
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
