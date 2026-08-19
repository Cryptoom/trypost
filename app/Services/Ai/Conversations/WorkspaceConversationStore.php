<?php

declare(strict_types=1);

namespace App\Services\Ai\Conversations;

use App\Enums\WorkspaceConversation\Message\Role;
use App\Models\WorkspaceConversation;
use App\Models\WorkspaceConversationMessage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Approvals\PendingApproval;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Exceptions\ApprovalMismatchException;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\StreamEvent;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use RuntimeException;

/**
 * Persists agent conversations into the application's own
 * workspace_conversations tables instead of the SDK's generic
 * agent_conversations tables, so a conversation is owned by a
 * (workspace, user) pair rather than a single polymorphic participant.
 *
 * Mirrors Laravel\Ai\Storage\DatabaseConversationStore against our columns.
 */
class WorkspaceConversationStore implements ConversationStore
{
    /**
     * The synthetic tool a provider is asked to call when a response must come
     * back as structured data. It is never a real step of the conversation, and
     * the SDK already keeps it out of `$response->toolCalls`.
     */
    private const STRUCTURED_OUTPUT_TOOL = 'output_structured_data';

    public function latestConversationId(string $participantType, string|int $participantId): ?string
    {
        throw new RuntimeException(
            'The conversation store cannot resume a conversation from a participant alone; the contract carries no workspace id, so a user-only lookup can return a conversation from another of their workspaces. Query WorkspaceConversation::listable($workspaceId, $userId) instead.'
        );
    }

    public function storeConversation(?string $participantType, string|int|null $participantId, string $title): string
    {
        throw new RuntimeException(
            'Conversations are created by ChatMessageController with a client-supplied id; the agent must always be given an existing conversation via continue().'
        );
    }

    /**
     * Idempotent by design. ChatMessageController writes the user's message
     * before the stream opens, so a dropped connection never loses what was
     * typed; the SDK's RememberConversation middleware then calls this again
     * at the end of the same turn. Without the guard, every turn stores the
     * user's message twice.
     */
    public function storeUserMessage(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        AgentPrompt $prompt,
    ): string {
        $existing = $this->messages($conversationId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($existing?->role === Role::User && $existing->content === $prompt->prompt) {
            return $existing->id;
        }

        return $this->write($conversationId, Role::User, $prompt->prompt)->id;
    }

    public function storeAssistantMessage(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        AgentPrompt $prompt,
        AgentResponse $response,
    ): ?string {
        $toolResults = $response->toolResults->values();

        if ($prompt->hasApprovalDecisions()) {
            $recorded = $this->recordedToolResultIds($conversationId);

            $toolResults = $toolResults
                ->reject(fn (ToolResult $result): bool => in_array($result->id, $recorded, true))
                ->values();

            if (blank($response->text) && $response->toolCalls->isEmpty() && $toolResults->isEmpty()) {
                return null;
            }
        }

        return $this->write($conversationId, Role::Assistant, $response->text, [
            'parts' => $this->orderedParts($response) ?: null,
            'tool_calls' => $response->toolCalls->values()->toArray(),
            'tool_results' => $toolResults->toArray(),
            'usage' => $response->usage->toArray(),
            'meta' => $this->messageMeta($response),
            'approval_state' => $this->approvalState($response),
        ])->id;
    }

    /**
     * Build the turn's renderable parts in the order the model produced them.
     *
     * `content` flattens a whole turn into one string and `tool_calls` is a
     * flat list beside it, so a sentence the model said *before* calling a
     * tool cannot be told apart from one it said after. Both a streamed
     * turn's events and a generated turn's steps still carry that order, so
     * it is captured here rather than discarded.
     *
     * @return array<int, array{type: string, text?: string, id?: string, name?: string}>
     */
    private function orderedParts(AgentResponse $response): array
    {
        return $response instanceof StreamedAgentResponse
            ? $this->partsFromEvents($response->events)
            : $this->partsFromSteps($response->steps);
    }

    /**
     * Walk a streamed turn's events, which arrive in the order the provider emitted them.
     *
     * @param  Collection<int, StreamEvent>  $events
     * @return array<int, array{type: string, text?: string, id?: string, name?: string}>
     */
    private function partsFromEvents(Collection $events): array
    {
        $parts = [];
        $text = '';

        foreach ($events as $event) {
            if ($event instanceof TextDelta) {
                $text .= $event->delta;

                continue;
            }

            if (! $event instanceof ToolCallEvent || $event->toolCall->name === self::STRUCTURED_OUTPUT_TOOL) {
                continue;
            }

            $parts = $this->appendTextPart($parts, $text);
            $text = '';

            $parts[] = $this->toolPart($event->toolCall);
        }

        return $this->appendTextPart($parts, $text);
    }

    /**
     * Walk a generated turn's steps; one step is one model generation, so its
     * text always precedes the calls that same generation asked for.
     *
     * @param  Collection<int, Step>  $steps
     * @return array<int, array{type: string, text?: string, id?: string, name?: string}>
     */
    private function partsFromSteps(Collection $steps): array
    {
        $parts = [];

        foreach ($steps as $step) {
            $parts = $this->appendTextPart($parts, $step->text);

            foreach ($step->toolCalls as $toolCall) {
                if ($toolCall->name === self::STRUCTURED_OUTPUT_TOOL) {
                    continue;
                }

                $parts[] = $this->toolPart($toolCall);
            }
        }

        return $parts;
    }

    /**
     * @param  array<int, array{type: string, text?: string, id?: string, name?: string}>  $parts
     * @return array<int, array{type: string, text?: string, id?: string, name?: string}>
     */
    private function appendTextPart(array $parts, string $text): array
    {
        if (trim($text) === '') {
            return $parts;
        }

        $parts[] = ['type' => 'text', 'text' => $text];

        return $parts;
    }

    /**
     * The payload a tool part renders is fetched by call id through
     * ToolReplayer, so a part only has to name the call it points at.
     *
     * @return array{type: string, id: string, name: string}
     */
    private function toolPart(ToolCall $toolCall): array
    {
        return ['type' => 'tool', 'id' => $toolCall->id, 'name' => $toolCall->name];
    }

    /**
     * @return Collection<int, Message>
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        $records = $this->messages($conversationId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();

        /**
         * A call resolved after an approval pause lands on a later row than the
         * call, so gather every result id across the window to keep those calls
         * while dropping legacy dangling ones.
         *
         * @var array<int, string> $resolvedCallIds
         */
        $resolvedCallIds = $records
            ->flatMap(fn (WorkspaceConversationMessage $record): array => collect($record->tool_results ?? [])->pluck('id')->all())
            ->filter()
            ->all();

        return $records
            ->flatMap(function (WorkspaceConversationMessage $record) use ($resolvedCallIds): array {
                if ($record->role === Role::User) {
                    return [new UserMessage($record->content)];
                }

                $toolCalls = collect($record->tool_calls ?? [])->values();
                $toolResults = collect($record->tool_results ?? [])->values();

                if ($toolCalls->isNotEmpty()) {
                    return $this->reconstructToolTurn($record, $toolCalls, $toolResults, $resolvedCallIds);
                }

                if ($toolResults->isNotEmpty()) {
                    $messages = [new ToolResultMessage($toolResults->map(ToolResult::fromArray(...)))];

                    if (filled($record->content)) {
                        $messages[] = new AssistantMessage($record->content);
                    }

                    return $messages;
                }

                return [new AssistantMessage($record->content)];
            })
            ->skipWhile(fn (Message $message): bool => $message instanceof ToolResultMessage)
            ->values();
    }

    /**
     * Durably record resolved approval results on the paused turn before the run continues.
     *
     * @param  array<int, ToolResult>  $toolResults
     *
     * @throws ApprovalMismatchException when no paused row matches the resolved results
     */
    public function storeApprovalResults(
        string $conversationId,
        ?string $participantType,
        string|int|null $participantId,
        array $toolResults,
    ): void {
        if ($toolResults === []) {
            return;
        }

        $resultIds = array_map(fn (ToolResult $result): string => $result->id, $toolResults);

        DB::transaction(function () use ($conversationId, $toolResults, $resultIds): void {
            $row = $this->pausedRows($conversationId)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get()
                ->first(fn (WorkspaceConversationMessage $record): bool => array_intersect($this->pausedCallIds($record), $resultIds) !== []);

            if ($row === null) {
                throw new ApprovalMismatchException('The approval results do not match a paused conversation turn.', collect());
            }

            $existing = collect($row->tool_results ?? []);

            $merged = $existing->merge(
                collect($toolResults)
                    ->reject(fn (ToolResult $result): bool => $existing->contains('id', $result->id))
                    ->map(fn (ToolResult $result): array => $result->toArray())
            );

            $pending = collect(data_get($row->approval_state, 'pending', []))->except($resultIds);

            // Keep the marker after resolution so the resume dedup scan stays bounded
            // to ever-paused rows, while each call's outcome lives in the merged results...
            $row->forceFill([
                'tool_results' => $merged->values()->all(),
                'approval_state' => ['pending' => $pending->all()],
            ])->save();
        });
    }

    /**
     * Rebuild the messages for a stored assistant turn that made tool calls, keeping a pause distinct from a completed turn.
     *
     * @param  Collection<int, array<string, mixed>>  $toolCalls
     * @param  Collection<int, array<string, mixed>>  $toolResults
     * @param  array<int, string>  $resolvedCallIds  Ids of calls answered anywhere in the window.
     * @return array<int, Message>
     */
    private function reconstructToolTurn(
        WorkspaceConversationMessage $record,
        Collection $toolCalls,
        Collection $toolResults,
        array $resolvedCallIds = [],
    ): array {
        $callIds = $toolCalls->pluck('id')->all();

        [$priorResults, $ownResults] = $toolResults->partition(
            fn (array $toolResult): bool => ! in_array(data_get($toolResult, 'id'), $callIds, true)
        );

        $ownResultIds = $ownResults->pluck('id')->all();

        [$resolvedCalls, $pendingCalls] = $toolCalls->partition(
            fn (array $toolCall): bool => in_array(data_get($toolCall, 'id'), $ownResultIds, true)
        );

        $pausedCallIds = $this->pausedCallIds($record);

        $isPause = $pendingCalls->isNotEmpty()
            && $pendingCalls->every(fn (array $toolCall): bool => in_array(data_get($toolCall, 'id'), $pausedCallIds, true));

        $messages = [];

        if ($priorResults->isNotEmpty()) {
            $messages[] = new ToolResultMessage($priorResults->map(ToolResult::fromArray(...))->values());
        }

        $providerContentBlocks = data_get($record->meta, 'provider_content_blocks', []);

        if ($isPause && filled($providerContentBlocks)) {
            $messages[] = new AssistantMessage(
                $record->content,
                $toolCalls->map(ToolCall::fromArray(...))->values(),
                $providerContentBlocks,
                data_get($record->meta, 'provider'),
            );

            if ($ownResults->isNotEmpty()) {
                $messages[] = new ToolResultMessage($ownResults->map(ToolResult::fromArray(...))->values());
            }

            return $messages;
        }

        // Calls already answered this turn are replayed with their results...
        if ($resolvedCalls->isNotEmpty()) {
            $messages[] = new AssistantMessage('', $resolvedCalls->map(ToolCall::fromArray(...))->values());
            $messages[] = new ToolResultMessage($ownResults->map(ToolResult::fromArray(...))->values());
        }

        $keptCalls = $pendingCalls->filter(
            fn (array $toolCall): bool => in_array(data_get($toolCall, 'id'), $pausedCallIds, true)
                || in_array(data_get($toolCall, 'id'), $resolvedCallIds, true)
        )->values();

        if ($keptCalls->isNotEmpty()) {
            $messages[] = new AssistantMessage($record->content, $keptCalls->map(ToolCall::fromArray(...))->values());
        } elseif (filled($record->content)) {
            $messages[] = new AssistantMessage($record->content);
        }

        return $messages;
    }

    /**
     * Get every tool-result id recorded on the conversation's approval-paused rows, the only rows a resume can duplicate.
     *
     * @return array<int, string>
     */
    private function recordedToolResultIds(string $conversationId): array
    {
        return $this->pausedRows($conversationId)
            ->pluck('tool_results')
            ->flatMap(fn (?array $results): array => collect($results ?? [])->pluck('id')->all())
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Mark a paused assistant row with the tool-call ids pending a decision, or null when the turn is not a pause.
     *
     * @return array{pending: array<string, string|null>}|null
     */
    private function approvalState(AgentResponse $response): ?array
    {
        if (! $response->hasPendingApprovals()) {
            return null;
        }

        return [
            'pending' => $response->pendingApprovals
                ->mapWithKeys(fn (PendingApproval $approval): array => [$approval->id => $approval->reason])
                ->all(),
        ];
    }

    /**
     * Get the tool-call ids a stored row recorded as pending a decision.
     *
     * @return array<int, string>
     */
    private function pausedCallIds(WorkspaceConversationMessage $record): array
    {
        $pending = data_get($record->approval_state, 'pending');

        return is_array($pending) ? array_keys($pending) : [];
    }

    /**
     * Build the message meta payload, tucking a paused turn's raw provider blocks alongside the response meta.
     *
     * @return array<string, mixed>
     */
    private function messageMeta(AgentResponse $response): array
    {
        $meta = (array) json_decode((string) json_encode($response->meta), true);

        if (filled($blocks = $response->pausedProviderContentBlocks())) {
            $meta['provider_content_blocks'] = $blocks;
        }

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function write(string $conversationId, Role $role, string $content, array $attributes = []): WorkspaceConversationMessage
    {
        $message = WorkspaceConversationMessage::create([
            'workspace_conversation_id' => $conversationId,
            'role' => $role,
            'content' => $content,
            ...$attributes,
        ]);

        $this->touchConversation($conversationId);

        return $message;
    }

    private function touchConversation(string $conversationId): void
    {
        WorkspaceConversation::query()
            ->whereKey($conversationId)
            ->update(['updated_at' => now()]);
    }

    /**
     * @return Builder<WorkspaceConversationMessage>
     */
    private function messages(string $conversationId): Builder
    {
        return WorkspaceConversationMessage::query()
            ->where('workspace_conversation_id', $conversationId);
    }

    /**
     * @return Builder<WorkspaceConversationMessage>
     */
    private function pausedRows(string $conversationId): Builder
    {
        return $this->messages($conversationId)
            ->where('role', Role::Assistant)
            ->whereNotNull('approval_state');
    }
}
