<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;

/**
 * Poll browser-side until the dusk element exists. These Pest browser
 * assertions do not auto-wait, and Inertia visits settle asynchronously.
 */
function waitForDusk(mixed $page, string $selector): void
{
    $page->script(<<<JS
        (async () => {
            const sel = '[dusk="{$selector}"]';
            for (let i = 0; i < 100; i++) {
                if (document.querySelector(sel)) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

/**
 * Poll browser-side until the location path matches, then let the new page
 * mount before asserting.
 */
function waitForPath(mixed $page, string $path): void
{
    $page->script(<<<JS
        (async () => {
            for (let i = 0; i < 100; i++) {
                if (window.location.pathname === '{$path}') break;
                await new Promise((r) => setTimeout(r, 50));
            }
            await new Promise((r) => setTimeout(r, 500));
        })();
    JS);
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

    // The CTA shows the plan/trial context. Clicking through to Stripe is
    // covered by feature tests — the browser must not hit the Stripe API.
    waitForDusk($page, 'welcome-start-checkout');
    $page->assertVisible('@welcome-checkout-plan-note')
        ->assertVisible('@welcome-start-checkout');

    expect($user->fresh()->persona?->value)->toBe('creator')
        ->and($user->fresh()->goals)->toBe(['save_time']);
});

test('owner skips the onboarding checklist and the residual banner disappears', function () {
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

    waitForDusk($page, 'onboarding-skip');
    $page->assertVisible('@onboarding-mcp')
        ->assertVisible('@onboarding-social')
        ->assertVisible('@onboarding-first-post')
        ->click('@onboarding-skip');

    waitForPath($page, '/calendar');
    $page->assertMissing('@sidebar-onboarding');

    expect($user->account->fresh()->onboarding_dismissed_at)->not->toBeNull();
});

test('member without app access lands on the subscription required screen', function () {
    config(['trypost.self_hosted' => false]);

    ['member' => $member] = strandedMemberOnSharedAccount();

    test()->actingAs($member);

    $page = visit(route('app.calendar'));

    waitForDusk($page, 'welcome-subscription-required');
    $page->assertVisible('@welcome-subscription-required');
});
