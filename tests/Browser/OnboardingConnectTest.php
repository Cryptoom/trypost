<?php

declare(strict_types=1);

use App\Enums\User\Goal;
use App\Enums\User\Persona;
use App\Enums\User\ReferralSource;
use App\Enums\UserWorkspace\Role;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;

/**
 * Wait for a data-testid element to mount and lay out. Pest browser `@`
 * selectors resolve to data-testid, and assertions do not auto-wait on SPA paint.
 */
function waitForOnboardingTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const sel = '[data-testid="{$testId}"]';
            for (let i = 0; i < 160; i++) {
                const el = document.querySelector(sel);
                if (el && el.getBoundingClientRect().height > 0) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

function assertOnboardingChatScrolledToEnd(mixed $page): void
{
    $result = $page->script(<<<'JS'
        (async () => {
            for (let i = 0; i < 60; i++) {
                const remaining =
                    document.documentElement.scrollHeight -
                    window.scrollY -
                    window.innerHeight;

                if (remaining <= 24) {
                    return { ok: true, remaining };
                }

                await new Promise((r) => setTimeout(r, 50));
            }

            return {
                ok: false,
                remaining:
                    document.documentElement.scrollHeight -
                    window.scrollY -
                    window.innerHeight,
            };
        })()
    JS);

    expect($result['ok'] ?? false)->toBeTrue(
        'onboarding chat should finish scrolling to the latest turn (remaining: '.($result['remaining'] ?? 'n/a').')',
    );
}

function onboardingOwnerOnConnectStep(): User
{
    $user = User::factory()->create();
    $user->update([
        'persona' => Persona::Agency->value,
        'goals' => [Goal::SaveTime->value],
        'referral_source' => ReferralSource::ProductHunt->value,
    ]);

    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    return $user->fresh();
}

test('connect step shows the grid and keeps continue disabled without a social account', function () {
    config(['trypost.self_hosted' => false]);

    $user = onboardingOwnerOnConnectStep();

    $this->actingAs($user);

    $page = visit(route('app.onboarding'));

    waitForOnboardingTestId($page, 'onboarding-platform-instagram');

    $page->assertRoute('app.onboarding')
        ->assertVisible('@onboarding-platform-instagram')
        ->assertMissing('@onboarding-connect-grid')
        ->click('@onboarding-platform-instagram');

    waitForOnboardingTestId($page, 'onboarding-connect-grid');
    waitForOnboardingTestId($page, 'instagram-connect-dialog');

    $page->assertVisible('@onboarding-connect-grid')
        ->assertVisible('@instagram-connect-dialog')
        ->assertMissing('@onboarding-start-checkout')
        ->assertMissing('@onboarding-step-4')
        ->assertNoJavaScriptErrors();
});

test('connect step enables continue when a social account is connected', function () {
    config(['trypost.self_hosted' => false]);

    $user = onboardingOwnerOnConnectStep();
    SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $user->current_workspace_id,
    ]);

    $this->actingAs($user->fresh());

    $page = visit(route('app.onboarding'));

    waitForOnboardingTestId($page, 'onboarding-publish-manual');

    $page->assertRoute('app.onboarding')
        ->assertMissing('@onboarding-connect-grid')
        ->assertMissing('@onboarding-start-checkout')
        ->assertMissing('@onboarding-mcp-setup')
        ->assertMissing('@onboarding-latest-post')
        ->click('@onboarding-publish-manual');

    waitForOnboardingTestId($page, 'onboarding-start-checkout');

    $page->assertEnabled('@onboarding-start-checkout')
        ->assertMissing('@onboarding-mcp-setup')
        ->assertNoJavaScriptErrors();
});

test('connect step shows mcp setup after choosing AI', function () {
    config(['trypost.self_hosted' => false]);

    $user = onboardingOwnerOnConnectStep();
    SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $user->current_workspace_id,
    ]);

    $this->actingAs($user->fresh());

    $page = visit(route('app.onboarding'));

    waitForOnboardingTestId($page, 'onboarding-publish-ai');

    $page->click('@onboarding-publish-ai');

    waitForOnboardingTestId($page, 'onboarding-mcp-setup');

    assertOnboardingChatScrolledToEnd($page);

    $page->assertVisible('@onboarding-mcp-setup')
        ->assertMissing('@onboarding-mcp-connected')
        ->assertVisible('@onboarding-start-checkout')
        ->assertEnabled('@onboarding-start-checkout')
        ->assertNoJavaScriptErrors();
});

test('connect step shows mcp connected after oauth grant', function () {
    config(['trypost.self_hosted' => false]);

    $user = onboardingOwnerOnConnectStep();
    SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $user->current_workspace_id,
    ]);
    mcpAccessToken($user, mcpOauthClient('Claude'), $user->currentWorkspace);

    $this->actingAs($user->fresh());

    $page = visit(route('app.onboarding'));

    waitForOnboardingTestId($page, 'onboarding-publish-ai');

    $page->click('@onboarding-publish-ai');

    waitForOnboardingTestId($page, 'onboarding-mcp-connected');

    $page->assertVisible('@onboarding-mcp-connected')
        ->assertSee('Claude')
        ->assertVisible('@onboarding-start-checkout')
        ->assertNoJavaScriptErrors();
});

test('connect step shows prior answers without a header', function () {
    config(['trypost.self_hosted' => false]);

    $user = onboardingOwnerOnConnectStep();

    $this->actingAs($user);

    $page = visit(route('app.onboarding'));

    waitForOnboardingTestId($page, 'onboarding-source-product_hunt');

    $page->assertRoute('app.onboarding')
        ->assertVisible('@onboarding-source-product_hunt')
        ->assertMissing('@onboarding-step-1')
        ->assertMissing('@onboarding-step-3')
        ->assertMissing('@onboarding-referral-continue')
        ->assertNoJavaScriptErrors();
});

test('persona chip submits and advances to goals', function () {
    config(['trypost.self_hosted' => false]);

    $user = User::factory()->create();

    $this->actingAs($user);

    $page = visit(route('app.onboarding'));

    waitForOnboardingTestId($page, 'onboarding-persona-agency');

    $page->assertDontSee('onboarding.title')
        ->assertDontSee('onboarding.description')
        ->assertSee(__('onboarding.title'))
        ->click('@onboarding-persona-agency');

    waitForOnboardingTestId($page, 'onboarding-goal-save_time');

    $page->assertRoute('app.onboarding')
        ->assertVisible('@onboarding-goal-save_time')
        ->assertMissing('@onboarding-persona-continue')
        ->assertNoJavaScriptErrors();

    expect($user->fresh()->persona)->toBe(Persona::Agency);
});

test('goal chip submits and advances to referral', function () {
    config(['trypost.self_hosted' => false]);

    $user = User::factory()->create();
    $user->update(['persona' => Persona::Agency->value]);

    $this->actingAs($user->fresh());

    $page = visit(route('app.onboarding'));

    waitForOnboardingTestId($page, 'onboarding-goal-save_time');

    $page->click('@onboarding-goal-save_time');

    waitForOnboardingTestId($page, 'onboarding-source-google');

    $page->assertRoute('app.onboarding')
        ->assertVisible('@onboarding-source-google')
        ->assertMissing('@onboarding-goals-continue')
        ->assertNoJavaScriptErrors();

    expect($user->fresh()->goals)->toBe([Goal::SaveTime->value]);
});

test('referral chip submits and advances to connect', function () {
    config(['trypost.self_hosted' => false]);

    $user = User::factory()->create();
    $user->update([
        'persona' => Persona::Agency->value,
        'goals' => [Goal::SaveTime->value],
    ]);
    $workspace = Workspace::factory()->create([
        'account_id' => $user->account_id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $this->actingAs($user->fresh());

    $page = visit(route('app.onboarding'));

    waitForOnboardingTestId($page, 'onboarding-source-google');

    $page->click('@onboarding-source-google');

    waitForOnboardingTestId($page, 'onboarding-platform-instagram');

    $page->assertRoute('app.onboarding')
        ->assertVisible('@onboarding-platform-instagram')
        ->assertMissing('@onboarding-referral-continue')
        ->assertNoJavaScriptErrors();

    expect($user->fresh()->referral_source)->toBe(ReferralSource::Google);
});

test('welcome chat screenshots each step', function () {
    config(['trypost.self_hosted' => false]);

    $user = User::factory()->create();

    $this->actingAs($user);

    $page = visit(route('app.onboarding'));

    waitForOnboardingTestId($page, 'onboarding-chat');

    $page->assertVisible('@onboarding-chat')
        ->screenshot(filename: 'onboarding-chat-persona');

    $user->update(['persona' => Persona::Agency->value]);

    $this->actingAs($user->fresh());

    $page = visit(route('app.onboarding'));

    waitForOnboardingTestId($page, 'onboarding-chat');

    $page->assertVisible('@onboarding-chat')
        ->screenshot(filename: 'onboarding-chat-goals');

    $user->update(['goals' => [Goal::SaveTime->value]]);

    $this->actingAs($user->fresh());

    $page = visit(route('app.onboarding'));

    waitForOnboardingTestId($page, 'onboarding-chat');

    $page->assertVisible('@onboarding-chat')
        ->screenshot(filename: 'onboarding-chat-referral');

    $user = onboardingOwnerOnConnectStep();

    $this->actingAs($user);

    $page = visit(route('app.onboarding'));

    waitForOnboardingTestId($page, 'onboarding-platform-instagram');

    $page->screenshot(filename: 'onboarding-chat-connect-ask');

    $page->click('@onboarding-platform-instagram');

    waitForOnboardingTestId($page, 'onboarding-connect-grid');
    waitForOnboardingTestId($page, 'instagram-connect-dialog');

    $page->assertVisible('@onboarding-chat')
        ->assertVisible('@onboarding-connect-grid')
        ->assertVisible('@instagram-connect-dialog')
        ->screenshot(filename: 'onboarding-chat-connect');
});

test('welcome chat screenshots the latest post reach pitch', function () {
    config(['trypost.self_hosted' => false]);

    $user = onboardingOwnerOnConnectStep();
    SocialAccount::factory()->instagram()->create([
        'workspace_id' => $user->current_workspace_id,
        'platform_user_id' => '178414000',
    ]);

    $postPath = public_path('images/welcome-dusk-post.jpg');
    $image = imagecreatetruecolor(640, 640);

    for ($y = 0; $y < 640; $y++) {
        $t = $y / 639;
        imageline(
            $image,
            0,
            $y,
            639,
            $y,
            imagecolorallocate(
                $image,
                (int) (245 - $t * 80),
                (int) (158 - $t * 40),
                (int) (11 + $t * 180),
            ),
        );
    }

    imagejpeg($image, $postPath, 85);
    imagedestroy($image);

    Http::fake([
        config('trypost.platforms.instagram.graph_api').'/178414000/media*' => Http::response([
            'data' => [[
                'id' => '1789',
                'caption' => 'Breakfast at the studio. New drop on Friday.',
                'media_type' => 'IMAGE',
                'media_url' => '/images/welcome-dusk-post.jpg',
                'permalink' => 'https://www.instagram.com/p/abc',
                'timestamp' => '2026-08-01T12:00:00+0000',
            ]],
        ]),
        config('trypost.platforms.instagram.graph_api').'/1789/insights*' => Http::response([
            'data' => [['name' => 'views', 'values' => [['value' => 1]]]],
        ]),
    ]);

    try {
        $this->actingAs($user->fresh());

        $page = visit(route('app.onboarding'));

        waitForOnboardingTestId($page, 'onboarding-reach-pitch');

        assertOnboardingChatScrolledToEnd($page);

        $page->assertVisible('@onboarding-latest-post')
            ->assertVisible('@onboarding-reach-pitch')
            ->assertVisible('@onboarding-publish-manual')
            ->assertVisible('@onboarding-publish-ai')
            ->assertMissing('@onboarding-start-checkout')
            ->assertMissing('@onboarding-mcp-setup')
            ->assertVisible('@onboarding-change-network')
            ->assertMissing('@onboarding-connect-grid')
            ->screenshot(filename: 'onboarding-chat-reach-pitch')
            ->assertNoJavaScriptErrors();

        $page->click('@onboarding-change-network');

        waitForOnboardingTestId($page, 'onboarding-platform-instagram');

        $page->assertVisible('@onboarding-platform-instagram')
            ->assertMissing('@onboarding-connect-grid')
            ->assertMissing('@onboarding-reach-pitch')
            ->assertMissing('@onboarding-latest-post')
            ->assertNoJavaScriptErrors();
    } finally {
        @unlink($postPath);
    }
});

test('legacy connect url opens welcome at the first incomplete step', function () {
    config(['trypost.self_hosted' => false]);

    $user = User::factory()->create();

    $this->actingAs($user);

    $page = visit(route('app.onboarding.connect'));

    waitForOnboardingTestId($page, 'onboarding-persona-agency');

    $page->assertRoute('app.onboarding')
        ->assertVisible('@onboarding-persona-agency')
        ->assertNoJavaScriptErrors();
});
