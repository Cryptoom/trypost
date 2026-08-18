<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user->refresh();

    subscribeAccount($this->user->account);
});

test('does not share onboarding progress', function (string $routeName) {
    $this->actingAs($this->user)
        ->get(route($routeName))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->missing('onboardingProgress'));
})->with([
    'calendar' => 'app.calendar',
    'mcp settings' => 'app.mcp.index',
]);

test('does not share onboarding progress on passport oauth consent', function () {
    $clientId = mcpOauthClient('Consent Share Agent');
    DB::table('oauth_clients')->where('id', $clientId)->update([
        'redirect_uris' => json_encode(['https://client.example/callback']),
    ]);

    $this->actingAs($this->user)
        ->get(route('passport.authorizations.authorize', oauthAuthorizeQuery($clientId)))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('mcp/Authorize')
            ->missing('onboardingProgress')
        );
});
