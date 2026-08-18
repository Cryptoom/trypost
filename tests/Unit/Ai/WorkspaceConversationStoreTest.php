<?php

declare(strict_types=1);

use App\Enums\WorkspaceConversation\Message\Role;
use App\Models\WorkspaceConversation;
use App\Models\WorkspaceConversationMessage;
use App\Services\Ai\Conversations\WorkspaceConversationStore;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Messages\UserMessage;

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
