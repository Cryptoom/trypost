<?php

declare(strict_types=1);

use App\Actions\Onboarding\ResolveOnboardingStatus;
use App\Enums\PostHog\OnboardingEvent;
use App\Events\OnboardingStatusUpdated;
use App\Jobs\PostHog\SendEvent;
use App\Models\AccessToken;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user->refresh();

    subscribeAccount($this->user->account);
});

test('resolves the empty onboarding state', function () {
    $status = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($status)->toBe([
        'mcp_connected' => false,
        'social_connected' => false,
        'first_post_created' => false,
        'all_complete' => false,
        'show_residual' => true,
        'completed_at' => null,
        'dismissed_at' => null,
    ]);
});

test('resolves an OAuth token as MCP connected', function () {
    mcpAccessToken($this->user, mcpOauthClient());

    $status = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($status)->toMatchArray([
        'mcp_connected' => true,
        'social_connected' => false,
        'first_post_created' => false,
        'all_complete' => false,
    ]);
});

test('does not resolve a personal access token as MCP connected', function () {
    $this->user->createToken('Personal Access Token');

    $status = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($status['mcp_connected'])->toBeFalse();
});

test('does not resolve a workspace-bound personal access token as MCP connected', function () {
    $result = $this->user->createToken('Personal Access Token');
    AccessToken::find($result->token->id)
        ->forceFill(['workspace_id' => $this->workspace->id])
        ->saveQuietly();

    $status = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($status['mcp_connected'])->toBeFalse();
});

test('does not resolve a revoked token as MCP connected', function () {
    $token = mcpAccessToken($this->user, mcpOauthClient());
    $token->forceFill(['revoked' => true])->saveQuietly();

    $status = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($status['mcp_connected'])->toBeFalse();
});

test('resolves a social account in the current workspace as connected', function () {
    SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    $status = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($status)->toMatchArray([
        'mcp_connected' => false,
        'social_connected' => true,
        'first_post_created' => false,
        'all_complete' => false,
    ]);
});

test('captures each completed step once without re-firing later', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Carbon::setTestNow('2026-07-24 12:00:00');
    Bus::fake();

    $cacheKey = "onboarding_step:{$this->user->account_id}:social_connected";
    Cache::forget($cacheKey);
    SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    app(ResolveOnboardingStatus::class)->syncProgress($this->user);
    app(ResolveOnboardingStatus::class)->syncProgress($this->user);

    Bus::assertDispatchedTimes(SendEvent::class, 1);
    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => $event->method === 'capture'
        && data_get($event->payload, 'event') === OnboardingEvent::StepCompleted->value
        && data_get($event->payload, 'properties.step') === 'social_connected');

    Carbon::setTestNow(now()->addDays(31));
    app(ResolveOnboardingStatus::class)->syncProgress($this->user);

    Bus::assertDispatchedTimes(SendEvent::class, 1);
});

test('does not resolve an expired oauth token as mcp connected', function () {
    $token = AccessToken::withoutEvents(fn () => mcpAccessToken($this->user, mcpOauthClient()));
    $token->forceFill(['expires_at' => now()->subMinute()])->saveQuietly();

    $status = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($status['mcp_connected'])->toBeFalse();
});

test('resolves account-scoped steps even when the user has no current workspace', function () {
    $this->user->update(['current_workspace_id' => null]);
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));
    AccessToken::withoutEvents(fn () => mcpAccessToken($this->user, mcpOauthClient()));

    $status = app(ResolveOnboardingStatus::class)->handle($this->user->fresh());

    expect($status)->toMatchArray([
        'mcp_connected' => true,
        'social_connected' => true,
        'first_post_created' => true,
        'all_complete' => true,
    ]);
});

test('members do not see the residual checklist', function () {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    expect(app(ResolveOnboardingStatus::class)->residual($member->fresh()))->toBeFalse()
        ->and(app(ResolveOnboardingStatus::class)->handle($member->fresh())['show_residual'])->toBeFalse();
});

test('generic trial without card still shows residual for the owner', function () {
    config(['trypost.billing.require_card_for_trial' => false]);
    $this->user->account->subscriptions()->delete();
    $this->user->account->update(['trial_ends_at' => now()->addDays(7)]);

    expect(app(ResolveOnboardingStatus::class)->residual($this->user->fresh()))->toBe([
        'completed' => 0,
        'total' => ResolveOnboardingStatus::TOTAL_STEPS,
    ]);
});

test('resolves a social account in another workspace as connected', function () {
    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    SocialAccount::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $status = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($status['social_connected'])->toBeTrue();
});

test('resolves any post in the current workspace as the first post', function () {
    Post::factory()->failed()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $status = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($status)->toMatchArray([
        'mcp_connected' => false,
        'social_connected' => false,
        'first_post_created' => true,
        'all_complete' => false,
    ]);
});

test('resolves a post in another workspace as the first post', function () {
    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    Post::factory()->create([
        'workspace_id' => $otherWorkspace->id,
        'user_id' => $this->user->id,
    ]);

    $status = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($status['first_post_created'])->toBeTrue();
});

test('marks onboarding completed once all three steps are complete', function () {
    Carbon::setTestNow('2026-07-24 12:00:00');

    mcpAccessToken($this->user, mcpOauthClient());
    SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);
    Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $status = app(ResolveOnboardingStatus::class)->syncProgress($this->user);

    expect($status)->toBe([
        'mcp_connected' => true,
        'social_connected' => true,
        'first_post_created' => true,
        'all_complete' => true,
        'show_residual' => false,
        'completed_at' => now()->toIso8601String(),
        'dismissed_at' => null,
    ])->and($this->user->account->fresh()->onboarding_completed_at?->equalTo(now()))->toBeTrue();

    Carbon::setTestNow(now()->addHour());
    app(ResolveOnboardingStatus::class)->syncProgress($this->user->fresh());

    expect($this->user->account->fresh()->onboarding_completed_at?->toIso8601String())
        ->toBe('2026-07-24T12:00:00+00:00');
});

test('handle does not mutate the account when every step is complete', function () {
    AccessToken::withoutEvents(fn () => mcpAccessToken($this->user, mcpOauthClient()));
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    $status = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($status)->toMatchArray([
        'all_complete' => true,
        'show_residual' => false,
        'completed_at' => null,
    ])->and($this->user->account->fresh()->onboarding_completed_at)->toBeNull();
});

test('stamps completion when the last step completes off the onboarding page', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Carbon::setTestNow('2026-07-24 12:00:00');
    Bus::fake();

    mcpAccessToken($this->user, mcpOauthClient());
    SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);

    expect($this->user->account->fresh()->onboarding_completed_at)->toBeNull();

    Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    expect($this->user->account->fresh()->onboarding_completed_at?->equalTo(now()))->toBeTrue();

    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::Completed->value);
});

test('resolves a teammate oauth token as mcp connected for the account', function () {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    AccessToken::withoutEvents(fn () => mcpAccessToken($member, mcpOauthClient()));

    $status = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($status['mcp_connected'])->toBeTrue();
});

test('dismissed onboarding does not show the residual checklist', function () {
    Carbon::setTestNow('2026-07-24 12:00:00');
    $this->user->account->update(['onboarding_dismissed_at' => now()]);

    $status = app(ResolveOnboardingStatus::class)->handle($this->user->fresh());

    expect($status['show_residual'])->toBeFalse()
        ->and($status['dismissed_at'])->toBe(now()->toIso8601String());
});

test('completed onboarding returns immediately without resolving steps or capturing analytics', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Carbon::setTestNow('2026-07-24 12:00:00');
    Bus::fake();
    $this->user->account->update(['onboarding_completed_at' => now()]);

    $status = app(ResolveOnboardingStatus::class)->syncProgress($this->user->fresh());

    expect($status)->toBe([
        'mcp_connected' => true,
        'social_connected' => true,
        'first_post_created' => true,
        'all_complete' => true,
        'show_residual' => false,
        'completed_at' => now()->toIso8601String(),
        'dismissed_at' => null,
    ]);
    Bus::assertNothingDispatched();
});

test('self-hosted onboarding does not show the residual checklist', function () {
    config(['trypost.self_hosted' => true]);

    $status = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($status['show_residual'])->toBeFalse();
});

test('unsubscribed account does not show the residual checklist', function () {
    $this->user->account->subscriptions()->delete();

    $status = app(ResolveOnboardingStatus::class)->handle($this->user);

    expect($status['show_residual'])->toBeFalse();
});

test('residual returns progress counts while onboarding is active', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    expect(app(ResolveOnboardingStatus::class)->residual($this->user))->toBe([
        'completed' => 1,
        'total' => ResolveOnboardingStatus::TOTAL_STEPS,
    ]);
});

test('checklist and residual both count social and posts from any workspace', function () {
    AccessToken::withoutEvents(fn () => mcpAccessToken($this->user, mcpOauthClient()));

    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $otherWorkspace->id,
    ]));

    $emptyWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->user->update(['current_workspace_id' => $emptyWorkspace->id]);

    expect(app(ResolveOnboardingStatus::class)->handle($this->user->fresh()))->toMatchArray([
        'mcp_connected' => true,
        'social_connected' => true,
        'first_post_created' => false,
        'all_complete' => false,
        'show_residual' => true,
    ])->and(app(ResolveOnboardingStatus::class)->residual($this->user->fresh()))->toBe([
        'completed' => 2,
        'total' => ResolveOnboardingStatus::TOTAL_STEPS,
    ]);
});

test('residual returns false when the banner should not show', function () {
    $this->user->account->update(['onboarding_dismissed_at' => now()]);

    expect(app(ResolveOnboardingStatus::class)->residual($this->user->fresh()))->toBeFalse();
});

test('tryMarkAccountComplete stamps when another workspace is already ready', function () {
    Carbon::setTestNow('2026-07-24 12:00:00');

    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $memberWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $member->id,
    ]);
    $member->update(['current_workspace_id' => $memberWorkspace->id]);

    expect(app(ResolveOnboardingStatus::class)->tryMarkAccountComplete(
        $this->user->account->fresh(),
        $member->fresh(),
    ))->toBeFalse();

    AccessToken::withoutEvents(fn () => mcpAccessToken($member, mcpOauthClient()));

    expect(app(ResolveOnboardingStatus::class)->tryMarkAccountComplete(
        $this->user->account->fresh(),
        $member->fresh(),
    ))->toBeTrue()
        ->and($this->user->account->fresh()->onboarding_completed_at?->equalTo(now()))->toBeTrue();
});

test('mcp connection attributes step analytics to the acting teammate', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();
    Cache::flush();

    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    $member = User::factory()->create([
        'account_id' => $this->user->account_id,
        'current_workspace_id' => $this->workspace->id,
    ]);

    // Owner has a lower id and would win if we still fan-out by users.id asc.
    expect($this->user->id < $member->id)->toBeTrue();

    mcpAccessToken($member, mcpOauthClient());

    Bus::assertDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => $event->method === 'capture'
            && data_get($event->payload, 'event') === OnboardingEvent::StepCompleted->value
            && data_get($event->payload, 'properties.step') === 'mcp_connected'
            && data_get($event->payload, 'distinctId') === (string) $member->id,
    );
});

test('markCompleted leaves the in-memory account clean', function () {
    Carbon::setTestNow('2026-07-24 12:00:00');
    Event::fake([OnboardingStatusUpdated::class]);

    expect(app(ResolveOnboardingStatus::class)->markCompleted($this->user))->toBeTrue()
        ->and($this->user->account->isDirty())->toBeFalse()
        ->and($this->user->account->onboarding_completed_at?->equalTo(now()))->toBeTrue();

    Event::assertDispatched(
        OnboardingStatusUpdated::class,
        fn (OnboardingStatusUpdated $event): bool => $event->workspaceId === $this->workspace->id,
    );
});

test('markCompleted refuses to stamp after onboarding was dismissed', function () {
    $this->user->account->update(['onboarding_dismissed_at' => now()]);

    expect(app(ResolveOnboardingStatus::class)->markCompleted($this->user->fresh()))->toBeFalse()
        ->and($this->user->account->fresh()->onboarding_completed_at)->toBeNull();
});

test('syncProgress stamps when another workspace already finished social and post', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');

    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));
    AccessToken::withoutEvents(fn () => mcpAccessToken($this->user, mcpOauthClient()));

    $emptyWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->user->update(['current_workspace_id' => $emptyWorkspace->id]);

    $status = app(ResolveOnboardingStatus::class)->syncProgress($this->user->fresh());

    expect($status['all_complete'])->toBeTrue()
        ->and($status['show_residual'])->toBeFalse()
        ->and($this->user->account->fresh()->onboarding_completed_at?->equalTo(now()))->toBeTrue();
});

test('residual hides without writing when another workspace already finished activation', function () {
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));
    AccessToken::withoutEvents(fn () => mcpAccessToken($this->user, mcpOauthClient()));

    $emptyWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->user->update(['current_workspace_id' => $emptyWorkspace->id]);

    expect(app(ResolveOnboardingStatus::class)->residual($this->user->fresh()))->toBeFalse()
        ->and($this->user->account->fresh()->onboarding_completed_at)->toBeNull();
});
