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
function waitForWelcomeTestId(mixed $page, string $testId): void
{
    $page->script(<<<JS
        (async () => {
            const sel = '[data-testid="{$testId}"]';
            for (let i = 0; i < 100; i++) {
                const el = document.querySelector(sel);
                if (el && el.getBoundingClientRect().height > 0) return;
                await new Promise((r) => setTimeout(r, 50));
            }
        })();
    JS);
}

function welcomeOwnerOnConnectStep(): User
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

    $user = welcomeOwnerOnConnectStep();

    $this->actingAs($user);

    $page = visit(route('app.welcome.connect'));

    waitForWelcomeTestId($page, 'welcome-platform-instagram');

    $page->assertRoute('app.welcome.connect')
        ->assertVisible('@welcome-platform-instagram')
        ->assertMissing('@welcome-connect-grid')
        ->click('@welcome-platform-instagram');

    waitForWelcomeTestId($page, 'welcome-connect-grid');

    $page->assertVisible('@welcome-connect-grid')
        ->assertVisible('@welcome-start-checkout')
        ->assertDisabled('@welcome-start-checkout')
        ->assertVisible('@welcome-step-4')
        ->assertNoJavaScriptErrors();
});

test('connect step enables continue when a social account is connected', function () {
    config(['trypost.self_hosted' => false]);

    $user = welcomeOwnerOnConnectStep();
    SocialAccount::factory()->linkedin()->create([
        'workspace_id' => $user->current_workspace_id,
    ]);

    $this->actingAs($user->fresh());

    $page = visit(route('app.welcome.connect'));

    waitForWelcomeTestId($page, 'welcome-start-checkout');

    $page->assertRoute('app.welcome.connect')
        ->assertVisible('@welcome-connect-grid')
        ->assertEnabled('@welcome-start-checkout')
        ->assertMissing('@welcome-latest-post')
        ->assertNoJavaScriptErrors();
});

test('connect step can go back to referral', function () {
    config(['trypost.self_hosted' => false]);

    $user = welcomeOwnerOnConnectStep();

    $this->actingAs($user);

    $page = visit(route('app.welcome.connect'));

    waitForWelcomeTestId($page, 'welcome-step-3');

    $page->click('@welcome-step-3');

    waitForWelcomeTestId($page, 'welcome-referral-continue');

    $page->assertRoute('app.welcome.referral-source')
        ->assertVisible('@welcome-referral-continue')
        ->assertNoJavaScriptErrors();
});

test('welcome chat screenshots each step', function () {
    config(['trypost.self_hosted' => false]);

    $user = User::factory()->create();

    $this->actingAs($user);

    $page = visit(route('app.welcome.persona'));

    waitForWelcomeTestId($page, 'welcome-chat');

    $page->assertVisible('@welcome-chat')
        ->click('@welcome-persona-agency')
        ->screenshot(filename: 'welcome-chat-persona');

    $user->update(['persona' => Persona::Agency->value]);

    $this->actingAs($user->fresh());

    $page = visit(route('app.welcome.goals'));

    waitForWelcomeTestId($page, 'welcome-chat');

    $page->assertVisible('@welcome-chat')
        ->click('@welcome-goal-save_time')
        ->screenshot(filename: 'welcome-chat-goals');

    $user->update(['goals' => [Goal::SaveTime->value]]);

    $this->actingAs($user->fresh());

    $page = visit(route('app.welcome.referral-source'));

    waitForWelcomeTestId($page, 'welcome-chat');

    $page->assertVisible('@welcome-chat')
        ->click('@welcome-source-product_hunt')
        ->screenshot(filename: 'welcome-chat-referral');

    $user = welcomeOwnerOnConnectStep();

    $this->actingAs($user);

    $page = visit(route('app.welcome.connect'));

    waitForWelcomeTestId($page, 'welcome-platform-instagram');

    $page->screenshot(filename: 'welcome-chat-connect-ask');

    $page->click('@welcome-platform-instagram');

    waitForWelcomeTestId($page, 'welcome-connect-grid');

    $page->assertVisible('@welcome-chat')
        ->assertVisible('@welcome-connect-grid')
        ->screenshot(filename: 'welcome-chat-connect');
});

test('welcome chat screenshots the latest post reach pitch', function () {
    config(['trypost.self_hosted' => false]);

    $user = welcomeOwnerOnConnectStep();
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

        $page = visit(route('app.welcome.connect'));

        waitForWelcomeTestId($page, 'welcome-reach-pitch');

        $page->script(<<<'JS'
            document.querySelector('[data-testid="welcome-reach-pitch"]')
                ?.scrollIntoView({ block: 'center' });
        JS);

        $page->assertVisible('@welcome-latest-post')
            ->assertVisible('@welcome-reach-pitch')
            ->assertVisible('@welcome-start-checkout')
            ->assertVisible('@welcome-change-network')
            ->screenshot(filename: 'welcome-chat-reach-pitch')
            ->assertNoJavaScriptErrors();

        $page->click('@welcome-change-network');

        waitForWelcomeTestId($page, 'welcome-platform-instagram');

        $page->assertVisible('@welcome-platform-instagram')
            ->assertMissing('@welcome-connect-grid')
            ->assertMissing('@welcome-reach-pitch')
            ->assertMissing('@welcome-latest-post')
            ->assertNoJavaScriptErrors();
    } finally {
        @unlink($postPath);
    }
});

test('connect step redirects to persona when prior steps are missing', function () {
    config(['trypost.self_hosted' => false]);

    $user = User::factory()->create();

    $this->actingAs($user);

    $page = visit(route('app.welcome.connect'));

    $page->assertRoute('app.welcome.persona')
        ->assertNoJavaScriptErrors();
});
