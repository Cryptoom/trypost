<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\UserWorkspace\Role;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\AttachMediaFromUploadTool;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $this->token = (string) Str::uuid();
    $this->media = Media::factory()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
        'collection' => 'assets',
        'upload_token' => $this->token,
    ]);
});

test('attaches the uploaded Media to the post', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(AttachMediaFromUploadTool::class, [
            'post_id' => $this->post->id,
            'upload_token' => $this->token,
        ]);

    $response->assertOk();
    expect($this->post->fresh()->media)->toHaveCount(1);
});

test('attaches an uploaded Media with alt text stored in meta', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(AttachMediaFromUploadTool::class, [
            'post_id' => $this->post->id,
            'upload_token' => $this->token,
            'alt' => 'A scenic mountain view',
        ]);

    $response->assertOk();
    expect(data_get($this->post->fresh()->media, '0.meta.alt_text'))->toBe('A scenic mountain view');
});

test('does not store alt text on a non-image upload', function () {
    $video = Media::factory()->video()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
        'collection' => 'assets',
        'upload_token' => (string) Str::uuid(),
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(AttachMediaFromUploadTool::class, [
            'post_id' => $this->post->id,
            'upload_token' => $video->upload_token,
            'alt' => 'alt is meaningless for a video',
        ]);

    $response->assertOk();

    expect(data_get($this->post->fresh()->media, '0.type'))->toBe('video')
        ->and(data_get($this->post->fresh()->media, '0.meta.alt_text'))->toBeNull();
});

test('rejects alt text over the max length', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(AttachMediaFromUploadTool::class, [
            'post_id' => $this->post->id,
            'upload_token' => $this->token,
            'alt' => str_repeat('a', 2001),
        ]);

    $response->assertHasErrors();
});

test('rejects a token from a different workspace', function () {
    $other = User::factory()->create();
    $otherWs = Workspace::factory()->create(['user_id' => $other->id]);

    $foreignToken = (string) Str::uuid();
    Media::factory()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $otherWs->id,
        'collection' => 'assets',
        'upload_token' => $foreignToken,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(AttachMediaFromUploadTool::class, [
            'post_id' => $this->post->id,
            'upload_token' => $foreignToken,
        ]);

    $response->assertHasErrors();
    expect($this->post->fresh()->media)->toHaveCount(0);
});

test('rejects an unknown upload_token', function () {
    $response = TryPostServer::actingAs($this->user)
        ->tool(AttachMediaFromUploadTool::class, [
            'post_id' => $this->post->id,
            'upload_token' => (string) Str::uuid(),
        ]);

    $response->assertHasErrors();
});

test('rejects a post from another workspace', function () {
    $other = User::factory()->create();
    $otherWs = Workspace::factory()->create(['user_id' => $other->id]);
    $otherPost = Post::factory()->create([
        'workspace_id' => $otherWs->id,
        'user_id' => $other->id,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(AttachMediaFromUploadTool::class, [
            'post_id' => $otherPost->id,
            'upload_token' => $this->token,
        ]);

    $response->assertHasErrors();
});

test('post_platform_ids scopes the uploaded media to that platform via the pivot', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);
    $platform = PostPlatform::factory()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $account->id,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(AttachMediaFromUploadTool::class, [
            'post_id' => $this->post->id,
            'upload_token' => $this->token,
            'post_platform_ids' => [$platform->id],
        ]);

    $response->assertOk();
    expect($platform->media()->pluck('medias.id')->all())->toBe([$this->media->id]);
});

test('empty post_platform_ids behaves like omitting it', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);
    $platform = PostPlatform::factory()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $account->id,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(AttachMediaFromUploadTool::class, [
            'post_id' => $this->post->id,
            'upload_token' => $this->token,
            'post_platform_ids' => [],
        ]);

    $response->assertOk();
    expect($platform->media()->count())->toBe(0)
        ->and($platform->scopedMediaItems())->toHaveCount(1);
});

test('rejects a post_platform_ids value belonging to another post', function () {
    $otherPost = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);
    $foreignPlatform = PostPlatform::factory()->create([
        'post_id' => $otherPost->id,
        'social_account_id' => $account->id,
    ]);

    $response = TryPostServer::actingAs($this->user)
        ->tool(AttachMediaFromUploadTool::class, [
            'post_id' => $this->post->id,
            'upload_token' => $this->token,
            'post_platform_ids' => [$foreignPlatform->id],
        ]);

    $response->assertHasErrors();
    expect($foreignPlatform->media()->count())->toBe(0);
});
