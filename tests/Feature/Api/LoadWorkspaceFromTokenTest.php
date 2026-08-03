<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\AccessToken;
use App\Models\Account;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    config([
        'trypost.self_hosted' => false,
        'trypost.billing.require_card_for_trial' => true,
    ]);

    $result = createApiTestToken();
    $this->user = $result['user'];
    $this->workspace = $result['workspace'];
    $this->plainToken = $result['plain_token'];
});

test('rejects api requests when the account has no app access', function () {
    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.workspace.show'))
        ->assertStatus(Response::HTTP_PAYMENT_REQUIRED)
        ->assertJson(['message' => 'Active subscription required.']);
});

test('allows api requests for subscribed accounts', function () {
    subscribeAccount($this->user->account);

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.workspace.show'))
        ->assertOk();
});

test('allows api requests for generic-trial accounts with app access', function () {
    config(['trypost.billing.require_card_for_trial' => false]);

    $this->user->account->update([
        'trial_ends_at' => now()->addDays(8),
    ]);

    expect($this->user->account->fresh()->hasAppAccess())->toBeTrue()
        ->and($this->user->account->fresh()->subscribed(Account::SUBSCRIPTION_NAME))->toBeFalse();

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->getJson(route('api.workspace.show'))
        ->assertOk();
});

test('rejects mcp oauth grants for workspace viewers', function () {
    subscribeAccount($this->user->account);

    $viewer = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($viewer->id, ['role' => Role::Viewer->value]);
    $viewer->update(['current_workspace_id' => $this->workspace->id]);
    $result = $viewer->createToken('MCP');
    $token = AccessToken::query()->findOrFail($result->token->id);
    DB::table('oauth_clients')
        ->where('id', $token->client_id)
        ->update(['grant_types' => json_encode(['authorization_code'])]);

    $this->withHeaders(['Authorization' => "Bearer {$result->accessToken}"])
        ->getJson(route('api.workspace.show'))
        ->assertForbidden();
});

test('allows mcp oauth grants for workspace members', function () {
    subscribeAccount($this->user->account);

    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);
    $result = $member->createToken('MCP');
    $token = AccessToken::query()->findOrFail($result->token->id);
    DB::table('oauth_clients')
        ->where('id', $token->client_id)
        ->update(['grant_types' => json_encode(['authorization_code'])]);

    $this->withHeaders(['Authorization' => "Bearer {$result->accessToken}"])
        ->getJson(route('api.workspace.show'))
        ->assertOk();
});
