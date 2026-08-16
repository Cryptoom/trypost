<?php

declare(strict_types=1);

use App\Enums\PostPlatform\Status;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Social\InstagramCollaborators;
use Illuminate\Support\Facades\Http;

test('normalize strips at signs, trims, and deduplicates case-insensitively', function () {
    expect(InstagramCollaborators::normalize([' @Host_One ', 'host_one', 'host_two', '', 1]))
        ->toBe(['Host_One', 'host_two']);
});

test('normalize caps at three usernames', function () {
    expect(InstagramCollaborators::normalize(['a', 'b', 'c', 'd']))->toBe(['a', 'b', 'c']);
});

test('payload encodes a json array string', function () {
    expect(InstagramCollaborators::payload(['@a', 'b']))->toBe([
        'collaborators' => '["a","b"]',
    ]);
});

test('payload is empty when there are no usernames', function () {
    expect(InstagramCollaborators::payload([]))->toBe([]);
});

test('isSameUsername ignores at signs and case', function () {
    expect(InstagramCollaborators::isSameUsername('@TestUser', 'testuser'))->toBeTrue()
        ->and(InstagramCollaborators::isSameUsername('host_one', 'host_two'))->toBeFalse()
        ->and(InstagramCollaborators::isSameUsername('host_one', null))->toBeFalse();
});

test('payload omits the connected account username', function () {
    expect(InstagramCollaborators::payload(['@TestUser', 'host_one'], 'testuser'))->toBe([
        'collaborators' => '["host_one"]',
    ]);
});

test('fetch invite status skips the graph call for standalone instagram', function () {
    Http::fake();

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => User::factory()]);
    $platform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::Instagram,
        'status' => Status::Published,
        'platform_post_id' => 'media-1',
        'meta' => ['collaborators' => ['host_one']],
    ]);

    expect(InstagramCollaborators::fetchInviteStatus($platform->load('socialAccount')))->toBe([
        'status_available' => false,
        'collaborators' => [['username' => 'host_one', 'invite_status' => null]],
    ]);

    Http::assertNothingSent();
});

test('fetch invite status maps facebook login graph response', function () {
    Http::fake([
        config('trypost.platforms.instagram-facebook.graph_api').'/media-1/collaborators*' => Http::response([
            'data' => [
                ['username' => 'host_one', 'invite_status' => 'Accpeted'],
                ['username' => 'host_two', 'invite_status' => 'Pending'],
            ],
        ], 200),
    ]);

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagramFacebook()->create([
        'workspace_id' => $workspace->id,
        'access_token' => 'token-fb',
    ]);
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => User::factory()]);
    $platform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::Instagram,
        'status' => Status::Published,
        'platform_post_id' => 'media-1',
        'meta' => ['collaborators' => ['host_one', 'host_two']],
    ]);

    expect(InstagramCollaborators::fetchInviteStatus($platform->load('socialAccount')))->toBe([
        'status_available' => true,
        'collaborators' => [
            ['username' => 'host_one', 'invite_status' => 'Accepted'],
            ['username' => 'host_two', 'invite_status' => 'Pending'],
        ],
    ]);
});

test('fetch invite status falls back when the graph call fails', function () {
    Http::fake([
        config('trypost.platforms.instagram-facebook.graph_api').'/media-1/collaborators*' => Http::response(['error' => ['message' => 'unsupported']], 400),
    ]);

    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagramFacebook()->create([
        'workspace_id' => $workspace->id,
        'access_token' => 'token-fb',
    ]);
    $post = Post::factory()->create(['workspace_id' => $workspace->id, 'user_id' => User::factory()]);
    $platform = PostPlatform::factory()->instagramFacebook()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'status' => Status::Published,
        'platform_post_id' => 'media-1',
        'meta' => ['collaborators' => ['host_one']],
    ]);

    expect(InstagramCollaborators::fetchInviteStatus($platform->load('socialAccount')))->toBe([
        'status_available' => false,
        'collaborators' => [['username' => 'host_one', 'invite_status' => null]],
    ]);
});
