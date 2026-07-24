<?php

declare(strict_types=1);

use App\Enums\PostHog\OnboardingEvent;
use App\Enums\SocialAccount\Platform;
use App\Jobs\PostHog\SendEvent;
use App\Models\AccessToken;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

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
            ->where('mcpUrl', url('/mcp/trypost'))
            ->where('mcpClients', [
                ['id' => 'claude', 'label' => 'Claude'],
                ['id' => 'chatgpt', 'label' => 'ChatGPT'],
            ])
            ->where('samplePrompt', __('onboarding.first_post.sample_prompt'))
            ->has('platforms', collect(Platform::cases())->filter->isConnectable()->count())
            ->where('accounts.0.id', $socialAccount->id)
            ->where('createPostUrl', route('app.posts.create'))
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
        ->reloadOnly(['status', 'accounts'], fn ($reload) => $reload
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

    $this->actingAs($this->user)
        ->post(route('app.onboarding.dismiss'))
        ->assertRedirect(route('app.calendar'));

    expect($this->user->account->fresh()->onboarding_dismissed_at?->equalTo(now()))->toBeTrue();

    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::Skipped->value);
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

    $token = $this->user->createToken('OAuth Session');
    AccessToken::findOrFail($token->token->id)
        ->forceFill(['workspace_id' => null])
        ->saveQuietly();
    SocialAccount::factory()->create(['workspace_id' => $this->workspace->id]);
    Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.complete'))
        ->assertRedirect(route('app.calendar'));

    expect($this->user->account->fresh()->onboarding_completed_at?->equalTo(now()))->toBeTrue();

    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::Completed->value);
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
