<?php

declare(strict_types=1);

use App\Enums\Plan\Slug;
use App\Models\Plan;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;

/**
 * Poll browser-side until the data-testid element exists — and fail loudly
 * when it never shows up.
 */
function waitForDusk(mixed $page, string $selector): void
{
    $found = $page->script(<<<JS
        (async () => {
            const sel = '[data-testid="{$selector}"]';
            for (let i = 0; i < 100; i++) {
                if (document.querySelector(sel)) return true;
                await new Promise((r) => setTimeout(r, 50));
            }
            return false;
        })();
    JS);

    expect($found)->toBeTrue("Timed out waiting for [data-testid=\"{$selector}\"] to appear.");
}

/**
 * Poll browser-side until the data-testid element is gone — and fail loudly
 * when it never disappears.
 */
function waitForDuskGone(mixed $page, string $selector): void
{
    $gone = $page->script(<<<JS
        (async () => {
            const sel = '[data-testid="{$selector}"]';
            for (let i = 0; i < 100; i++) {
                if (! document.querySelector(sel)) return true;
                await new Promise((r) => setTimeout(r, 50));
            }
            return false;
        })();
    JS);

    expect($gone)->toBeTrue("Timed out waiting for [data-testid=\"{$selector}\"] to disappear.");
}

/**
 * Poll browser-side until the location path matches, then let the new page
 * mount before asserting.
 */
function waitForPath(mixed $page, string $path): void
{
    $reached = $page->script(<<<JS
        (async () => {
            for (let i = 0; i < 100; i++) {
                if (window.location.pathname === '{$path}') break;
                await new Promise((r) => setTimeout(r, 50));
            }
            await new Promise((r) => setTimeout(r, 500));
            return window.location.pathname === '{$path}';
        })();
    JS);

    expect($reached)->toBeTrue("Timed out waiting for path {$path}.");
}

test('owner walks the welcome steps up to the checkout CTA', function () {
    config(['trypost.self_hosted' => false]);

    $user = User::factory()->create();

    // fresh(): the in-process server keeps the guard user across requests, and
    // the factory instance lacks the nullable ICP columns (strict models throw).
    test()->actingAs($user->fresh());

    $page = visit(route('app.welcome.persona'));

    waitForDusk($page, 'welcome-persona-creator');
    $page->assertVisible('@welcome-persona-creator')
        ->click('@welcome-persona-creator')
        ->click('@welcome-persona-continue');

    waitForDusk($page, 'welcome-goal-save_time');
    $page->assertVisible('@welcome-goal-save_time')
        ->click('@welcome-goal-save_time')
        ->click('@welcome-goals-continue');

    // The CTA stays disabled until a source is picked. Clicking through to
    // Stripe is covered by feature tests — the browser must not hit the
    // Stripe API.
    waitForDusk($page, 'welcome-start-checkout');
    $page->click('@welcome-source-google');

    $checkoutEnabled = $page->script('(() => { const b = document.querySelector(\'[data-testid="welcome-start-checkout"]\'); return b !== null && ! b.disabled; })()');

    expect($checkoutEnabled)->toBeTrue();

    expect($user->fresh()->persona?->value)->toBe('creator')
        ->and($user->fresh()->goals)->toBe(['save_time']);
});

test('owner skips the mcp step and completes onboarding once the required steps are done', function () {
    config(['trypost.self_hosted' => false]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    test()->actingAs($user->fresh());

    $page = visit(route('app.onboarding'));

    waitForDusk($page, 'onboarding-mcp-skip');
    $page->assertVisible('@onboarding-mcp')
        ->assertVisible('@onboarding-social')
        ->assertVisible('@onboarding-first-post')
        ->click('@onboarding-mcp-skip');

    // Skipping the optional step keeps the checklist open — the required steps
    // are still pending.
    waitForDuskGone($page, 'onboarding-mcp-skip');
    expect($user->account->fresh()->onboarding_skipped_steps)->toBe(['mcp'])
        ->and($user->account->fresh()->onboarding_completed_at)->toBeNull();

    // Finish the required steps server-side; the poll picks them up.
    SocialAccount::factory()->create(['workspace_id' => $workspace->id]);
    Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
    ]);

    waitForDusk($page, 'onboarding-continue');
    $page->click('@onboarding-continue');

    waitForPath($page, parse_url(route('app.calendar'), PHP_URL_PATH));
    $page->assertMissing('@sidebar-onboarding');

    expect($user->account->fresh()->onboarding_completed_at)->not->toBeNull();
});

test('member without app access lands on the subscription required screen', function () {
    config(['trypost.self_hosted' => false]);

    ['member' => $member] = strandedMemberOnSharedAccount();

    test()->actingAs($member);

    $page = visit(route('app.calendar'));

    waitForDusk($page, 'welcome-subscription-required');
    $page->assertVisible('@welcome-subscription-required');
});

test('purchase acknowledgement is not sent immediately after analytics is queued', function () {
    config(['trypost.self_hosted' => false]);

    $user = User::factory()->create();
    $account = $user->account;
    $account->forceFill([
        'stripe_id' => 'cus_test_123',
        'onboarding_completed_at' => now(),
    ])->save();
    $plan = Plan::where('slug', Slug::Workspace)->firstOrFail();
    $plan->update([
        'stripe_monthly_price_id' => 'price_123',
    ]);
    $account->update(['plan_id' => $plan->id]);
    subscribeAccount($account);

    $sessionId = 'cs_test_'.fake()->uuid();
    Cache::put("checkout_verified:{$account->id}:{$sessionId}", [
        'kind' => 'purchase',
        'payload' => [
            'value' => 10,
            'currency' => 'USD',
            'transaction_id' => $sessionId,
        ],
        'verified_at' => now()->toIso8601String(),
    ], now()->addDay());

    test()->actingAs($user->fresh());

    $page = visit(route('app.billing.processing', ['session_id' => $sessionId]));
    $page->assertPathIs(parse_url(route('app.billing.processing'), PHP_URL_PATH));
    $initialProps = $page->script('window.history.state.page.props');

    expect(data_get($initialProps, 'subscriptionActive'))->toBeTrue()
        ->and(data_get($initialProps, 'conversionResolved'))->toBeTrue()
        ->and(data_get($initialProps, 'conversion.transaction_id'))->toBe($sessionId)
        ->and(data_get($initialProps, 'auth.plan'))->not->toBeNull();

    $page->script('(async () => { await new Promise((resolve) => setTimeout(resolve, 500)); })()');
    $page->assertNoJavaScriptErrors();

    $purchaseWasQueued = $page->script(
        'Array.isArray(window.dataLayer) && window.dataLayer.some((entry) => entry.event === "purchase")',
    );

    expect($purchaseWasQueued)->toBeTrue()
        ->and(Cache::has("checkout_tracked:{$account->id}:{$sessionId}"))->toBeFalse();
});

test('owner sees the residual in the mobile sidebar and it links to onboarding', function () {
    config(['trypost.self_hosted' => false]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);

    test()->actingAs($user->fresh());

    $page = visit(route('app.calendar'))->resize(375, 812);

    $page->click('@sidebar-trigger');

    waitForDusk($page, 'sidebar-onboarding');
    $page->assertVisible('@sidebar-onboarding')->click('@sidebar-onboarding');

    waitForDusk($page, 'onboarding-mcp');
    waitForDusk($page, 'copy-mcp-url');
    $page->assertVisible('@onboarding-mcp')
        ->assertVisible('@copy-mcp-url')
        ->assertVisible('@mcp-client-claude');
});
