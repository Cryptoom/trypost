<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\Account;
use App\Models\Plan;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Stripe\ApiRequestor;
use Stripe\HttpClient\CurlClient;

beforeEach(function () {
    config(['trypost.billing.require_card_for_trial' => true]);

    $this->account = Account::factory()->create();
    $this->user = User::factory()->create([
        'account_id' => $this->account->id,
    ]);
    $this->account->update(['owner_id' => $this->user->id]);
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

afterEach(function () {
    // The Stripe HTTP fake is a process-global static — never leak it.
    ApiRequestor::setHttpClient(new CurlClient);
});

// Subscribe tests
test('subscribe requires authentication', function () {
    $response = $this->get(route('app.subscribe'));

    $response->assertRedirect(route('login'));
});

test('subscribe redirects to welcome', function () {
    config(['trypost.self_hosted' => false]);

    $response = $this->actingAs($this->user)->get(route('app.subscribe'));

    $response->assertRedirect(route('app.welcome.persona'));
});

test('swapToYearly redirects to calendar in self hosted mode', function () {
    config(['trypost.self_hosted' => true]);

    $response = $this->actingAs($this->user)
        ->post(route('app.billing.swap-to-yearly'));

    $response->assertRedirect(route('app.calendar'));
});

test('portal redirects to calendar in self hosted mode', function () {
    config(['trypost.self_hosted' => true]);

    $response = $this->actingAs($this->user)->get(route('app.billing.portal'));

    $response->assertRedirect(route('app.calendar'));
});

// Index tests
test('billing index requires authentication', function () {
    $response = $this->get(route('app.billing.index'));

    $response->assertRedirect(route('login'));
});

test('billing index shows billing dashboard', function () {
    config(['trypost.self_hosted' => false]);

    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    $response = $this->actingAs($this->user)->get(route('app.billing.index'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/account/Billing', false)
        ->has('hasSubscription')
        ->has('plan')
        ->has('workspaceCount')
    );
});

test('billing index exposes onTrial=true and trialEndsAt for subscription-trial account', function () {
    config(['trypost.self_hosted' => false]);

    $subscriptionEndsAt = now()->addDays(5)->startOfSecond();
    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'trialing',
        'stripe_price' => 'price_123',
        'trial_ends_at' => $subscriptionEndsAt,
    ]);

    $response = $this->actingAs($this->user->fresh())->get(route('app.billing.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('hasSubscription', true)
        ->where('onTrial', true)
        ->where('trialEndsAt', $subscriptionEndsAt->toIso8601ZuluString('microsecond'))
    );
});

test('billing index exposes onTrial=false and trialEndsAt=null for paying subscribed user', function () {
    config(['trypost.self_hosted' => false]);

    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    $response = $this->actingAs($this->user->fresh())->get(route('app.billing.index'));

    $response->assertInertia(fn ($page) => $page
        ->where('hasSubscription', true)
        ->where('onTrial', false)
        ->where('trialEndsAt', null)
    );
});

test('billing index redirects to calendar in self hosted mode', function () {
    config(['trypost.self_hosted' => true]);

    $response = $this->actingAs($this->user)->get(route('app.billing.index'));

    $response->assertRedirect(route('app.calendar'));
});

// Processing tests
test('billing processing requires authentication', function () {
    $response = $this->get(route('app.billing.processing'));

    $response->assertRedirect(route('login'));
});

test('billing processing shows processing page', function () {
    config(['trypost.self_hosted' => false]);

    $response = $this->actingAs($this->user)->get(route('app.billing.processing'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('billing/Processing', false)
        ->has('subscriptionActive')
        ->where('redirectToOnboarding', true)
        ->where('conversion', null)
        ->where('conversionResolved', true)
        ->missing('fromCheckout')
    );
});

test('billing processing skips onboarding when already completed', function () {
    config(['trypost.self_hosted' => false]);
    $this->user->account->forceFill(['onboarding_completed_at' => now()])->save();

    $this->actingAs($this->user->fresh())
        ->get(route('app.billing.processing'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('redirectToOnboarding', false));
});

test('billing processing skips onboarding when dismissed', function () {
    config(['trypost.self_hosted' => false]);
    $this->user->account->forceFill(['onboarding_dismissed_at' => now()])->save();

    $this->actingAs($this->user->fresh())
        ->get(route('app.billing.processing'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('redirectToOnboarding', false));
});

test('billing processing completes already satisfied onboarding before redirecting', function () {
    config(['trypost.self_hosted' => false]);
    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_test',
        'quantity' => 1,
    ]);
    mcpAccessToken($this->user, mcpOauthClient());
    SocialAccount::withoutEvents(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
    ]));
    Post::withoutEvents(fn () => Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]));

    $this->actingAs($this->user->fresh())
        ->get(route('app.billing.processing'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('subscriptionActive', true)
            ->where('redirectToOnboarding', false)
        );

    expect($this->account->fresh()->onboarding_completed_at)->not->toBeNull();
});

test('billing processing delivers conversion once then suppresses it', function () {
    config(['trypost.self_hosted' => false]);

    $sessionId = 'cs_test_'.fake()->uuid();
    $this->account->forceFill(['stripe_id' => 'cus_test_123'])->save();
    $stripe = fakeStripeHttp([[
        'body' => [
            'id' => $sessionId,
            'customer' => 'cus_test_123',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => 1000,
            'currency' => 'usd',
        ],
        'status' => 200,
    ]]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.billing.processing', ['session_id' => $sessionId]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('conversion.value', 10)
            ->where('conversion.transaction_id', $sessionId)
            ->where('conversionResolved', true)
        );

    $this->actingAs($this->user->fresh())
        ->get(route('app.billing.processing', ['session_id' => $sessionId]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('conversion', null)
            ->where('conversionResolved', true)
        );

    expect($stripe->calls)->toBe(1);
});

test('only the account owner receives checkout purchase conversion', function () {
    config(['trypost.self_hosted' => false]);

    $sessionId = 'cs_test_'.fake()->uuid();
    $this->account->forceFill(['stripe_id' => 'cus_test_123'])->save();
    fakeStripeHttp([[
        'body' => [
            'id' => $sessionId,
            'customer' => 'cus_test_123',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => 1000,
            'currency' => 'usd',
        ],
        'status' => 200,
    ]]);

    $member = User::factory()->create([
        'account_id' => $this->account->id,
        'current_workspace_id' => $this->workspace->id,
    ]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);

    $this->actingAs($member->fresh())
        ->get(route('app.billing.processing', ['session_id' => $sessionId]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('conversion', null)
            ->where('conversionResolved', true)
        );

    $this->actingAs($this->user->fresh())
        ->get(route('app.billing.processing', ['session_id' => $sessionId]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('conversion.transaction_id', $sessionId)
        );
});

test('billing processing does not convert a session owned by another customer', function () {
    config(['trypost.self_hosted' => false]);

    $sessionId = 'cs_test_'.fake()->uuid();
    $this->account->forceFill(['stripe_id' => 'cus_test_123'])->save();
    $stripe = fakeStripeHttp([[
        'body' => [
            'id' => $sessionId,
            'customer' => 'cus_someone_else',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => 1000,
            'currency' => 'usd',
        ],
        'status' => 200,
    ]]);

    // Customer mismatch yields no conversion — purchase tracking gates on conversion.
    $this->actingAs($this->user->fresh())
        ->get(route('app.billing.processing', ['session_id' => $sessionId]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('conversion', null)
            ->where('conversionResolved', true)
        );

    // The mismatch is consumed once — the poll loop must not re-hit Stripe.
    $this->actingAs($this->user->fresh())
        ->get(route('app.billing.processing', ['session_id' => $sessionId]))
        ->assertOk();

    expect($stripe->calls)->toBe(1);
});

test('billing processing consumes an unknown session id without retrying stripe', function () {
    config(['trypost.self_hosted' => false]);

    $sessionId = 'cs_test_'.fake()->uuid();
    $this->account->forceFill(['stripe_id' => 'cus_test_123'])->save();
    $stripe = fakeStripeHttp([[
        'body' => [
            'error' => [
                'type' => 'invalid_request_error',
                'code' => 'resource_missing',
                'message' => 'No such checkout.session',
            ],
        ],
        'status' => 404,
    ]]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.billing.processing', ['session_id' => $sessionId]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('conversion', null)
            ->where('conversionResolved', true)
        );

    $this->actingAs($this->user->fresh())
        ->get(route('app.billing.processing', ['session_id' => $sessionId]))
        ->assertOk();

    expect($stripe->calls)->toBe(1);
});

test('billing processing retries an open checkout session until it completes', function () {
    config(['trypost.self_hosted' => false]);

    $sessionId = 'cs_test_'.fake()->uuid();
    $this->account->forceFill(['stripe_id' => 'cus_test_123'])->save();
    $stripe = fakeStripeHttp([
        [
            'body' => [
                'id' => $sessionId,
                'customer' => 'cus_test_123',
                'status' => 'open',
                'amount_total' => 1000,
                'currency' => 'usd',
            ],
            'status' => 200,
        ],
        [
            'body' => [
                'id' => $sessionId,
                'customer' => 'cus_test_123',
                'status' => 'complete',
                'payment_status' => 'paid',
                'amount_total' => 1000,
                'currency' => 'usd',
            ],
            'status' => 200,
        ],
    ]);

    $response = $this->actingAs($this->user->fresh())
        ->get(route('app.billing.processing', ['session_id' => $sessionId]))
        ->assertOk();

    // Unresolved while open — Processing.vue must keep polling (not finish).
    $response->assertInertia(fn ($page) => $page
        ->where('conversion', null)
        ->where('conversionResolved', false)
    );

    // Matches Processing.vue usePoll only list.
    $response->assertInertia(fn ($page) => $page
        ->reloadOnly(['subscriptionActive', 'auth', 'conversion', 'conversionResolved'], fn ($reload) => $reload
            ->where('conversion.value', 10)
            ->where('conversion.transaction_id', $sessionId)
            ->where('conversionResolved', true)
        )
    );

    expect($stripe->calls)->toBe(2);
});

test('billing processing retries purchase verification after a transient stripe failure on partial poll', function () {
    config(['trypost.self_hosted' => false]);

    $sessionId = 'cs_test_'.fake()->uuid();
    $this->account->forceFill(['stripe_id' => 'cus_test_123'])->save();
    $stripe = fakeStripeHttp([
        [
            'body' => ['error' => ['type' => 'api_error', 'message' => 'boom']],
            'status' => 500,
        ],
        [
            'body' => [
                'id' => $sessionId,
                'customer' => 'cus_test_123',
                'status' => 'complete',
                'payment_status' => 'paid',
                'amount_total' => 1000,
                'currency' => 'usd',
            ],
            'status' => 200,
        ],
    ]);

    $response = $this->actingAs($this->user->fresh())
        ->get(route('app.billing.processing', ['session_id' => $sessionId]))
        ->assertOk();

    $response->assertInertia(fn ($page) => $page
        ->where('conversion', null)
        ->where('conversionResolved', false)
    );

    // Partial poll (same contract as Processing.vue) must still receive conversion.
    $response->assertInertia(fn ($page) => $page
        ->reloadOnly(['subscriptionActive', 'auth', 'conversion', 'conversionResolved'], fn ($reload) => $reload
            ->where('conversion.value', 10)
            ->where('conversion.transaction_id', $sessionId)
            ->where('conversionResolved', true)
        )
    );

    expect($stripe->calls)->toBe(2);
});

test('billing processing consumes an expired checkout session without conversion', function () {
    config(['trypost.self_hosted' => false]);

    $sessionId = 'cs_test_'.fake()->uuid();
    $this->account->forceFill(['stripe_id' => 'cus_test_123'])->save();
    $stripe = fakeStripeHttp([[
        'body' => [
            'id' => $sessionId,
            'customer' => 'cus_test_123',
            'status' => 'expired',
            'amount_total' => 1000,
            'currency' => 'usd',
        ],
        'status' => 200,
    ]]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.billing.processing', ['session_id' => $sessionId]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('conversion', null)
            ->where('conversionResolved', true)
        );

    $this->actingAs($this->user->fresh())
        ->get(route('app.billing.processing', ['session_id' => $sessionId]))
        ->assertOk();

    expect($stripe->calls)->toBe(1);
});

test('billing processing resolves without conversion when complete session payload is unusable', function () {
    config(['trypost.self_hosted' => false]);

    $sessionId = 'cs_test_'.fake()->uuid();
    $this->account->forceFill(['stripe_id' => 'cus_test_123'])->save();
    $stripe = fakeStripeHttp([[
        'body' => [
            'id' => $sessionId,
            'customer' => 'cus_test_123',
            'status' => 'complete',
            'payment_status' => 'paid',
            // Missing amount_total — CheckoutConversionData returns null.
            'currency' => 'usd',
        ],
        'status' => 200,
    ]]);

    $this->actingAs($this->user->fresh())
        ->get(route('app.billing.processing', ['session_id' => $sessionId]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('conversion', null)
            ->where('conversionResolved', true)
        );

    // Consumed — do not keep hitting Stripe on polls.
    $this->actingAs($this->user->fresh())
        ->get(route('app.billing.processing', ['session_id' => $sessionId]))
        ->assertOk();

    expect($stripe->calls)->toBe(1);
});

test('billing processing exposes null conversion when session_id query param is missing', function () {
    config(['trypost.self_hosted' => false]);

    $response = $this->actingAs($this->user)
        ->get(route('app.billing.processing', ['session_id' => '']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('conversion', null)
        ->where('conversionResolved', true)
    );
});

test('billing processing exposes null conversion when account has no stripe_id', function () {
    config(['trypost.self_hosted' => false]);

    expect($this->account->stripe_id)->toBeNull();

    $response = $this->actingAs($this->user)
        ->get(route('app.billing.processing', ['session_id' => 'cs_test_123']));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('conversion', null)
        ->where('conversionResolved', true)
    );
});

test('shared auth.plan exposes name slug and interval via AuthPlanResource', function () {
    config(['trypost.self_hosted' => false]);

    $plan = Plan::where('slug', 'workspace')->firstOrFail();
    $this->account->update(['plan_id' => $plan->id]);

    $response = $this->actingAs($this->user->fresh())->get(route('app.billing.processing'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('auth.plan.name', 'Workspace')
        ->where('auth.plan.slug', 'workspace')
        ->where('auth.plan.interval', 'monthly')
    );
});

test('billing processing redirects to calendar in self hosted mode', function () {
    config(['trypost.self_hosted' => true]);

    $response = $this->actingAs($this->user)->get(route('app.billing.processing'));

    $response->assertRedirect(route('app.calendar'));
});

test('billing processing does not let another account burn the buyers checkout session', function () {
    config(['trypost.self_hosted' => false]);

    $sessionId = 'cs_test_'.fake()->uuid();
    $this->account->forceFill(['stripe_id' => 'cus_test_123'])->save();
    fakeStripeHttp([[
        'body' => [
            'id' => $sessionId,
            'customer' => 'cus_test_123',
            'status' => 'complete',
            'payment_status' => 'paid',
            'amount_total' => 1000,
            'currency' => 'usd',
        ],
        'status' => 200,
    ]]);

    // Another account presenting the buyer's session_id gets nothing — and,
    // without a stripe_id of its own, never even calls Stripe.
    $otherUser = User::factory()->create();

    $this->actingAs($otherUser)
        ->get(route('app.billing.processing', ['session_id' => $sessionId]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('conversion', null));

    // The real buyer's first visit still receives the conversion payload.
    $this->actingAs($this->user->fresh())
        ->get(route('app.billing.processing', ['session_id' => $sessionId]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('conversion.value', 10)
            ->where('conversion.transaction_id', $sessionId)
        );
});

test('billing processing does not send members to onboarding', function () {
    config(['trypost.self_hosted' => false]);

    ['member' => $member] = strandedMemberOnSharedAccount();

    $this->actingAs($member)
        ->get(route('app.billing.processing'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('redirectToOnboarding', false));
});

// Checkout tests
// Portal tests
test('portal requires authentication', function () {
    $response = $this->get(route('app.billing.portal'));

    $response->assertRedirect(route('login'));
});

// Authorization tests
test('non-owner admin cannot access billing index', function () {
    config(['trypost.self_hosted' => false]);

    $admin = User::factory()->create([
        'account_id' => $this->account->id,
    ]);
    $this->workspace->members()->attach($admin->id, ['role' => Role::Admin->value]);
    $admin->update(['current_workspace_id' => $this->workspace->id]);

    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    $this->actingAs($admin)->get(route('app.billing.index'))->assertForbidden();
});

test('member cannot access billing index', function () {
    config(['trypost.self_hosted' => false]);

    $member = User::factory()->create([
        'account_id' => $this->account->id,
    ]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_123',
    ]);

    $this->actingAs($member)->get(route('app.billing.index'))->assertForbidden();
});

// Swap-to-yearly tests
test('swapToYearly forbids a non-owner', function () {
    config(['trypost.self_hosted' => false]);

    $member = User::factory()->create(['account_id' => $this->account->id]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_monthly',
    ]);

    $this->actingAs($member)
        ->post(route('app.billing.swap-to-yearly'))
        ->assertForbidden();
});

test('swapToYearly is a no-op when already on annual billing', function () {
    config(['trypost.self_hosted' => false]);

    $plan = Plan::where('slug', 'workspace')->first();
    $plan->update([
        'stripe_monthly_price_id' => 'price_monthly',
        'stripe_yearly_price_id' => 'price_yearly',
    ]);
    $this->account->update(['plan_id' => $plan->id]);

    $this->account->subscriptions()->create([
        'type' => Account::SUBSCRIPTION_NAME,
        'stripe_id' => 'sub_test_'.fake()->uuid(),
        'stripe_status' => 'active',
        'stripe_price' => 'price_yearly',
    ]);
    $this->user->unsetRelation('account');

    $this->actingAs($this->user)
        ->post(route('app.billing.swap-to-yearly'))
        ->assertRedirect(route('app.billing.index'));
});

test('swapToYearly requires authentication', function () {
    $response = $this->post(route('app.billing.swap-to-yearly'));

    $response->assertRedirect(route('login'));
});
