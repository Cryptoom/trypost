<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Rules\InstagramCollaboratorIsNotSelf;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $platform
 * @return list<string>
 */
function runSelfCollaboratorRule(string $value, array $platform): array
{
    $errors = [];
    $rule = (new InstagramCollaboratorIsNotSelf)->setData([
        'platforms' => [$platform],
    ]);

    $rule->validate('platforms.0.meta.collaborators.0', $value, function (string $message) use (&$errors): void {
        $errors[] = $message;
    });

    return $errors;
}

test('fails when the collaborator is the connected instagram account', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $workspace->id,
        'username' => 'testuser',
    ]);

    $errors = runSelfCollaboratorRule('@TestUser', [
        'social_account_id' => $account->id,
        'meta' => ['collaborators' => ['@TestUser']],
    ]);

    expect($errors)->toBe([__('posts.form.instagram.collaborators_self')]);
});

test('passes when the collaborator is a different username', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $workspace->id,
        'username' => 'testuser',
    ]);

    expect(runSelfCollaboratorRule('host_one', [
        'social_account_id' => $account->id,
        'meta' => ['collaborators' => ['host_one']],
    ]))->toBe([]);
});

test('fails on update when the post platform account matches', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::InstagramFacebook,
        'username' => 'page_ig',
    ]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => User::factory(),
    ]);
    $platform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::InstagramFacebook,
    ]);

    $errors = runSelfCollaboratorRule('page_ig', [
        'id' => $platform->id,
        'meta' => ['collaborators' => ['page_ig']],
    ]);

    expect($errors)->toBe([__('posts.form.instagram.collaborators_self')]);
});

test('skips when social_account_id is not a uuid', function () {
    expect(runSelfCollaboratorRule('testuser', [
        'social_account_id' => 'not-a-uuid',
        'meta' => ['collaborators' => ['testuser']],
    ]))->toBe([]);
});
