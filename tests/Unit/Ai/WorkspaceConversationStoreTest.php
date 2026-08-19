<?php

declare(strict_types=1);

use App\Enums\WorkspaceConversation\Message\Role;
use App\Models\WorkspaceConversation;
use App\Models\WorkspaceConversationMessage;
use App\Services\Ai\Conversations\WorkspaceConversationStore;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\FinishReason;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Step;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Streaming\Events\ToolCall as ToolCallEvent;

/**
 * A turn that is not resuming an approval pause, which is all
 * storeAssistantMessage() reads off the prompt.
 */
function promptWithoutApprovals(): AgentPrompt
{
    $prompt = Mockery::mock(AgentPrompt::class);
    $prompt->shouldReceive('hasApprovalDecisions')->andReturnFalse();

    return $prompt;
}

test('it reads a stored user row back as a UserMessage', function () {
    $conversation = WorkspaceConversation::factory()->create();

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::User,
        'content' => 'How many posts went out today?',
    ]);

    $messages = app(WorkspaceConversationStore::class)
        ->getLatestConversationMessages($conversation->id, 10);

    expect($messages)->toHaveCount(1)
        ->and($messages->first())->toBeInstanceOf(UserMessage::class)
        ->and($messages->first()->content)->toBe('How many posts went out today?');
});

test('it rebuilds tool calls and results from an assistant row', function () {
    $conversation = WorkspaceConversation::factory()->create();

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::User,
        'content' => 'List drafts.',
    ]);

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Here they are.',
        'tool_calls' => [['id' => 'call_1', 'name' => 'list_posts', 'arguments' => ['status' => 'draft']]],
        'tool_results' => [['id' => 'call_1', 'name' => 'list_posts', 'arguments' => ['status' => 'draft'], 'result' => '{"data":[]}']],
    ]);

    $messages = app(WorkspaceConversationStore::class)
        ->getLatestConversationMessages($conversation->id, 10);

    $assistant = $messages->first(fn ($message): bool => $message instanceof AssistantMessage);
    $toolResult = $messages->first(fn ($message): bool => $message instanceof ToolResultMessage);

    expect($assistant)->not->toBeNull()
        ->and($toolResult)->not->toBeNull()
        ->and($toolResult->toolResults->first()->name)->toBe('list_posts');
});

test('it respects the message limit and returns oldest first', function () {
    $conversation = WorkspaceConversation::factory()->create();

    foreach (['one', 'two', 'three'] as $index => $text) {
        WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
            'role' => Role::User,
            'content' => $text,
            'created_at' => now()->addSeconds($index),
        ]);
    }

    $messages = app(WorkspaceConversationStore::class)
        ->getLatestConversationMessages($conversation->id, 2);

    expect($messages)->toHaveCount(2)
        ->and($messages->first()->content)->toBe('two')
        ->and($messages->last()->content)->toBe('three');
});

test('it replays a paused turn with its tool calls and provider content blocks', function () {
    $conversation = WorkspaceConversation::factory()->create();

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::User,
        'content' => 'Delete the draft.',
        'created_at' => now(),
    ]);

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => '',
        'tool_calls' => [['id' => 'call_1', 'name' => 'delete_post', 'arguments' => ['id' => 'p1']]],
        'tool_results' => [],
        'approval_state' => ['pending' => ['call_1' => 'Destructive action.']],
        'meta' => ['provider' => 'anthropic', 'provider_content_blocks' => [['type' => 'tool_use', 'id' => 'call_1']]],
        'created_at' => now()->addSecond(),
    ]);

    $messages = app(WorkspaceConversationStore::class)
        ->getLatestConversationMessages($conversation->id, 10);

    expect($messages)->toHaveCount(2)
        ->and($messages->last())->toBeInstanceOf(AssistantMessage::class)
        ->and($messages->last()->toolCalls->pluck('id')->all())->toBe(['call_1'])
        ->and($messages->last()->providerContentBlocks)->toBe([['type' => 'tool_use', 'id' => 'call_1']])
        ->and($messages->last()->providerContentBlocksProvider)->toBe('anthropic');
});

test('it drops a dangling tool call that was never answered', function () {
    $conversation = WorkspaceConversation::factory()->create();

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Working on it.',
        'tool_calls' => [['id' => 'call_1', 'name' => 'list_posts', 'arguments' => []]],
        'tool_results' => [],
    ]);

    $messages = app(WorkspaceConversationStore::class)
        ->getLatestConversationMessages($conversation->id, 10);

    expect($messages)->toHaveCount(1)
        ->and($messages->first())->toBeInstanceOf(AssistantMessage::class)
        ->and($messages->first()->toolCalls)->toBeEmpty();
});

test('it emits a resolved tool call immediately before its result', function () {
    $conversation = WorkspaceConversation::factory()->create();

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::User,
        'content' => 'List drafts.',
        'created_at' => now(),
    ]);

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Here they are.',
        'tool_calls' => [['id' => 'call_1', 'name' => 'list_posts', 'arguments' => ['status' => 'draft']]],
        'tool_results' => [['id' => 'call_1', 'name' => 'list_posts', 'arguments' => ['status' => 'draft'], 'result' => '{"data":[]}']],
        'created_at' => now()->addSecond(),
    ]);

    $messages = app(WorkspaceConversationStore::class)
        ->getLatestConversationMessages($conversation->id, 10);

    expect($messages->map(fn ($message): string => $message::class)->all())->toBe([
        UserMessage::class,
        AssistantMessage::class,
        ToolResultMessage::class,
        AssistantMessage::class,
    ])
        ->and($messages[1]->toolCalls->pluck('id')->all())->toBe(['call_1'])
        ->and($messages[2]->toolResults->pluck('id')->all())->toBe(['call_1'])
        ->and($messages[3]->content)->toBe('Here they are.');
});

test('it drops a leading tool result when the window starts mid turn', function () {
    $conversation = WorkspaceConversation::factory()->create();

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => '',
        'tool_calls' => [['id' => 'call_1', 'name' => 'delete_post', 'arguments' => ['id' => 'p1']]],
        'approval_state' => ['pending' => ['call_1' => 'Destructive action.']],
        'created_at' => now(),
    ]);

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Deleted.',
        'tool_calls' => [],
        'tool_results' => [['id' => 'call_1', 'name' => 'delete_post', 'arguments' => ['id' => 'p1'], 'result' => 'ok']],
        'created_at' => now()->addSecond(),
    ]);

    $messages = app(WorkspaceConversationStore::class)
        ->getLatestConversationMessages($conversation->id, 1);

    expect($messages)->toHaveCount(1)
        ->and($messages->first())->toBeInstanceOf(AssistantMessage::class)
        ->and($messages->first()->content)->toBe('Deleted.');
});

test('it emits a prior turn tool result before the assistant message that carries its own call', function () {
    $conversation = WorkspaceConversation::factory()->create();

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::User,
        'content' => 'Delete the draft, then archive it.',
        'created_at' => now(),
    ]);

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => '',
        'tool_calls' => [['id' => 'call_1', 'name' => 'delete_post', 'arguments' => ['id' => 'p1']]],
        'tool_results' => [],
        'approval_state' => ['pending' => ['call_1' => 'Destructive action.']],
        'meta' => ['provider' => 'anthropic', 'provider_content_blocks' => [['type' => 'tool_use', 'id' => 'call_1']]],
        'created_at' => now()->addSecond(),
    ]);

    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::Assistant,
        'content' => 'Archiving it now.',
        'tool_calls' => [['id' => 'call_2', 'name' => 'archive_post', 'arguments' => ['id' => 'p1']]],
        'tool_results' => [['id' => 'call_1', 'name' => 'delete_post', 'arguments' => ['id' => 'p1'], 'result' => 'ok']],
        'approval_state' => ['pending' => ['call_2' => 'Destructive action.']],
        'created_at' => now()->addSeconds(2),
    ]);

    $messages = app(WorkspaceConversationStore::class)
        ->getLatestConversationMessages($conversation->id, 10);

    expect($messages->map(fn ($message): string => $message::class)->all())->toBe([
        UserMessage::class,
        AssistantMessage::class,
        ToolResultMessage::class,
        AssistantMessage::class,
    ])
        ->and($messages[1]->toolCalls->pluck('id')->all())->toBe(['call_1'])
        ->and($messages[2]->toolResults->pluck('id')->all())->toBe(['call_1'])
        ->and($messages[3]->toolCalls->pluck('id')->all())->toBe(['call_2'])
        ->and($messages[3]->content)->toBe('Archiving it now.');
});

test('it stores a streamed turn as text, tool card, then text in the order the model produced them', function () {
    $conversation = WorkspaceConversation::factory()->create();

    $response = new StreamedAgentResponse('invocation-1', collect([
        new TextDelta('e1', 'message-1', 'Let me show you the formats.', 1),
        new ToolCallEvent('e2', new ToolCall('call_start', 'start_post_generation', []), 2),
        new TextDelta('e3', 'message-2', 'All set! Pick one above.', 3),
    ]), new Meta('anthropic', 'claude-test'));

    app(WorkspaceConversationStore::class)->storeAssistantMessage(
        $conversation->id,
        null,
        null,
        promptWithoutApprovals(),
        $response,
    );

    $message = WorkspaceConversationMessage::query()
        ->where('workspace_conversation_id', $conversation->id)
        ->sole();

    expect($message->parts)->toBe([
        ['type' => 'text', 'text' => 'Let me show you the formats.'],
        ['type' => 'tool', 'id' => 'call_start', 'name' => 'start_post_generation'],
        ['type' => 'text', 'text' => 'All set! Pick one above.'],
    ])
        ->and($message->content)->toBe("Let me show you the formats.\n\nAll set! Pick one above.")
        ->and($message->tool_calls)->toHaveCount(1);
});

test('it stores a turn that said nothing before its tool call as the card then the answer', function () {
    $conversation = WorkspaceConversation::factory()->create();

    $response = new StreamedAgentResponse('invocation-2', collect([
        new ToolCallEvent('e1', new ToolCall('call_list', 'list_posts', ['status' => 'draft']), 1),
        new TextDelta('e2', 'message-1', 'Here are your drafts.', 2),
    ]), new Meta('anthropic', 'claude-test'));

    app(WorkspaceConversationStore::class)->storeAssistantMessage(
        $conversation->id,
        null,
        null,
        promptWithoutApprovals(),
        $response,
    );

    $message = WorkspaceConversationMessage::query()
        ->where('workspace_conversation_id', $conversation->id)
        ->sole();

    expect($message->parts)->toBe([
        ['type' => 'tool', 'id' => 'call_list', 'name' => 'list_posts'],
        ['type' => 'text', 'text' => 'Here are your drafts.'],
    ]);
});

test('it stores the interleaved order of a non-streamed turn from its steps', function () {
    $conversation = WorkspaceConversation::factory()->create();

    $response = (new AgentResponse('invocation-3', 'ignored', new Usage, new Meta('anthropic', 'claude-test')))
        ->withSteps(collect([
            new Step(
                'Let me check that.',
                [new ToolCall('call_metrics', 'get_post_metrics', ['id' => 'p1'])],
                [],
                FinishReason::ToolCalls,
                new Usage,
                new Meta('anthropic', 'claude-test'),
            ),
            new Step('It got 12 likes.', [], [], FinishReason::Stop, new Usage, new Meta('anthropic', 'claude-test')),
        ]));

    app(WorkspaceConversationStore::class)->storeAssistantMessage(
        $conversation->id,
        null,
        null,
        promptWithoutApprovals(),
        $response,
    );

    $message = WorkspaceConversationMessage::query()
        ->where('workspace_conversation_id', $conversation->id)
        ->sole();

    expect($message->parts)->toBe([
        ['type' => 'text', 'text' => 'Let me check that.'],
        ['type' => 'tool', 'id' => 'call_metrics', 'name' => 'get_post_metrics'],
        ['type' => 'text', 'text' => 'It got 12 likes.'],
    ]);
});
