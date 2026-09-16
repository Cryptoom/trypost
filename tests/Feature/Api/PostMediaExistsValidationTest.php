<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * TPX-03 verification: proves App\Support\PostMediaRules::assertHostedMediaExists
 * closes the gap where CreatePost/UpdatePost persisted a client-supplied
 * media id/path pair straight into posts.media without checking a real
 * medias row backed it, scoped to the caller's workspace. Covers the API
 * inline-media path (create + update); a matching web-side test lives in
 * tests/Feature/PostMediaExistsValidationWebTest.php.
 */
beforeEach(function () {
    $result = createApiTestToken();
    $this->user = $result['user'];
    $this->workspace = $result['workspace'];
    $this->plainToken = $result['plain_token'];

    $this->socialAccount = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);
});

it('rejects creating a post with a fabricated media id/path (no matching medias row)', function () {
    $payload = [
        'content' => 'Fabricated media id',
        'media' => [['id' => 'media-1', 'path' => 'media/foo.jpg', 'url' => 'https://example.com/foo.jpg', 'type' => 'image']],
        'platforms' => [
            ['social_account_id' => $this->socialAccount->id, 'content_type' => 'linkedin_post'],
        ],
    ];

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), $payload)
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['media.0.id']);

    expect(Post::where('workspace_id', $this->workspace->id)->count())->toBe(0);
});

it('creates a post when media id references a real asset owned by the workspace', function () {
    $asset = Media::factory()->assets()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
    ]);

    $payload = [
        'content' => 'Real media id',
        'media' => [['id' => $asset->id, 'path' => $asset->path, 'url' => 'https://example.com/'.$asset->path, 'type' => 'image']],
        'platforms' => [
            ['social_account_id' => $this->socialAccount->id, 'content_type' => 'linkedin_post'],
        ],
    ];

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), $payload)
        ->assertCreated();

    $post = Post::where('workspace_id', $this->workspace->id)->first();
    expect($post->media)->toHaveCount(1)
        ->and(data_get($post->media, '0.id'))->toBe($asset->id);
});

it('rejects creating a post with another workspace\'s real media id (cross-tenant IDOR)', function () {
    $other = Workspace::factory()->create();
    $foreignAsset = Media::factory()->assets()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $other->id,
    ]);

    $payload = [
        'content' => 'Cross-tenant media id',
        'media' => [['id' => $foreignAsset->id, 'path' => $foreignAsset->path, 'url' => $foreignAsset->url, 'type' => 'image']],
        'platforms' => [
            ['social_account_id' => $this->socialAccount->id, 'content_type' => 'linkedin_post'],
        ],
    ];

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), $payload)
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['media.0.id']);

    expect(Post::where('workspace_id', $this->workspace->id)->count())->toBe(0);
});

it('still allows a bare external url with no id/path (API download-and-host path)', function () {
    Http::fake([
        'example.com/photo.png' => Http::response(
            file_get_contents(__DIR__.'/../../fixtures/1x1.png'),
            200,
            ['Content-Type' => 'image/png'],
        ),
    ]);
    Storage::fake();

    $payload = [
        'content' => 'Fresh external url',
        'media' => [['url' => 'https://example.com/photo.png']],
        'platforms' => [
            ['social_account_id' => $this->socialAccount->id, 'content_type' => 'linkedin_post'],
        ],
    ];

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), $payload)
        ->assertCreated();

    $post = Post::where('workspace_id', $this->workspace->id)->first();
    expect($post->media)->toHaveCount(1);

    $storedId = data_get($post->media, '0.id');
    expect(Media::query()->whereKey($storedId)->exists())->toBeTrue();
});

it('rejects updating a post with a fabricated media id/path', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    $payload = [
        'status' => 'draft',
        'media' => [['id' => 'forged-id', 'path' => 'media/forged.jpg', 'url' => 'https://example.com/forged.jpg', 'type' => 'image']],
    ];

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->putJson(route('api.posts.update', $post), $payload)
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['media.0.id']);

    expect($post->fresh()->media)->toBe([]);
});

it('rejects a media item that sends a path without a resolvable id', function () {
    $payload = [
        'content' => 'Path without id',
        'media' => [['path' => 'media/orphan.jpg', 'url' => 'https://example.com/orphan.jpg', 'type' => 'image']],
        'platforms' => [
            ['social_account_id' => $this->socialAccount->id, 'content_type' => 'linkedin_post'],
        ],
    ];

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), $payload)
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['media.0.id']);
});

it('rejects a real media id from the workspace that belongs to a different collection', function () {
    $logo = Media::factory()->logo()->create([
        'mediable_type' => (new Workspace)->getMorphClass(),
        'mediable_id' => $this->workspace->id,
    ]);

    $payload = [
        'content' => 'Wrong collection',
        'media' => [['id' => $logo->id, 'path' => $logo->path, 'url' => $logo->url, 'type' => 'image']],
        'platforms' => [
            ['social_account_id' => $this->socialAccount->id, 'content_type' => 'linkedin_post'],
        ],
    ];

    $this->withHeaders(['Authorization' => 'Bearer '.$this->plainToken])
        ->postJson(route('api.posts.store'), $payload)
        ->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY)
        ->assertJsonValidationErrors(['media.0.id']);

    expect(Post::where('workspace_id', $this->workspace->id)->count())->toBe(0);
});
