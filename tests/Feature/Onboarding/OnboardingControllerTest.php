<?php

declare(strict_types=1);

use App\Actions\Billing\StartSubscriptionCheckout;
use App\Enums\Plan\Slug;
use App\Enums\PostHog\CheckoutEvent;
use App\Enums\PostHog\OnboardingEvent;
use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Enums\User\Goal;
use App\Enums\User\Persona;
use App\Enums\User\PublishMethod;
use App\Enums\User\ReferralSource;
use App\Enums\UserWorkspace\Role;
use App\Enums\Workspace\ContentLanguage;
use App\Jobs\PostHog\SendEvent;
use App\Models\Account;
use App\Models\Plan;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\PostHogService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);
    $this->user = User::factory()->create();
});

test('onboarding renders the persona step for an unsubscribed account', function () {
    $this->actingAs($this->user)
        ->get(route('app.onboarding'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/Chat', false)
            ->where('step', 'persona')
            ->where('history', [])
            ->has('personas', count(Persona::cases()))
            ->has('goals', count(Goal::cases()))
            ->has('sources', count(ReferralSource::cases()))
            ->has('publishMethods', count(PublishMethod::cases()))
            ->where('selectedPublishMethod', null)
            ->missing('connectedClients')
        );
});

test('legacy onboarding step urls redirect to onboarding', function (string $routeName) {
    $this->actingAs($this->user)
        ->get(route($routeName))
        ->assertRedirect(route('app.onboarding'));
})->with([
    'persona' => ['app.onboarding.persona'],
    'goals' => ['app.onboarding.goals'],
    'referral source' => ['app.onboarding.referral-source'],
    'publish method' => ['app.onboarding.publish-method'],
    'connect' => ['app.onboarding.connect'],
]);

test('persona requires a valid selection', function (array $payload) {
    $this->actingAs($this->user)
        ->post(route('app.onboarding.persona.store'), $payload)
        ->assertSessionHasErrors('persona');

    expect($this->user->fresh()->persona)->toBeNull();
})->with([
    'missing' => [[]],
    'invalid' => [['persona' => 'not-a-persona']],
]);

test('persona store saves the selection mirrors it to PostHog and advances to goals', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();

    $this->actingAs($this->user)
        ->post(route('app.onboarding.persona.store'), ['persona' => Persona::Agency->value])
        ->assertRedirect(route('app.onboarding'));

    expect($this->user->fresh()->persona)->toBe(Persona::Agency);
    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => $event->method === 'capture'
        && data_get($event->payload, 'distinctId') === $this->user->id
        && data_get($event->payload, 'event') === OnboardingEvent::Persona->value
        && data_get($event->payload, 'properties.persona') === Persona::Agency->value);
});

test('persona store returns the next chat state as json without a redirect', function () {
    $this->actingAs($this->user)
        ->postJson(route('app.onboarding.persona.store'), ['persona' => Persona::Agency->value])
        ->assertOk()
        ->assertJsonPath('step', 'goals')
        ->assertJsonPath('history.0.step', 'persona')
        ->assertJsonPath('history.0.values.0', Persona::Agency->value)
        ->assertJsonPath('selectedPersona', Persona::Agency->value);

    expect($this->user->fresh()->persona)->toBe(Persona::Agency);
});

test('referral store json includes connect props', function () {
    completeOnboardingThroughReferral($this->user);
    $this->user->update(['referral_source' => null]);
    attachCurrentWorkspace($this->user);

    $this->actingAs($this->user->fresh())
        ->postJson(route('app.onboarding.referral-source.store'), [
            'referral_source' => ReferralSource::ProductHunt->value,
        ])
        ->assertOk()
        ->assertJsonPath('step', 'connect')
        ->assertJsonPath('history.2.step', 'referral')
        ->assertJsonPath('mcpUrl', route('mcp.trypost'))
        ->assertJsonPath('connectedClients', []);
});

test('onboarding stays on persona until a persona is selected', function () {
    $this->actingAs($this->user)
        ->get(route('app.onboarding'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/Chat', false)
            ->where('step', 'persona')
        );
});

test('onboarding opens goals after a persona is selected', function () {
    $this->user->update(['persona' => Persona::Agency->value]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.onboarding'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/Chat', false)
            ->where('step', 'goals')
            ->where('history.0.step', 'persona')
            ->where('history.0.values', [Persona::Agency->value])
            ->has('goals', count(Goal::cases()))
        );
});

test('goals requires at least one valid goal', function (array $goals, string $error) {
    $this->user->update(['persona' => Persona::Agency->value]);

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.goals.store'), ['goals' => $goals])
        ->assertSessionHasErrors($error);

    expect($this->user->fresh()->goals)->toBeNull();
})->with([
    'empty' => [[], 'goals'],
    'invalid' => [['not-a-goal'], 'goals.0'],
]);

test('goals store saves choices mirrors them to PostHog and advances to referral source', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();
    $this->user->update(['persona' => Persona::Creator->value]);

    $goals = [Goal::AiContent->value, Goal::SaveTime->value];

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.goals.store'), ['goals' => $goals])
        ->assertRedirect(route('app.onboarding'));

    expect($this->user->fresh()->goals)->toBe($goals);
    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => $event->method === 'capture'
        && data_get($event->payload, 'event') === OnboardingEvent::Goals->value
        && data_get($event->payload, 'properties.goals') === $goals);
});

test('onboarding includes prior answers so the chat can go back without leaving the page', function () {
    attachCurrentWorkspace($this->user);
    $this->user->update([
        'persona' => Persona::Agency->value,
        'goals' => [Goal::SaveTime->value],
        'referral_source' => ReferralSource::Google->value,
    ]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.onboarding'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/Chat', false)
            ->where('step', 'connect')
            ->has('history', 3)
            ->has('personas', count(Persona::cases()))
            ->has('goals', count(Goal::cases()))
            ->has('sources', count(ReferralSource::cases()))
        );
});

test('onboarding opens the first incomplete step', function (array $attributes, string $step) {
    $this->user->update($attributes);

    $this->actingAs($this->user->fresh())
        ->get(route('app.onboarding'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/Chat', false)
            ->where('step', $step)
        );
})->with([
    'missing persona' => [[], 'persona'],
    'missing goals' => [['persona' => Persona::Agency->value], 'goals'],
    'only removed goals' => [
        [
            'persona' => Persona::Agency->value,
            'goals' => ['team_collaboration', 'automate_api', 'track_performance'],
        ],
        'goals',
    ],
    'missing referral' => [
        [
            'persona' => Persona::Agency->value,
            'goals' => [Goal::SaveTime->value],
        ],
        'referral',
    ],
]);

test('onboarding allows users who still have at least one current goal', function () {
    $this->user->update([
        'persona' => Persona::Agency->value,
        'goals' => [Goal::SaveTime->value, 'team_collaboration'],
    ]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.onboarding'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('onboarding/Chat', false)->where('step', 'referral'));
});

test('onboarding opens referral after prior steps are complete', function () {
    $this->user->update([
        'persona' => Persona::Agency->value,
        'goals' => [Goal::SaveTime->value],
    ]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.onboarding'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/Chat', false)
            ->where('step', 'referral')
            ->where('history.0.step', 'persona')
            ->where('history.1.step', 'goals')
            ->has('sources', count(ReferralSource::cases()))
            ->where('sources', fn ($sources): bool => collect($sources)->contains(ReferralSource::GitHub->value)
                && collect($sources)->contains(ReferralSource::Threads->value)
                && collect($sources)->contains(ReferralSource::HackerNews->value)
                && collect($sources)->contains(ReferralSource::Directories->value)
                && collect($sources)->contains(ReferralSource::Founder->value))
            ->missing('plan')
        );
});

test('referral source requires a valid selection', function (array $payload) {
    $this->user->update([
        'persona' => Persona::Agency->value,
        'goals' => [Goal::SaveTime->value],
    ]);

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.referral-source.store'), $payload)
        ->assertSessionHasErrors('referral_source');

    expect($this->user->fresh()->referral_source)->toBeNull();
})->with([
    'missing' => [[]],
    'invalid' => [['referral_source' => 'not-a-source']],
]);

test('onboarding funnel captures connect between referral and checkout.started', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();
    $workspace = attachCurrentWorkspace($this->user);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);

    Plan::where('slug', Slug::Workspace)->firstOrFail()->update([
        'stripe_monthly_price_id' => 'price_monthly_test',
    ]);

    $this->mock(StartSubscriptionCheckout::class)
        ->shouldReceive('redirect')
        ->once()
        ->andReturn(redirect('https://checkout.stripe.test/session'));

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.persona.store'), ['persona' => Persona::Agency->value])
        ->assertRedirect(route('app.onboarding'));

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.goals.store'), ['goals' => [Goal::SaveTime->value]])
        ->assertRedirect(route('app.onboarding'));

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.referral-source.store'), [
            'referral_source' => ReferralSource::ProductHunt->value,
        ])
        ->assertRedirect(route('app.onboarding'));

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.publish-method.store'), [
            'publish_method' => PublishMethod::Manual->value,
        ])
        ->assertRedirect(route('app.onboarding'));

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.connect.store'))
        ->assertRedirect('https://checkout.stripe.test/session');

    $funnel = OnboardingEvent::funnel();

    $captured = collect(Bus::dispatched(SendEvent::class))
        ->filter(fn (SendEvent $event): bool => $event->method === 'capture')
        ->map(fn (SendEvent $event): string => (string) data_get($event->payload, 'event'))
        ->filter(fn (string $event): bool => in_array($event, $funnel, true))
        ->values()
        ->all();

    expect($captured)->toBe($funnel);
});

test('referral source store saves the source mirrors it to PostHog and advances to connect', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();
    $this->user->update([
        'persona' => Persona::Agency->value,
        'goals' => [Goal::SaveTime->value],
    ]);

    $this->mock(StartSubscriptionCheckout::class)->shouldNotReceive('redirect');

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.referral-source.store'), [
            'referral_source' => ReferralSource::ProductHunt->value,
        ])
        ->assertRedirect(route('app.onboarding'));

    expect($this->user->fresh()->referral_source)->toBe(ReferralSource::ProductHunt);
    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => $event->method === 'capture'
        && data_get($event->payload, 'event') === OnboardingEvent::Referral->value
        && data_get($event->payload, 'properties.referral_source') === ReferralSource::ProductHunt->value);
    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === CheckoutEvent::Started->value,
    );
});

test('publish method requires a valid selection', function (array $payload) {
    completeOnboardingThroughReferral($this->user);
    $workspace = attachCurrentWorkspace($this->user);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.publish-method.store'), $payload)
        ->assertSessionHasErrors('publish_method');

    expect($this->user->fresh()->publish_method)->toBeNull();
})->with([
    'missing' => [[]],
    'invalid' => [['publish_method' => 'not-a-method']],
]);

test('publish method store saves the selection and mirrors it to PostHog', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();
    completeOnboardingThroughReferral($this->user);
    $workspace = attachCurrentWorkspace($this->user);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.publish-method.store'), [
            'publish_method' => PublishMethod::Ai->value,
        ])
        ->assertRedirect(route('app.onboarding'));

    expect($this->user->fresh()->publish_method)->toBe(PublishMethod::Ai);

    $this->actingAs($this->user->fresh())
        ->get(route('app.onboarding'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/Chat', false)
            ->where('selectedPublishMethod', PublishMethod::Ai->value)
        );

    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => $event->method === 'capture'
        && data_get($event->payload, 'event') === OnboardingEvent::PublishMethod->value
        && data_get($event->payload, 'properties.publish_method') === PublishMethod::Ai->value);
});

test('publish method store redirects when no social account is connected', function () {
    completeOnboardingThroughReferral($this->user);
    attachCurrentWorkspace($this->user);

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.publish-method.store'), [
            'publish_method' => PublishMethod::Manual->value,
        ])
        ->assertRedirect(route('app.onboarding'));

    expect($this->user->fresh()->publish_method)->toBeNull();
});

test('connect store redirects to onboarding when prior steps are incomplete', function (array $attributes, bool $withWorkspace) {
    $this->user->update($attributes);

    if ($withWorkspace) {
        attachCurrentWorkspace($this->user);
    }

    $this->mock(StartSubscriptionCheckout::class)->shouldNotReceive('redirect');

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.connect.store'))
        ->assertRedirect(route('app.onboarding'));
})->with([
    'missing persona' => [[]],
    'missing goals' => [['persona' => Persona::Agency->value]],
    'missing referral' => [[
        'persona' => Persona::Agency->value,
        'goals' => [Goal::SaveTime->value],
    ]],
    'only removed goals' => [[
        'persona' => Persona::Agency->value,
        'goals' => ['team_collaboration', 'automate_api', 'track_performance'],
        'referral_source' => ReferralSource::Google->value,
    ]],
])->with([
    'without workspace' => [false],
    'with empty workspace' => [true],
]);

test('connect returns 404 when prior steps are complete but the user has no workspace', function () {
    completeOnboardingThroughReferral($this->user);

    $this->mock(StartSubscriptionCheckout::class)->shouldNotReceive('redirect');

    $this->actingAs($this->user->fresh())
        ->get(route('app.onboarding'))
        ->assertNotFound();

    $this->actingAs($this->user->fresh())
        ->from(route('app.onboarding'))
        ->post(route('app.onboarding.connect.store'))
        ->assertNotFound();
});

test('connect labels X as X (Twitter)', function () {
    completeOnboardingThroughReferral($this->user);
    attachCurrentWorkspace($this->user);

    $xIndex = collect(SocialPlatform::connectableOptions())->search(
        fn (array $option): bool => data_get($option, 'value') === SocialPlatform::X->value,
    );

    expect($xIndex)->toBeInt();

    $this->actingAs($this->user->fresh())
        ->get(route('app.onboarding'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/Chat', false)
            ->where("platforms.{$xIndex}.value", SocialPlatform::X->value)
            ->where("platforms.{$xIndex}.label", 'X (Twitter)')
        );
});

test('connect renders the network grid when the workspace has no accounts', function () {
    completeOnboardingThroughReferral($this->user);
    attachCurrentWorkspace($this->user);

    $this->actingAs($this->user->fresh())
        ->get(route('app.onboarding'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/Chat', false)
            ->where('step', 'connect')
            ->has('history', 3)
            ->where('history.2.step', 'referral')
            ->has('platforms', count(SocialPlatform::connectableOptions()))
            ->where('accounts', [])
            ->where('latestPostNetwork', null)
            ->where('latestPost', null)
            ->where('mcpUrl', route('mcp.trypost'))
            ->where('connectedClients', [])
            ->where('selectedPublishMethod', null)
        );
});

test('connect lists connected mcp clients', function () {
    completeOnboardingThroughReferral($this->user);
    $workspace = attachCurrentWorkspace($this->user);
    $clientId = mcpOauthClient('Claude');
    mcpAccessToken($this->user, $clientId, $workspace);

    $this->actingAs($this->user->fresh())
        ->get(route('app.onboarding'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/Chat', false)
            ->where('step', 'connect')
            ->has('connectedClients', 1)
            ->where('connectedClients.0.client_id', $clientId)
            ->where('connectedClients.0.name', 'Claude')
        );
});

test('connect renders connected accounts for the current workspace', function () {
    completeOnboardingThroughReferral($this->user);
    $workspace = attachCurrentWorkspace($this->user);
    $account = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.onboarding'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/Chat', false)
            ->where('step', 'connect')
            ->has('platforms', count(SocialPlatform::connectableOptions()))
            ->has('accounts', 1)
            ->where('accounts.0.id', $account->id)
            ->where('accounts.0.platform', SocialPlatform::LinkedIn->value)
            ->where('accounts.0.status', Status::Connected->value)
            ->where('latestPostNetwork', null)
            ->where('latestPost', null)
        );
});

test('connect includes the latest post when the connected network exposes impressions', function () {
    completeOnboardingThroughReferral($this->user);
    $workspace = attachCurrentWorkspace($this->user);
    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $workspace->id,
        'platform_user_id' => '178414000',
    ]);

    Http::fake([
        config('trypost.platforms.instagram.graph_api').'/178414000/media*' => Http::response([
            'data' => [[
                'id' => '1789',
                'caption' => 'Hello from IG',
                'media_type' => 'IMAGE',
                'media_url' => 'https://cdn.example/photo.jpg',
                'permalink' => 'https://www.instagram.com/p/abc',
                'timestamp' => '2026-08-01T12:00:00+0000',
            ]],
        ]),
        config('trypost.platforms.instagram.graph_api').'/1789/insights*' => Http::response([
            'data' => [['name' => 'views', 'values' => [['value' => 1]]]],
        ]),
    ]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.onboarding'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/Chat', false)
            ->where('step', 'connect')
            ->where('accounts.0.id', $account->id)
            ->where('latestPostNetwork', 'instagram')
            ->missing('latestPost')
            ->loadDeferredProps(fn ($page) => $page
                ->where('latestPost.id', '1789')
                ->where('latestPost.caption', 'Hello from IG')
                ->where('latestPost.media_url', 'https://cdn.example/photo.jpg')
                ->where('latestPost.permalink', 'https://www.instagram.com/p/abc')
                ->where('latestPost.published_at', '2026-08-01T12:00:00+0000')
                ->where('latestPost.impressions', 1)
                ->where('latestPost.reach.network', 'Instagram')
                ->where('latestPost.reach.others.0.label', 'TikTok')
                ->where('latestPost.reach.others.1.label', 'YouTube')
                ->where('latestPost.reach.each_views', 1000)
                ->where('latestPost.reach.extra_views', 2000)
            )
        );
});

test('connect fetches the latest post from the first analytics-capable account', function () {
    completeOnboardingThroughReferral($this->user);
    $workspace = attachCurrentWorkspace($this->user);
    SocialAccount::factory()->discord()->create(['workspace_id' => $workspace->id]);
    SocialAccount::factory()->instagram()->create([
        'workspace_id' => $workspace->id,
        'platform_user_id' => '178414000',
    ]);

    Http::fake([
        config('trypost.platforms.instagram.graph_api').'/178414000/media*' => Http::response([
            'data' => [[
                'id' => '1789',
                'caption' => 'Hello from IG',
                'media_type' => 'IMAGE',
                'media_url' => 'https://cdn.example/photo.jpg',
                'permalink' => 'https://www.instagram.com/p/abc',
                'timestamp' => '2026-08-01T12:00:00+0000',
            ]],
        ]),
        config('trypost.platforms.instagram.graph_api').'/1789/insights*' => Http::response([
            'data' => [['name' => 'views', 'values' => [['value' => 1]]]],
        ]),
    ]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.onboarding'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/Chat', false)
            ->where('latestPostNetwork', 'instagram')
            ->missing('latestPost')
            ->loadDeferredProps(fn ($page) => $page
                ->where('latestPost.id', '1789')
                ->where('latestPost.reach.network', 'Instagram')
            )
        );
});

test('connect skips the latest post when the platform request fails', function () {
    completeOnboardingThroughReferral($this->user);
    $workspace = attachCurrentWorkspace($this->user);
    SocialAccount::factory()->instagram()->create([
        'workspace_id' => $workspace->id,
        'platform_user_id' => '178414000',
    ]);

    Http::fake([
        config('trypost.platforms.instagram.graph_api').'/178414000/media*' => Http::response(['error' => 'nope'], 500),
    ]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.onboarding'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/Chat', false)
            ->where('latestPostNetwork', 'instagram')
            ->missing('latestPost')
            ->loadDeferredProps(fn ($page) => $page
                ->where('latestPost', null)
            )
        );
});

test('connect copy exists in every locale', function (string $locale) {
    expect(__('onboarding.connect.title', [], $locale))->not->toBe('onboarding.connect.title')
        ->and(__('onboarding.connect.description', [], $locale))->not->toBe('onboarding.connect.description')
        ->and(__('onboarding.connect.follow_up', ['network' => 'Instagram'], $locale))->not->toBe('onboarding.connect.follow_up')
        ->and(__('onboarding.connect.latest_post', [], $locale))->not->toBe('onboarding.connect.latest_post')
        ->and(trans_choice('onboarding.connect.pitch_views', 1, ['views' => '1', 'network' => 'Instagram'], $locale))->not->toBe('onboarding.connect.pitch_views')
        ->and(__('onboarding.connect.pitch_no_views', ['network' => 'Instagram'], $locale))->not->toBe('onboarding.connect.pitch_no_views')
        ->and(trans_choice('onboarding.connect.pitch_missed', 0, [], $locale))->toBe('')
        ->and(trans_choice('onboarding.connect.pitch_missed', 1, [
            'first' => 'TikTok',
            'each' => '1,000',
            'extra' => '1,000',
        ], $locale))
        ->not->toBe('onboarding.connect.pitch_missed')
        ->toContain('TikTok')
        ->not->toContain(':first')
        ->not->toContain(':second')
        ->and(trans_choice('onboarding.connect.pitch_missed', 2, [
            'first' => 'TikTok',
            'second' => 'YouTube',
            'each' => '1,000',
            'extra' => '2,000',
        ], $locale))
        ->not->toBe('onboarding.connect.pitch_missed')
        ->toContain('TikTok')
        ->toContain('YouTube')
        ->and(__('onboarding.connect.pitch_sales', [], $locale))->not->toBe('onboarding.connect.pitch_sales')
        ->and(__('onboarding.connect.change_network', [], $locale))->not->toBe('onboarding.connect.change_network')
        ->and(__('onboarding.connect.required', [], $locale))->not->toBe('onboarding.connect.required')
        ->and(__('onboarding.publish_method.title', [], $locale))->not->toBe('onboarding.publish_method.title')
        ->and(__('onboarding.publish_method.description', [], $locale))->not->toBe('onboarding.publish_method.description')
        ->and(__('onboarding.publish_method.manual', [], $locale))->not->toBe('onboarding.publish_method.manual')
        ->and(__('onboarding.publish_method.ai', [], $locale))->not->toBe('onboarding.publish_method.ai')
        ->and(__('onboarding.publish_method.mcp', [], $locale))->not->toBe('onboarding.publish_method.mcp')
        ->and(__('onboarding.publish_method.connected', [], $locale))->not->toBe('onboarding.publish_method.connected')
        ->and(__('onboarding.publish_method.connected_description', ['name' => 'Claude'], $locale))->not->toBe('onboarding.publish_method.connected_description')
        ->toContain('Claude')
        ->and(__('onboarding.publish_method.required', [], $locale))->not->toBe('onboarding.publish_method.required')
        ->and(__('onboarding.goals_description', [], $locale))->not->toBe('onboarding.goals_description');
})->with(ContentLanguage::values());

test('goals description asks for a single choice', function () {
    expect(__('onboarding.goals_description', [], 'en'))->not->toContain('everything')
        ->and(__('onboarding.goals_description', [], 'pt-BR'))->not->toContain('tudo');
});

test('connect store requires a publish method', function () {
    completeOnboardingThroughReferral($this->user);
    $workspace = attachCurrentWorkspace($this->user);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);

    $this->mock(StartSubscriptionCheckout::class)->shouldNotReceive('redirect');

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.connect.store'))
        ->assertSessionHasErrors('publish_method');
});

test('connect store requires a connected social account', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();
    completeOnboardingThroughPublishMethod($this->user);
    attachCurrentWorkspace($this->user);

    $this->mock(StartSubscriptionCheckout::class)->shouldNotReceive('redirect');

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.connect.store'))
        ->assertSessionHasErrors('connect');

    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::Connect->value,
    );
    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === CheckoutEvent::Started->value,
    );
});

test('connect store rejects disconnected or expired social accounts', function () {
    completeOnboardingThroughPublishMethod($this->user);
    $workspace = attachCurrentWorkspace($this->user);
    SocialAccount::factory()->linkedin()->disconnected()->create(['workspace_id' => $workspace->id]);
    SocialAccount::factory()->x()->tokenExpired()->create(['workspace_id' => $workspace->id]);

    $this->mock(StartSubscriptionCheckout::class)->shouldNotReceive('redirect');

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.connect.store'))
        ->assertSessionHasErrors('connect');
});

test('connect store ignores social accounts on another workspace', function () {
    completeOnboardingThroughPublishMethod($this->user);
    attachCurrentWorkspace($this->user);

    $otherWorkspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $otherWorkspace->id]);

    $this->mock(StartSubscriptionCheckout::class)->shouldNotReceive('redirect');

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.connect.store'))
        ->assertSessionHasErrors('connect');
});

test('connect store starts Stripe checkout when a social account is connected', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();
    completeOnboardingThroughPublishMethod($this->user);
    $workspace = attachCurrentWorkspace($this->user);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);

    Plan::where('slug', Slug::Workspace)->firstOrFail()->update([
        'stripe_monthly_price_id' => 'price_monthly_test',
    ]);

    $this->mock(StartSubscriptionCheckout::class)
        ->shouldReceive('redirect')
        ->once()
        ->withArgs(fn (Account $account, string $priceId, string $cancelUrl): bool => $account->is($this->user->account)
            && $priceId === 'price_monthly_test'
            && $cancelUrl === route('app.onboarding'))
        ->andReturn(redirect('https://checkout.stripe.test/session'));

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.connect.store'))
        ->assertRedirect('https://checkout.stripe.test/session');

    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => $event->method === 'capture'
        && data_get($event->payload, 'event') === OnboardingEvent::Connect->value
        && data_get($event->payload, 'properties.platforms') === [SocialPlatform::LinkedIn->value]);
});

test('connect store captures checkout.started with the plan name and interval', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();
    completeOnboardingThroughPublishMethod($this->user);
    $workspace = attachCurrentWorkspace($this->user);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);

    $plan = Plan::where('slug', Slug::Workspace)->firstOrFail();
    $plan->update(['stripe_monthly_price_id' => 'price_monthly_test']);

    $this->mock(StartSubscriptionCheckout::class)
        ->shouldReceive('redirect')
        ->once()
        ->andReturn(redirect('https://checkout.stripe.test/session'));

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.connect.store'));

    Bus::assertDispatched(SendEvent::class, fn (SendEvent $event): bool => $event->method === 'capture'
        && data_get($event->payload, 'event') === CheckoutEvent::Started->value
        && data_get($event->payload, 'properties.plan_name') === $plan->name
        && data_get($event->payload, 'properties.interval') === 'monthly');
});

test('connect store does not capture checkout.started when Stripe checkout creation fails', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();
    completeOnboardingThroughPublishMethod($this->user);
    $workspace = attachCurrentWorkspace($this->user);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);

    Plan::where('slug', Slug::Workspace)->firstOrFail()->update([
        'stripe_monthly_price_id' => 'price_monthly_test',
    ]);

    $this->mock(StartSubscriptionCheckout::class)
        ->shouldReceive('redirect')
        ->once()
        ->andThrow(new RuntimeException('Stripe checkout could not be created.'));

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.connect.store'));

    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::Connect->value,
    );
    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === CheckoutEvent::Started->value,
    );
});

test('connect store still redirects to stripe when posthog capture fails', function () {
    Exceptions::fake();
    completeOnboardingThroughPublishMethod($this->user);
    $workspace = attachCurrentWorkspace($this->user);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);

    Plan::where('slug', Slug::Workspace)->firstOrFail()->update([
        'stripe_monthly_price_id' => 'price_monthly_test',
    ]);

    $this->mock(StartSubscriptionCheckout::class)
        ->shouldReceive('redirect')
        ->once()
        ->andReturn(redirect('https://checkout.stripe.test/session'));

    $this->mock(PostHogService::class)
        ->shouldReceive('capture')
        ->andThrow(new RuntimeException('PostHog is down.'));

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.connect.store'))
        ->assertRedirect('https://checkout.stripe.test/session');

    Exceptions::assertReported(RuntimeException::class);
});

test('onboarding steps redirect to calendar for subscribed accounts', function (string $routeName, string $method, array $payload = []) {
    subscribeAccount($this->user->account);

    $this->actingAs($this->user->fresh());

    $response = $method === 'get'
        ? $this->get(route($routeName))
        : $this->post(route($routeName), $payload);

    $response->assertRedirect(route('app.calendar'));
})->with([
    'welcome' => ['app.onboarding', 'get'],
    'persona store' => ['app.onboarding.persona.store', 'post', ['persona' => Persona::Agency->value]],
    'goals store' => ['app.onboarding.goals.store', 'post', ['goals' => [Goal::SaveTime->value]]],
    'referral source store' => ['app.onboarding.referral-source.store', 'post', ['referral_source' => ReferralSource::Google->value]],
    'publish method store' => ['app.onboarding.publish-method.store', 'post', ['publish_method' => PublishMethod::Manual->value]],
    'connect store' => ['app.onboarding.connect.store', 'post'],
]);

test('onboarding redirects generic-trial accounts with app access to calendar', function () {
    config(['trypost.billing.require_card_for_trial' => false]);

    $this->user->account->forceFill([
        'trial_ends_at' => now()->addDays(8),
    ])->save();

    expect($this->user->account->fresh()->hasAppAccess())->toBeTrue()
        ->and($this->user->account->fresh()->subscribed(Account::SUBSCRIPTION_NAME))->toBeFalse();

    $this->actingAs($this->user->fresh())
        ->get(route('app.onboarding'))
        ->assertRedirect(route('app.calendar'));
});

test('onboarding steps redirect to calendar in self hosted mode', function (string $routeName, string $method, array $payload = []) {
    config(['trypost.self_hosted' => true]);

    $this->actingAs($this->user);

    $response = $method === 'get'
        ? $this->get(route($routeName))
        : $this->post(route($routeName), $payload);

    $response->assertRedirect(route('app.calendar'));
})->with([
    'welcome' => ['app.onboarding', 'get'],
    'persona store' => ['app.onboarding.persona.store', 'post', ['persona' => Persona::Agency->value]],
    'goals store' => ['app.onboarding.goals.store', 'post', ['goals' => [Goal::SaveTime->value]]],
    'referral source store' => ['app.onboarding.referral-source.store', 'post', ['referral_source' => ReferralSource::Google->value]],
    'publish method store' => ['app.onboarding.publish-method.store', 'post', ['publish_method' => PublishMethod::Manual->value]],
    'connect store' => ['app.onboarding.connect.store', 'post'],
]);

test('old onboarding icp routes are not registered', function (string $routeName) {
    expect(Route::has($routeName))->toBeFalse();
})->with([
    'store' => 'app.onboarding.store',
    'checkout' => 'app.onboarding.checkout',
]);

test('legacy welcome urls redirect to onboarding', function () {
    $this->actingAs($this->user)
        ->get(route('app.welcome'))
        ->assertRedirect(route('app.onboarding'));
});

test('members cannot start Stripe checkout from onboarding', function (bool $withWorkspace) {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    completeOnboardingThroughReferral($member);

    if ($withWorkspace) {
        attachCurrentWorkspace($member);
    }

    $this->mock(StartSubscriptionCheckout::class)->shouldNotReceive('redirect');

    $this->actingAs($member->fresh())
        ->get(route('app.onboarding'))
        ->assertRedirect(route('app.onboarding.subscription-required'));

    $this->actingAs($member->fresh())
        ->post(route('app.onboarding.connect.store'))
        ->assertRedirect(route('app.onboarding.subscription-required'));
})->with([
    'without workspace' => [false],
    'with empty workspace' => [true],
]);

test('subscribed owners skip connect validation and go to calendar', function () {
    subscribeAccount($this->user->account);
    completeOnboardingThroughReferral($this->user);
    attachCurrentWorkspace($this->user);

    $this->mock(StartSubscriptionCheckout::class)->shouldNotReceive('redirect');

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.connect.store'))
        ->assertRedirect(route('app.calendar'));
});

test('members without app access are held on the subscription required screen', function (string $routeName, string $method, array $payload = []) {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);

    $this->actingAs($member->fresh());

    $response = $method === 'get'
        ? $this->get(route($routeName))
        : $this->post(route($routeName), $payload);

    $response->assertRedirect(route('app.onboarding.subscription-required'));
})->with([
    'welcome' => ['app.onboarding', 'get'],
    'persona store' => ['app.onboarding.persona.store', 'post', ['persona' => Persona::Agency->value]],
    'goals store' => ['app.onboarding.goals.store', 'post', ['goals' => [Goal::SaveTime->value]]],
    'referral source store' => ['app.onboarding.referral-source.store', 'post', ['referral_source' => ReferralSource::Google->value]],
    'publish method store' => ['app.onboarding.publish-method.store', 'post', ['publish_method' => PublishMethod::Manual->value]],
    'connect store' => ['app.onboarding.connect.store', 'post'],
]);

test('subscription required screen renders for members without app access', function () {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);

    $this->actingAs($member->fresh())
        ->get(route('app.onboarding.subscription-required'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('onboarding/SubscriptionRequired', false)
            ->where('ownerName', $this->user->name)
        );
});

test('subscription required screen sends owners back to the onboarding flow', function () {
    $this->actingAs($this->user)
        ->get(route('app.onboarding.subscription-required'))
        ->assertRedirect(route('app.onboarding'));
});

test('subscription required screen sends subscribed users to the calendar', function () {
    subscribeAccount($this->user->account);

    $this->actingAs($this->user->fresh())
        ->get(route('app.onboarding.subscription-required'))
        ->assertRedirect(route('app.calendar'));
});

test('subscription required screen sends members with app access to the calendar', function () {
    ['owner' => $owner, 'member' => $member] = strandedMemberOnSharedAccount();
    subscribeAccount($owner->account);

    $this->actingAs($member)
        ->get(route('app.onboarding.subscription-required'))
        ->assertRedirect(route('app.calendar'));
});

test('subscription required screen redirects to calendar in self hosted mode', function () {
    config(['trypost.self_hosted' => true]);

    $member = User::factory()->create(['account_id' => $this->user->account_id]);

    $this->actingAs($member->fresh())
        ->get(route('app.onboarding.subscription-required'))
        ->assertRedirect(route('app.calendar'));
});

test('onboarding sends members with app access to the calendar', function () {
    ['owner' => $owner, 'member' => $member] = strandedMemberOnSharedAccount();
    subscribeAccount($owner->account);

    $this->actingAs($member)
        ->get(route('app.onboarding'))
        ->assertRedirect(route('app.calendar'));
});

test('connect store fails loudly when the monthly price is not configured', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();
    completeOnboardingThroughPublishMethod($this->user);
    $workspace = attachCurrentWorkspace($this->user);
    SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    Plan::where('slug', Slug::Workspace)->update(['stripe_monthly_price_id' => null]);

    $this->mock(StartSubscriptionCheckout::class)->shouldNotReceive('redirect');

    $this->actingAs($this->user->fresh())
        ->post(route('app.onboarding.connect.store'))
        ->assertServerError();

    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === OnboardingEvent::Connect->value,
    );
    Bus::assertNotDispatched(
        SendEvent::class,
        fn (SendEvent $event): bool => data_get($event->payload, 'event') === CheckoutEvent::Started->value,
    );
});

function completeOnboardingThroughReferral(User $user): void
{
    $user->update([
        'persona' => Persona::Agency->value,
        'goals' => [Goal::SaveTime->value],
        'referral_source' => ReferralSource::ProductHunt->value,
    ]);
}

function completeOnboardingThroughPublishMethod(User $user): void
{
    completeOnboardingThroughReferral($user);
    $user->update(['publish_method' => PublishMethod::Manual]);
}

function attachCurrentWorkspace(User $user): Workspace
{
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    return $workspace;
}
