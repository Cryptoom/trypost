<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $result = createApiTestToken();
    $this->user = $result['user'];
    $this->workspace = $result['workspace'];
    $this->plainToken = $result['plain_token'];
});

it('lists pinterest boards for a connected account', function () {
    $account = SocialAccount::factory()->pinterest()->create([
        'workspace_id' => $this->workspace->id,
        'access_token' => 'pinterest-token',
    ]);

    Http::fake([
        config('trypost.platforms.pinterest.api').'/boards*' => Http::response([
            'items' => [
                ['id' => 'board_1', 'name' => 'Ideas', 'privacy' => 'PUBLIC'],
                ['id' => 'board_2', 'name' => 'Product', 'privacy' => 'PUBLIC'],
            ],
        ], 200),
    ]);

    $response = $this->getJson(route('api.social-accounts.boards', $account), [
        'Authorization' => "Bearer {$this->plainToken}",
    ]);

    $response->assertOk();
    $response->assertExactJson([
        'boards' => [
            ['id' => 'board_1', 'name' => 'Ideas'],
            ['id' => 'board_2', 'name' => 'Product'],
        ],
    ]);
});

it('rejects boards listing for non-pinterest accounts', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);

    $response = $this->getJson(route('api.social-accounts.boards', $account), [
        'Authorization' => "Bearer {$this->plainToken}",
    ]);

    $response->assertUnprocessable();
    $response->assertJsonPath('message', 'Boards are only available for Pinterest accounts.');
});

it('cannot list boards for an account from another workspace', function () {
    $otherWorkspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->pinterest()->create([
        'workspace_id' => $otherWorkspace->id,
    ]);

    $response = $this->getJson(route('api.social-accounts.boards', $account), [
        'Authorization' => "Bearer {$this->plainToken}",
    ]);

    $response->assertNotFound();
});

it('requires authentication to list boards', function () {
    $account = SocialAccount::factory()->pinterest()->create([
        'workspace_id' => $this->workspace->id,
    ]);

    $this->getJson(route('api.social-accounts.boards', $account))
        ->assertUnauthorized();
});
