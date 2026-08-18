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

test('it unwraps a response that is entirely one quoted title', function () {
    ConversationTitleGenerator::fake(['"Draft post count"']);

    $conversation = WorkspaceConversation::factory()->untitled()->create();
    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::User,
        'content' => 'How many drafts do I have?',
    ]);

    (new GenerateConversationTitle($conversation->id))->handle();

    expect($conversation->fresh()->title)->toBe('Draft post count');
});

test('it extracts a quoted title after a colon without pairing unrelated apostrophes', function () {
    ConversationTitleGenerator::fake(['Here\'s the title: "Sarah\'s Q4 plan"']);

    $conversation = WorkspaceConversation::factory()->untitled()->create();
    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::User,
        'content' => 'What is in the Q4 plan?',
    ]);

    (new GenerateConversationTitle($conversation->id))->handle();

    expect($conversation->fresh()->title)->toBe('Sarah\'s Q4 plan');
});

test('it does not extract an embedded quoted phrase that is not the whole title', function () {
    ConversationTitleGenerator::fake(['The "Great Escape" plan']);

    $conversation = WorkspaceConversation::factory()->untitled()->create();
    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::User,
        'content' => 'Tell me about the plan',
    ]);

    (new GenerateConversationTitle($conversation->id))->handle();

    expect($conversation->fresh()->title)->toBe('The "Great Escape" plan');
});

test('it leaves a title with a single apostrophe unchanged', function () {
    ConversationTitleGenerator::fake(['Sarah\'s Q4 plan']);

    $conversation = WorkspaceConversation::factory()->untitled()->create();
    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::User,
        'content' => 'What is in the Q4 plan?',
    ]);

    (new GenerateConversationTitle($conversation->id))->handle();

    expect($conversation->fresh()->title)->toBe('Sarah\'s Q4 plan');
});

test('it leaves a title with multiple apostrophes unchanged', function () {
    ConversationTitleGenerator::fake(['Sarah\'s Q4 plan and Mike\'s roadmap']);

    $conversation = WorkspaceConversation::factory()->untitled()->create();
    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::User,
        'content' => 'What is in the plans?',
    ]);

    (new GenerateConversationTitle($conversation->id))->handle();

    expect($conversation->fresh()->title)->toBe('Sarah\'s Q4 plan and Mike\'s roadmap');
});

test('it stays chatty rather than pairing apostrophes when there is no colon to anchor extraction', function () {
    ConversationTitleGenerator::fake(['It\'s called "Don\'t Stop"']);

    $conversation = WorkspaceConversation::factory()->untitled()->create();
    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::User,
        'content' => 'What is the song called?',
    ]);

    (new GenerateConversationTitle($conversation->id))->handle();

    expect($conversation->fresh()->title)
        ->not->toBe('Don\'t Stop')
        ->toBe('It\'s called "Don\'t Stop');
});

test('it never stores an empty title from an empty quoted capture', function () {
    ConversationTitleGenerator::fake(['The title is ""']);

    $conversation = WorkspaceConversation::factory()->untitled()->create();
    WorkspaceConversationMessage::factory()->for($conversation, 'conversation')->create([
        'role' => Role::User,
        'content' => 'What should the title be?',
    ]);

    (new GenerateConversationTitle($conversation->id))->handle();

    expect($conversation->fresh()->title)
        ->not->toBeNull()
        ->not->toBe('');
});
