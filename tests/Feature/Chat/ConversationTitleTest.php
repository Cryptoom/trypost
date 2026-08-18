<?php

declare(strict_types=1);

use App\Ai\Agents\ConversationTitleGenerator;
use App\Enums\WorkspaceConversation\Message\Role;
use App\Jobs\Ai\GenerateConversationTitle;
use App\Models\WorkspaceConversation;
use App\Models\WorkspaceConversationMessage;
use Illuminate\Support\Str;

test('it titles a conversation from its first user message', function () {
    ConversationTitleGenerator::fake(['Draft post count']);

    $conversation = WorkspaceConversation::factory()->untitled()->create();
    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::User,
        'content' => 'How many drafts do I have?',
    ]);

    (new GenerateConversationTitle($conversation->id))->handle();

    expect($conversation->fresh()->title)->toBe('Draft post count');
});

test('it leaves an already titled conversation alone', function () {
    ConversationTitleGenerator::fake(['Should not be used']);

    $conversation = WorkspaceConversation::factory()->create(['title' => 'Existing']);

    (new GenerateConversationTitle($conversation->id))->handle();

    expect($conversation->fresh()->title)->toBe('Existing');
    ConversationTitleGenerator::assertNeverPrompted();
});

test('it does nothing when the conversation no longer exists', function () {
    ConversationTitleGenerator::fake(['Should not be used']);

    (new GenerateConversationTitle((string) Str::uuid()))->handle();

    ConversationTitleGenerator::assertNeverPrompted();
});

test('it does nothing when the conversation has no user message yet', function () {
    ConversationTitleGenerator::fake(['Should not be used']);

    $conversation = WorkspaceConversation::factory()->untitled()->create();

    (new GenerateConversationTitle($conversation->id))->handle();

    expect($conversation->fresh()->title)->toBeNull();
    ConversationTitleGenerator::assertNeverPrompted();
});

test('it strips a chatty preamble and quotes from the model response', function () {
    ConversationTitleGenerator::fake(['Sure! Here\'s a title: "Draft post count"']);

    $conversation = WorkspaceConversation::factory()->untitled()->create();
    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::User,
        'content' => 'How many drafts do I have?',
    ]);

    (new GenerateConversationTitle($conversation->id))->handle();

    expect($conversation->fresh()->title)->toBe('Draft post count');
});

test('it truncates a title that would overflow the column', function () {
    $long = str_repeat('a', 300);
    ConversationTitleGenerator::fake([$long]);

    $conversation = WorkspaceConversation::factory()->untitled()->create();
    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::User,
        'content' => 'How many drafts do I have?',
    ]);

    (new GenerateConversationTitle($conversation->id))->handle();

    expect(strlen((string) $conversation->fresh()->title))->toBeLessThanOrEqual(250);
});

test('it leaves a legitimate title containing a colon unchanged', function () {
    ConversationTitleGenerator::fake(['Okay Computer: A Retrospective']);

    $conversation = WorkspaceConversation::factory()->untitled()->create();
    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::User,
        'content' => 'Tell me about the album',
    ]);

    (new GenerateConversationTitle($conversation->id))->handle();

    expect($conversation->fresh()->title)->toBe('Okay Computer: A Retrospective');
});

test('it round-trips a non-Latin title byte-for-byte intact', function () {
    ConversationTitleGenerator::fake(['株式会社の設立準備']);

    $conversation = WorkspaceConversation::factory()->untitled()->create();
    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::User,
        'content' => '会社の設立について教えてください',
    ]);

    (new GenerateConversationTitle($conversation->id))->handle();

    expect($conversation->fresh()->title)->toBe('株式会社の設立準備');
});
