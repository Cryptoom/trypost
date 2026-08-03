<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;

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

    // Skip lands on the calendar and the sidebar residual is gone for good.
    waitForPath($page, parse_url(route('app.calendar'), PHP_URL_PATH));
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

test('owner sees the residual banner on mobile and can dismiss it in place', function () {
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

    waitForDusk($page, 'sidebar-onboarding-mobile');
    $page->assertVisible('@sidebar-onboarding-mobile')
        ->click('@sidebar-onboarding-mobile-dismiss');

    // Dismiss with `stay` keeps the user on the calendar and clears the banner.
    waitForDuskGone($page, 'sidebar-onboarding-mobile');
    $page->assertMissing('@sidebar-onboarding-mobile');

    expect($user->account->fresh()->onboarding_dismissed_at)->not->toBeNull();
});
