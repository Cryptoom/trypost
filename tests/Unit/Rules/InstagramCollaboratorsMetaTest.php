<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Rules\InstagramCollaboratorsMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $platform
 * @param  list<mixed>  $collaborators
 * @return array<string, list<string>>
 */
function runCollaboratorsMetaRule(array $collaborators, array $platform): array
{
    $data = [
        'platforms' => [array_merge($platform, ['meta' => ['collaborators' => $collaborators]])],
    ];
    $validator = Validator::make($data, []);
    $rule = (new InstagramCollaboratorsMeta)->setData($data)->setValidator($validator);
    $parentErrors = [];

    $rule->validate('platforms.0.meta.collaborators', $collaborators, function (string $message) use (&$parentErrors): void {
        $parentErrors[] = $message;
    });

    return [
        'parent' => $parentErrors,
        'items' => $validator->errors()->messages(),
    ];
}

test('fails when the collaborator is the connected instagram account', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $workspace->id,
        'username' => 'testuser',
    ]);

    $errors = runCollaboratorsMetaRule(['@TestUser'], [
        'social_account_id' => $account->id,
    ]);

    expect($errors['items']['platforms.0.meta.collaborators.0'] ?? [])->toBe([__('posts.form.instagram.collaborators_self')]);
});

test('passes when the collaborator is a different username', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $workspace->id,
        'username' => 'testuser',
    ]);

    $errors = runCollaboratorsMetaRule(['host_one'], [
        'social_account_id' => $account->id,
    ]);

    expect($errors['parent'])->toBe([])
        ->and($errors['items'])->toBe([]);
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

    $errors = runCollaboratorsMetaRule(['page_ig'], [
        'id' => $platform->id,
    ]);

    expect($errors['items']['platforms.0.meta.collaborators.0'] ?? [])->toBe([__('posts.form.instagram.collaborators_self')]);
});

test('skips instagram constraints when the platform is tiktok', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $workspace->id,
        'username' => 'testuser',
    ]);

    $errors = runCollaboratorsMetaRule(['@TestUser', 'a', 'b', 'c', 'not valid!!'], [
        'social_account_id' => $account->id,
    ]);

    expect($errors['parent'])->toBe([])
        ->and($errors['items'])->toBe([]);
});
