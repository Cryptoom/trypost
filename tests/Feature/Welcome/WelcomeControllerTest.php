<?php

declare(strict_types=1);

use App\Actions\Billing\StartSubscriptionCheckout;
use App\Enums\Plan\Slug;
use App\Enums\User\Goal;
use App\Enums\User\Persona;
use App\Enums\User\ReferralSource;
use App\Jobs\PostHog\SendEvent;
use App\Models\Account;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);
    $this->user = User::factory()->create();
});

function subscribeWelcomeAccount(Account $account): void
{
    $account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);
}

test('welcome redirects to the persona step', function () {
    $this->actingAs($this->user)
        ->get(route('app.welcome'))
        ->assertRedirect(route('app.welcome.persona'));
});

test('persona renders for an unsubscribed account', function () {
    $this->actingAs($this->user)
        ->get(route('app.welcome.persona'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('welcome/Persona', false)
            ->has('personas', count(Persona::cases()))
        );
});

test('persona requires a valid selection', function (array $payload) {
    $this->actingAs($this->user)
        ->post(route('app.welcome.persona.store'), $payload)
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
        ->post(route('app.welcome.persona.store'), ['persona' => Persona::Agency->value])
        ->assertRedirect(route('app.welcome.goals'));

    expect($this->user->fresh()->persona)->toBe(Persona::Agency);
    Bus::assertDispatched(SendEvent::class);
});

test('goals redirects to persona until a persona is selected', function () {
    $this->actingAs($this->user)
        ->get(route('app.welcome.goals'))
        ->assertRedirect(route('app.welcome.persona'));
});

test('goals renders after a persona is selected', function () {
    $this->user->update(['persona' => Persona::Agency->value]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.goals'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('welcome/Goals', false)
            ->has('goals', count(Goal::cases()))
        );
});

test('goals requires at least one valid goal', function (array $goals, string $error) {
    $this->user->update(['persona' => Persona::Agency->value]);

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.goals.store'), ['goals' => $goals])
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
        ->post(route('app.welcome.goals.store'), ['goals' => $goals])
        ->assertRedirect(route('app.welcome.referral-source'));

    expect($this->user->fresh()->goals)->toBe($goals);
    Bus::assertDispatched(SendEvent::class);
});

test('referral source redirects through incomplete prior steps', function (array $attributes, string $routeName) {
    $this->user->update($attributes);

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.referral-source'))
        ->assertRedirect(route($routeName));
})->with([
    'missing persona' => [[], 'app.welcome.persona'],
    'missing goals' => [['persona' => Persona::Agency->value], 'app.welcome.goals'],
]);

test('referral source renders after prior steps are complete', function () {
    $this->user->update([
        'persona' => Persona::Agency->value,
        'goals' => [Goal::SaveTime->value],
    ]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.referral-source'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('welcome/ReferralSource', false)
            ->has('sources', count(ReferralSource::cases()))
        );
});

test('referral source requires a valid selection', function (array $payload) {
    $this->user->update([
        'persona' => Persona::Agency->value,
        'goals' => [Goal::SaveTime->value],
    ]);

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.referral-source.store'), $payload)
        ->assertSessionHasErrors('referral_source');

    expect($this->user->fresh()->referral_source)->toBeNull();
})->with([
    'missing' => [[]],
    'invalid' => [['referral_source' => 'not-a-source']],
]);

test('referral source store saves the source mirrors it to PostHog and advances to subscribe', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);
    Bus::fake();
    $this->user->update([
        'persona' => Persona::Agency->value,
        'goals' => [Goal::SaveTime->value],
    ]);

    $this->actingAs($this->user->fresh())
        ->post(route('app.welcome.referral-source.store'), [
            'referral_source' => ReferralSource::ProductHunt->value,
        ])
        ->assertRedirect(route('app.welcome.subscribe'));

    expect($this->user->fresh()->referral_source)->toBe(ReferralSource::ProductHunt);
    Bus::assertDispatched(SendEvent::class);
});

test('subscribe redirects through incomplete welcome steps', function (array $attributes, string $routeName) {
    $this->user->update($attributes);

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.subscribe'))
        ->assertRedirect(route($routeName));
})->with([
    'missing persona' => [[], 'app.welcome.persona'],
    'missing goals' => [['persona' => Persona::Agency->value], 'app.welcome.goals'],
    'missing referral source' => [[
        'persona' => Persona::Agency->value,
        'goals' => [Goal::SaveTime->value],
    ], 'app.welcome.referral-source'],
]);

test('subscribe renders plan props without social account props', function () {
    $this->user->update([
        'persona' => Persona::Agency->value,
        'goals' => [Goal::SaveTime->value],
        'referral_source' => ReferralSource::Google->value,
    ]);
    $plan = Plan::where('slug', Slug::Workspace)->firstOrFail();

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.subscribe'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('welcome/Subscribe', false)
            ->where('plan.name', $plan->name)
            ->where('plan.interval', 'monthly')
            ->missing('platforms')
            ->missing('accounts')
        );
});

test('checkout starts without a connected social account and uses subscribe as its cancel url', function () {
    Plan::where('slug', Slug::Workspace)->firstOrFail()->update([
        'stripe_monthly_price_id' => 'price_monthly_test',
    ]);

    $this->mock(StartSubscriptionCheckout::class)
        ->shouldReceive('redirect')
        ->once()
        ->withArgs(fn (Account $account, string $priceId, string $cancelUrl): bool => $account->is($this->user->account)
            && $priceId === 'price_monthly_test'
            && $cancelUrl === route('app.welcome.subscribe'))
        ->andReturn(redirect('https://checkout.stripe.test/session'));

    $this->actingAs($this->user)
        ->post(route('app.welcome.checkout'))
        ->assertRedirect('https://checkout.stripe.test/session');
});

test('welcome steps redirect to activation for subscribed accounts with residual onboarding', function (string $routeName, string $method, array $payload = []) {
    subscribeWelcomeAccount($this->user->account);

    $this->actingAs($this->user->fresh());

    $response = $method === 'get'
        ? $this->get(route($routeName))
        : $this->post(route($routeName), $payload);

    $response->assertRedirect(route('app.onboarding'));
})->with([
    'persona' => ['app.welcome.persona', 'get'],
    'persona store' => ['app.welcome.persona.store', 'post', ['persona' => Persona::Agency->value]],
    'goals' => ['app.welcome.goals', 'get'],
    'goals store' => ['app.welcome.goals.store', 'post', ['goals' => [Goal::SaveTime->value]]],
    'referral source' => ['app.welcome.referral-source', 'get'],
    'referral source store' => ['app.welcome.referral-source.store', 'post', ['referral_source' => ReferralSource::Google->value]],
    'subscribe' => ['app.welcome.subscribe', 'get'],
    'checkout' => ['app.welcome.checkout', 'post'],
]);

test('welcome redirects subscribed accounts without residual onboarding to calendar', function (array $attributes) {
    subscribeWelcomeAccount($this->user->account);
    $this->user->account->update($attributes);

    $this->actingAs($this->user->fresh())
        ->get(route('app.welcome.persona'))
        ->assertRedirect(route('app.calendar'));
})->with([
    'dismissed' => [['onboarding_dismissed_at' => now()]],
    'completed' => [['onboarding_completed_at' => now()]],
]);

test('welcome steps redirect to calendar in self hosted mode', function (string $routeName, string $method, array $payload = []) {
    config(['trypost.self_hosted' => true]);

    $this->actingAs($this->user);

    $response = $method === 'get'
        ? $this->get(route($routeName))
        : $this->post(route($routeName), $payload);

    $response->assertRedirect(route('app.calendar'));
})->with([
    'persona' => ['app.welcome.persona', 'get'],
    'persona store' => ['app.welcome.persona.store', 'post', ['persona' => Persona::Agency->value]],
    'goals' => ['app.welcome.goals', 'get'],
    'goals store' => ['app.welcome.goals.store', 'post', ['goals' => [Goal::SaveTime->value]]],
    'referral source' => ['app.welcome.referral-source', 'get'],
    'referral source store' => ['app.welcome.referral-source.store', 'post', ['referral_source' => ReferralSource::Google->value]],
    'subscribe' => ['app.welcome.subscribe', 'get'],
    'checkout' => ['app.welcome.checkout', 'post'],
]);

test('legacy welcome URLs redirect to their replacement routes', function (string $legacyRoute, string $routeName) {
    $this->actingAs($this->user)
        ->get(route($legacyRoute))
        ->assertRedirect(route($routeName));
})->with([
    'goals' => ['app.legacy-onboarding.goals', 'app.welcome.goals'],
    'referral source' => ['app.legacy-onboarding.referral-source', 'app.welcome.referral-source'],
    'connect' => ['app.legacy-onboarding.connect', 'app.welcome.subscribe'],
]);
