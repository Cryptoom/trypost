<?php

declare(strict_types=1);

use App\Actions\Onboarding\ResolveOnboardingStatus;
use App\Enums\PostHog\OnboardingEvent;
use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Events\OnboardingStatusUpdated;
use App\Jobs\PostHog\SendEvent;
use App\Models\AccessToken;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    config([
        'trypost.self_hosted' => false,
        'services.posthog.enabled' => true,
        'services.posthog.api_key' => 'phc_test',
    ]);

    Bus::fake();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user->refresh();

    subscribeAccount($this->user->account);
});

test('onboarding renders activation status and connection props', function () {
    $socialAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $this->actingAs($this->user)
        ->get(route('app.onboarding'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/Index', false)
            ->where('status.mcp_connected', false)
            ->where('status.social_connected', true)
            ->where('status.first_post_created', false)
            ->where('status.all_complete', false)
            ->where('status.show_residual', true)
            ->where('status.completed_at', null)
            ->where('status.dismissed_at', null)
            ->where('mcpUrl', route('mcp.trypost'))
            ->where('canDismiss', true)
            ->where('mcpClients', collect(config('trypost.mcp.clients'))
                ->map(fn (array $client, string $id): array => [
                    'id' => $id,
                    'label' => data_get($client, 'label'),
                    'logo' => data_get($client, 'logo'),
                    'settings_url' => data_get($client, 'settings_url'),
                ])
                ->values()
                ->all())
            ->where('samplePrompt', __('onboarding.first_post.sample_prompt'))
            ->has('platforms', collect(Platform::cases())->filter->isConnectable()->count())
            ->where('accounts.0.id', $socialAccount->id)
        );

    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => $event->method === 'capture'
        && data_get($event->payload, 'distinctId') === $this->user->id
        && data_get($event->payload, 'event') === OnboardingEvent::Viewed->value);
});

test('onboarding does not capture viewed during a partial reload', function () {
    $response = $this->actingAs($this->user)
        ->get(route('app.onboarding'))
        ->assertOk();

    Bus::fake();

    $response->assertInertia(fn ($page) => $page
        ->reloadOnly(['status', 'accounts', 'onboardingResidual'], fn ($reload) => $reload
            ->has('status')
            ->has('accounts')
        )
    );

    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::Viewed->value,
    );
});

test('onboarding can be dismissed', function () {
    Carbon::setTestNow('2026-07-24 12:00:00');
    Event::fake([OnboardingStatusUpdated::class]);

    $this->actingAs($this->user)
        ->post(route('app.onboarding.dismiss'))
        ->assertRedirect(route('app.calendar'));

    expect($this->user->account->fresh()->onboarding_dismissed_at?->equalTo(now()))->toBeTrue();

    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::Skipped->value);
    Event::assertDispatched(
        OnboardingStatusUpdated::class,
        fn (OnboardingStatusUpdated $event): bool => $event->workspaceId === $this->workspace->id,
    );
});

test('dismissed accounts are redirected away from onboarding index', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');
    $this->user->account->update(['onboarding_dismissed_at' => now()]);

    Bus::fake();

    $this->actingAs($this->user->fresh())
        ->get(route('app.onboarding'))
        ->assertRedirect(route('app.calendar'));

    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::Viewed->value,
    );
});

test('completed accounts are redirected away from onboarding on full visits', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');
    $this->user->account->update(['onboarding_completed_at' => now()]);

    Bus::fake();

    $this->actingAs($this->user->fresh())
        ->get(route('app.onboarding'))
        ->assertRedirect(route('app.calendar'));

    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::Viewed->value,
    );
});

test('completed accounts still see celebration after just-completed session flash', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');

    AccessToken::withoutEvents(fn () => mcpAccessToken($this->user, mcpOauthClient()));
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    // Stamp during an authenticated request (OAuth/observer path) so the flash is set.
    $this->actingAs($this->user->fresh())
        ->get(route('app.calendar'))
        ->assertOk();

    expect(app(ResolveOnboardingStatus::class)->markCompleted($this->user->fresh()))->toBeTrue();

    Bus::fake();

    $this->actingAs($this->user->fresh())
        ->get(route('app.onboarding'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/Index', false)
            ->where('status.all_complete', true)
            ->where('status.completed_at', now()->toIso8601String())
        );

    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::Viewed->value,
    );
});

test('completed accounts still see celebration on onboarding partial reloads', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');

    AccessToken::withoutEvents(fn () => mcpAccessToken($this->user, mcpOauthClient()));
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));
    $this->user->account->update(['onboarding_completed_at' => now()]);

    $this->actingAs($this->user->fresh())
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'X-Inertia-Partial-Component' => 'onboarding/Index',
            'X-Inertia-Partial-Data' => 'status,accounts,onboardingResidual',
        ])
        ->get(route('app.onboarding'))
        ->assertOk()
        ->assertJsonPath('component', 'onboarding/Index')
        ->assertJsonPath('props.status.all_complete', true)
        ->assertJsonPath('props.status.completed_at', now()->toIso8601String());
});

test('dismiss after completion redirects without rewriting dismissed_at', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');
    Event::fake([OnboardingStatusUpdated::class]);

    $this->user->account->update(['onboarding_completed_at' => now()]);

    Bus::fake();

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.dismiss'))
        ->assertRedirect(route('app.calendar'));

    expect($this->user->account->fresh()->onboarding_dismissed_at)->toBeNull();

    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::Skipped->value,
    );
    Event::assertNotDispatched(OnboardingStatusUpdated::class);
});

test('onboarding cannot be completed before every activation step', function () {
    $this->actingAs($this->user)
        ->post(route('app.onboarding.complete'))
        ->assertRedirect(route('app.onboarding'));

    expect($this->user->account->fresh()->onboarding_completed_at)->toBeNull();

    Bus::assertNotDispatched(SendEvent::class, fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::Completed->value);
});

test('onboarding completes after every activation step', function () {
    Carbon::setTestNow('2026-07-24 12:00:00');
    Event::fake([OnboardingStatusUpdated::class]);

    AccessToken::withoutEvents(fn () => mcpAccessToken($this->user, mcpOauthClient()));
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.complete'))
        ->assertRedirect(route('app.calendar'));

    expect($this->user->account->fresh()->onboarding_completed_at?->equalTo(now()))->toBeTrue();

    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::Completed->value);
    Event::assertDispatched(
        OnboardingStatusUpdated::class,
        fn (OnboardingStatusUpdated $event): bool => $event->workspaceId === $this->workspace->id,
    );
});

test('onboarding complete does not re-fire completed when already stamped', function () {
    Carbon::setTestNow('2026-07-24 12:00:00');

    AccessToken::withoutEvents(fn () => mcpAccessToken($this->user, mcpOauthClient()));
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));
    $this->user->account->update(['onboarding_completed_at' => now()]);

    Bus::fake();

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.complete'))
        ->assertRedirect(route('app.calendar'));

    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::Completed->value,
    );
});

test('members do not see the skip control', function () {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, [
        'role' => Role::Member->value,
    ]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $this->actingAs($member->fresh())
        ->get(route('app.onboarding'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canDismiss', false));
});

test('only the account owner can dismiss onboarding', function () {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, [
        'role' => Role::Member->value,
    ]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $this->actingAs($member->fresh())
        ->post(route('app.onboarding.dismiss'))
        ->assertForbidden();

    expect($this->user->account->fresh()->onboarding_dismissed_at)->toBeNull();
});

test('teammates can stamp completion via the complete endpoint', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');
    Event::fake([OnboardingStatusUpdated::class]);

    AccessToken::withoutEvents(fn () => mcpAccessToken($this->user, mcpOauthClient()));
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, [
        'role' => Role::Member->value,
    ]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);

    $this->actingAs($member->fresh())
        ->post(route('app.onboarding.complete'))
        ->assertRedirect(route('app.calendar'));

    expect($this->user->account->fresh()->onboarding_completed_at?->equalTo(now()))->toBeTrue();

    Event::assertDispatched(
        OnboardingStatusUpdated::class,
        fn (OnboardingStatusUpdated $event): bool => $event->workspaceId === $this->workspace->id,
    );
    Event::assertDispatched(
        OnboardingStatusUpdated::class,
        fn (OnboardingStatusUpdated $event): bool => $event->workspaceId === $otherWorkspace->id,
    );
});

test('complete stamps via tryMarkAccountComplete when another workspace is ready', function () {
    Carbon::setTestNow('2026-07-29 12:00:00');
    Event::fake([OnboardingStatusUpdated::class]);

    AccessToken::withoutEvents(fn () => mcpAccessToken($this->user, mcpOauthClient()));
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    $emptyWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->user->update(['current_workspace_id' => $emptyWorkspace->id]);

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.complete'))
        ->assertRedirect(route('app.calendar'));

    expect($this->user->account->fresh()->onboarding_completed_at?->equalTo(now()))->toBeTrue();
});

test('complete after dismiss redirects without stamping completion', function () {
    Carbon::setTestNow('2026-07-24 12:00:00');

    AccessToken::withoutEvents(fn () => mcpAccessToken($this->user, mcpOauthClient()));
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));
    $this->user->account->update(['onboarding_dismissed_at' => now()]);

    Bus::fake();

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.complete'))
        ->assertRedirect(route('app.calendar'));

    expect($this->user->account->fresh()->onboarding_completed_at)->toBeNull();

    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::Completed->value,
    );
});

test('unsubscribed accounts are redirected to welcome by middleware', function (string $routeName, string $method) {
    $this->user->account->subscriptions()->delete();
    $this->actingAs($this->user->fresh());

    $response = $method === 'get'
        ? $this->get(route($routeName))
        : $this->post(route($routeName));

    $response->assertRedirect(route('app.welcome.persona'));
})->with([
    'index' => ['app.onboarding', 'get'],
    'dismiss' => ['app.onboarding.dismiss', 'post'],
    'complete' => ['app.onboarding.complete', 'post'],
]);

test('self hosted activation endpoints redirect to calendar', function (string $routeName, string $method) {
    config(['trypost.self_hosted' => true]);
    $this->actingAs($this->user);

    $response = $method === 'get'
        ? $this->get(route($routeName))
        : $this->post(route($routeName));

    $response->assertRedirect(route('app.calendar'));
})->with([
    'index' => ['app.onboarding', 'get'],
    'dismiss' => ['app.onboarding.dismiss', 'post'],
    'complete' => ['app.onboarding.complete', 'post'],
]);
