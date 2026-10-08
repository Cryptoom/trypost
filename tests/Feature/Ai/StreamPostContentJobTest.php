<?php

declare(strict_types=1);

use App\Ai\Agents\PostContentStreamer;
use App\Jobs\Ai\StreamPostContent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Broadcasting\AnonymousEvent;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;

test('job is queued onto the ai queue', function () {
    Bus::fake();

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();

    StreamPostContent::dispatch(
        workspaceId: $workspace->id,
        userId: $user->id,
        generationId: 'gen-1',
        prompt: 'Write a post about Mondays',
        currentContent: null,
    );

    Bus::assertDispatched(StreamPostContent::class, fn ($job) => $job->queue === 'ai');
});

test('job invokes the PostContentStreamer agent and broadcasts stream events', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();

    PostContentStreamer::fake(['Hello world']);

    $job = new StreamPostContent(
        workspaceId: $workspace->id,
        userId: $user->id,
        generationId: 'gen-abc',
        prompt: 'Write a post about Mondays',
        currentContent: null,
    );

    $job->handle();

    PostContentStreamer::assertPrompted('Write a post about Mondays');
});

test('aig02 a provider error event is rebroadcast under the stable error name', function () {
    Event::fake([AnonymousEvent::class]);

    $job = new StreamPostContent('w', 'u', 'gen-err', 'p', null);

    $job->reportStream(collect([
        new Error('1', 'overloaded_error', 'secret provider text', false, time()),
    ]), new PrivateChannel('user.u.ai-gen.gen-err'));

    Event::assertDispatched(AnonymousEvent::class, fn ($event) => $event->broadcastAs() === 'error');
});

test('aig02 a clean stream broadcasts no error and logs lengths only', function () {
    Event::fake([AnonymousEvent::class]);
    Log::spy();

    $job = new StreamPostContent('w', 'u', 'gen-ok', 'p', null);

    $job->reportStream(collect([
        new TextDelta('1', 'm', '```json {"a"', time()),
        new StreamEnd('2', 'stop', new Usage(1, 1), time()),
    ]), new PrivateChannel('user.u.ai-gen.gen-ok'));

    Event::assertNotDispatched(AnonymousEvent::class);
    Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $message === 'PostContentGenerator stream ended'
        && $context['delta_count'] === 1
        && $context['starts_with_fence'] === true
        && ! in_array('```json {"a"', $context, true))->once();
});

test('aig02 an exception broadcasts the error event and the job still throws', function () {
    Event::fake([AnonymousEvent::class]);

    $workspace = Workspace::factory()->create();
    $user = User::factory()->create();

    PostContentStreamer::fake(fn () => throw new RuntimeException('boom'));

    $job = new StreamPostContent($workspace->id, $user->id, 'gen-boom', 'p', null);

    expect(fn () => $job->handle())->toThrow(RuntimeException::class);

    Event::assertDispatched(AnonymousEvent::class, fn ($event) => $event->broadcastAs() === 'error');
});

test('aig02 failed hook broadcasts the error event', function () {
    Event::fake([AnonymousEvent::class]);

    (new StreamPostContent('w', 'u', 'gen-f', 'p', null))->failed(new RuntimeException('x'));

    Event::assertDispatched(AnonymousEvent::class, fn ($event) => $event->broadcastAs() === 'error');
});
