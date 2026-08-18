<?php

declare(strict_types=1);

use App\Ai\Agents\WorkspaceConversationAgent;
use App\Enums\WorkspaceConversation\Message\Role;
use App\Enums\WorkspaceConversation\Status;
use App\Jobs\Ai\GenerateConversationTitle;
use App\Models\AiUsageLog;
use App\Models\WorkspaceConversation;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

test('it creates the conversation from the client supplied id and persists the user message', function () {
    WorkspaceConversationAgent::fake(['All done.']);

    [$user, $workspace] = actingAsWorkspaceUser();
    $conversationId = (string) Str::uuid();

    $this->post(route('app.chat.messages.store', $conversationId), ['message' => 'How many drafts?'])
        ->assertOk();

    $conversation = WorkspaceConversation::find($conversationId);

    expect($conversation)->not->toBeNull()
        ->and($conversation->workspace_id)->toBe($workspace->id)
        ->and($conversation->user_id)->toBe($user->id)
        ->and($conversation->messages()->where('role', Role::User)->first()->content)->toBe('How many drafts?');
});

test('it rejects a second message while a turn is in progress', function () {
    WorkspaceConversationAgent::fake(['All done.']);

    [$user, $workspace] = actingAsWorkspaceUser();
    $conversation = WorkspaceConversation::factory()
        ->for($workspace)->for($user)->inProgress()->create();

    $this->post(route('app.chat.messages.store', $conversation->id), ['message' => 'Again'])
        ->assertStatus(Response::HTTP_CONFLICT);

    expect($conversation->messages()->count())->toBe(0);
});

test('it rejects message and decisions together', function () {
    [$user, $workspace] = actingAsWorkspaceUser();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    $this->postJson(route('app.chat.messages.store', $conversation->id), [
        'message' => 'Hi',
        'decisions' => ['call_1' => ['action' => 'approve']],
    ])->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
});

test('it rejects a turn with neither a message nor decisions', function () {
    [$user, $workspace] = actingAsWorkspaceUser();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    $this->postJson(route('app.chat.messages.store', $conversation->id), [])
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['message', 'decisions']);
});

test('it refuses another users conversation', function () {
    WorkspaceConversationAgent::fake(['All done.']);

    [$user, $workspace] = actingAsWorkspaceUser();
    $foreign = WorkspaceConversation::factory()->for($workspace)->create();

    $this->post(route('app.chat.messages.store', $foreign->id), ['message' => 'Hi'])
        ->assertForbidden();
});

test('it refuses a conversation from another workspace', function () {
    WorkspaceConversationAgent::fake(['All done.']);

    [$user] = actingAsWorkspaceUser();
    $foreign = WorkspaceConversation::factory()->for($user)->create();

    $this->post(route('app.chat.messages.store', $foreign->id), ['message' => 'Hi'])
        ->assertForbidden();
});

test('it returns the conversation to idle when the turn completes', function () {
    WorkspaceConversationAgent::fake(['All done.']);

    [$user, $workspace] = actingAsWorkspaceUser();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    $response = $this->post(route('app.chat.messages.store', $conversation->id), ['message' => 'Hi']);
    $response->streamedContent();

    expect($conversation->fresh()->status)->toBe(Status::Idle);
});

test('it marks the conversation in progress for the duration of the turn', function () {
    WorkspaceConversationAgent::fake(['All done.']);

    [$user, $workspace] = actingAsWorkspaceUser();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    $this->post(route('app.chat.messages.store', $conversation->id), ['message' => 'Hi']);

    expect($conversation->fresh()->status)->toBe(Status::InProgress);
});

test('a turn stores exactly one user message', function () {
    WorkspaceConversationAgent::fake(['All done.']);

    [$user, $workspace] = actingAsWorkspaceUser();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    $response = $this->post(route('app.chat.messages.store', $conversation->id), ['message' => 'Only once']);
    $response->streamedContent();

    expect($conversation->messages()->where('role', Role::User)->count())->toBe(1);
});

test('it records the turn against the workspace credit usage', function () {
    WorkspaceConversationAgent::fake(['All done.']);

    [$user, $workspace] = actingAsWorkspaceUser();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    $response = $this->post(route('app.chat.messages.store', $conversation->id), ['message' => 'Hi']);
    $response->streamedContent();

    $usage = AiUsageLog::query()->where('workspace_id', $workspace->id)->first();

    expect($usage)->not->toBeNull()
        ->and($usage->user_id)->toBe($user->id)
        ->and(data_get($usage->metadata, 'agent'))->toBe('workspace_conversation');
});

test('it queues a title for an untitled conversation once the turn completes', function () {
    Bus::fake();
    WorkspaceConversationAgent::fake(['All done.']);

    [$user, $workspace] = actingAsWorkspaceUser();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->untitled()->create();

    $response = $this->post(route('app.chat.messages.store', $conversation->id), ['message' => 'Hi']);
    $response->streamedContent();

    Bus::assertDispatched(
        GenerateConversationTitle::class,
        fn (GenerateConversationTitle $job): bool => $job->conversationId === $conversation->id,
    );
});

test('it does not requeue a title for an already titled conversation', function () {
    Bus::fake();
    WorkspaceConversationAgent::fake(['All done.']);

    [$user, $workspace] = actingAsWorkspaceUser();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    $response = $this->post(route('app.chat.messages.store', $conversation->id), ['message' => 'Hi']);
    $response->streamedContent();

    Bus::assertNotDispatched(GenerateConversationTitle::class);
});

test('an approval continuation stores no user message', function () {
    WorkspaceConversationAgent::fake(['All done.']);

    [$user, $workspace] = actingAsWorkspaceUser();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    $response = $this->post(route('app.chat.messages.store', $conversation->id), [
        'decisions' => ['call_1' => ['action' => 'reject', 'result' => 'Not now.']],
    ]);
    $response->streamedContent();

    expect($conversation->messages()->where('role', Role::User)->count())->toBe(0)
        ->and($conversation->fresh()->status)->toBe(Status::Idle);
});

test('it rejects an unknown decision action', function () {
    [$user, $workspace] = actingAsWorkspaceUser();
    $conversation = WorkspaceConversation::factory()->for($workspace)->for($user)->create();

    $this->postJson(route('app.chat.messages.store', $conversation->id), [
        'decisions' => ['call_1' => ['action' => 'maybe']],
    ])->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['decisions.call_1.action']);
});

test('endpoint requires authentication', function () {
    $this->postJson(route('app.chat.messages.store', (string) Str::uuid()), ['message' => 'Hi'])
        ->assertStatus(Response::HTTP_UNAUTHORIZED);
});
