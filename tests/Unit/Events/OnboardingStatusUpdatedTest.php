<?php

declare(strict_types=1);

use App\Events\OnboardingStatusUpdated;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Event;

test('event broadcasts on the workspace channel', function () {
    $workspaceId = fake()->uuid();
    $event = new OnboardingStatusUpdated($workspaceId);
    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1)
        ->and($channels[0])->toBeInstanceOf(PrivateChannel::class)
        ->and($channels[0]->name)->toBe("private-workspace.{$workspaceId}");
});

test('event broadcasts with the workspace id', function () {
    $workspaceId = fake()->uuid();
    $event = new OnboardingStatusUpdated($workspaceId);

    expect($event->broadcastWith())->toBe(['workspace_id' => $workspaceId]);
});

test('event broadcasts as a stable name', function () {
    $event = new OnboardingStatusUpdated(fake()->uuid());

    expect($event->broadcastAs())->toBe('onboarding.status.updated');
});

test('event waits for the database transaction to commit', function () {
    expect(new OnboardingStatusUpdated(fake()->uuid()))
        ->toBeInstanceOf(ShouldDispatchAfterCommit::class);
});

test('dispatchForWorkspace skips blank workspace ids', function () {
    Event::fake([OnboardingStatusUpdated::class]);

    OnboardingStatusUpdated::dispatchForWorkspace(null);
    OnboardingStatusUpdated::dispatchForWorkspace('');

    Event::assertNotDispatched(OnboardingStatusUpdated::class);
});
