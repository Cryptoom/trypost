<?php

declare(strict_types=1);

use App\Enums\WorkspaceConversation\Status;
use App\Models\WorkspaceConversation;

test('it releases in-progress conversations older than ten minutes', function () {
    $stalled = WorkspaceConversation::factory()->inProgress()->create();
    $stalled->forceFill(['updated_at' => now()->subMinutes(11)])->saveQuietly();

    $fresh = WorkspaceConversation::factory()->inProgress()->create();

    $this->artisan('chat:release-stalled')->assertSuccessful();

    expect($stalled->fresh()->status)->toBe(Status::Idle)
        ->and($fresh->fresh()->status)->toBe(Status::InProgress);
});

test('it does not restamp updated_at on the conversations it releases', function () {
    $stalled = WorkspaceConversation::factory()->inProgress()->create();
    $staleUpdatedAt = now()->subDays(3);
    $stalled->forceFill(['updated_at' => $staleUpdatedAt])->saveQuietly();

    $this->artisan('chat:release-stalled')->assertSuccessful();

    expect($stalled->fresh()->updated_at->toDateTimeString())->toBe($staleUpdatedAt->toDateTimeString());
});
