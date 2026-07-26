<?php

declare(strict_types=1);

use App\Events\OnboardingStatusUpdated;
use App\Models\AccessToken;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Observers\AccessTokenObserver;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([OnboardingStatusUpdated::class]);
});

test('creating a social account broadcasts onboarding status for its workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);

    SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
    ]);

    Event::assertDispatched(
        OnboardingStatusUpdated::class,
        fn (OnboardingStatusUpdated $event): bool => $event->workspaceId === $workspace->id,
    );
});

test('creating a post broadcasts onboarding status for its workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);

    Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    Event::assertDispatched(
        OnboardingStatusUpdated::class,
        fn (OnboardingStatusUpdated $event): bool => $event->workspaceId === $workspace->id,
    );
});

test('creating a personal access token broadcasts onboarding status for the current workspace', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $user->createToken('MCP');

    Event::assertDispatched(
        OnboardingStatusUpdated::class,
        fn (OnboardingStatusUpdated $event): bool => $event->workspaceId === $workspace->id,
    );
});

test('workspace-scoped access tokens do not broadcast onboarding status', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);

    $token = new AccessToken([
        'user_id' => $user->id,
        'workspace_id' => $workspace->id,
        'revoked' => false,
    ]);

    (new AccessTokenObserver)->created($token);

    Event::assertNotDispatched(OnboardingStatusUpdated::class);
});

test('personal tokens without a current workspace do not broadcast', function () {
    $user = User::factory()->create(['current_workspace_id' => null]);

    $token = new AccessToken([
        'user_id' => $user->id,
        'workspace_id' => null,
        'revoked' => false,
    ]);

    (new AccessTokenObserver)->created($token);

    Event::assertNotDispatched(OnboardingStatusUpdated::class);
});

test('does not broadcast when onboarding is already completed', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $user->account->update(['onboarding_completed_at' => now()]);

    Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    Event::assertNotDispatched(OnboardingStatusUpdated::class);
});

test('does not broadcast when onboarding is dismissed', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $user->account->update(['onboarding_dismissed_at' => now()]);

    SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
    ]);

    Event::assertNotDispatched(OnboardingStatusUpdated::class);
});
