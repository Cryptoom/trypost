<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\Status as PlatformStatus;
use App\Enums\SocialAccount\Platform;
use App\Jobs\PublishToSocialPlatform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'status' => PostStatus::PartiallyPublished,
        'published_at' => now()->subHour(),
    ]);
});

test('it queues fresh attempts only for failed enabled platforms', function () {
    Bus::fake([PublishToSocialPlatform::class]);

    $publishedPlatform = PostPlatform::factory()->published()->create([
        'post_id' => $this->post->id,
        'social_account_id' => SocialAccount::factory()->linkedin()->create([
            'workspace_id' => $this->workspace->id,
        ]),
    ]);
    $failedThreads = PostPlatform::factory()->threads()->failed()->create([
        'post_id' => $this->post->id,
        'social_account_id' => SocialAccount::factory()->threads()->create([
            'workspace_id' => $this->workspace->id,
        ]),
        'platform_post_id' => 'stale-post-id',
        'platform_url' => 'https://threads.net/stale',
        'published_at' => now()->subHour(),
        'error_context' => ['remote_operation_id' => 'stale-operation'],
    ]);
    $failedPinterest = PostPlatform::factory()->pinterest()->failed()->create([
        'post_id' => $this->post->id,
        'social_account_id' => SocialAccount::factory()->pinterest()->create([
            'workspace_id' => $this->workspace->id,
        ]),
    ]);
    $disabledFailedPlatform = PostPlatform::factory()->tiktok()->failed()->disabled()->create([
        'post_id' => $this->post->id,
        'social_account_id' => SocialAccount::factory()->tiktok()->create([
            'workspace_id' => $this->workspace->id,
        ]),
    ]);

    $this->artisan('posts:retry', ['post' => $this->post->id, '--force' => true])
        ->expectsOutput('2 publish attempt(s) queued.')
        ->assertSuccessful();

    expect($this->post->fresh()->status)->toBe(PostStatus::Publishing)
        ->and($failedThreads->fresh()->status)->toBe(PlatformStatus::Pending)
        ->and($failedThreads->fresh()->platform_post_id)->toBeNull()
        ->and($failedThreads->fresh()->platform_url)->toBeNull()
        ->and($failedThreads->fresh()->published_at)->toBeNull()
        ->and($failedThreads->fresh()->error_message)->toBeNull()
        ->and($failedThreads->fresh()->error_context)->toBeNull()
        ->and($failedPinterest->fresh()->status)->toBe(PlatformStatus::Pending)
        ->and($publishedPlatform->fresh()->status)->toBe(PlatformStatus::Published)
        ->and($disabledFailedPlatform->fresh()->status)->toBe(PlatformStatus::Failed);

    Bus::assertDispatchedTimes(PublishToSocialPlatform::class, 2);
    Bus::assertDispatched(
        PublishToSocialPlatform::class,
        fn (PublishToSocialPlatform $job): bool => $job->postPlatform->is($failedThreads) && $job->uniqueAttempt === 0,
    );
});

test('it can retry only one requested platform', function () {
    Bus::fake([PublishToSocialPlatform::class]);

    $failedThreads = PostPlatform::factory()->threads()->failed()->create([
        'post_id' => $this->post->id,
        'social_account_id' => SocialAccount::factory()->threads()->create([
            'workspace_id' => $this->workspace->id,
        ]),
    ]);
    $failedTikTok = PostPlatform::factory()->tiktok()->failed()->create([
        'post_id' => $this->post->id,
        'social_account_id' => SocialAccount::factory()->tiktok()->create([
            'workspace_id' => $this->workspace->id,
        ]),
    ]);

    $this->artisan('posts:retry', [
        'post' => $this->post->id,
        '--platform' => Platform::Threads->value,
        '--force' => true,
    ])->assertSuccessful();

    expect($failedThreads->fresh()->status)->toBe(PlatformStatus::Pending)
        ->and($failedTikTok->fresh()->status)->toBe(PlatformStatus::Failed);

    Bus::assertDispatchedTimes(PublishToSocialPlatform::class, 1);
    Bus::assertDispatched(PublishToSocialPlatform::class, fn (PublishToSocialPlatform $job): bool => $job->postPlatform->is($failedThreads));
});

test('it removes stale TikTok derivatives before starting from scratch', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    Storage::fake();

    $derivativePath = 'social-tiktok-photos/123e4567-e89b-12d3-a456-426614174000.jpg';
    Storage::put($derivativePath, 'temporary image');
    $failedTikTok = PostPlatform::factory()->tiktok()->failed()->create([
        'post_id' => $this->post->id,
        'social_account_id' => SocialAccount::factory()->tiktok()->create([
            'workspace_id' => $this->workspace->id,
        ]),
        'error_context' => [
            'tiktok_publish_id' => 'stale-publish-id',
            'tiktok_derivative_paths' => [$derivativePath],
        ],
    ]);

    $this->artisan('posts:retry', ['post' => $this->post->id, '--force' => true])
        ->assertSuccessful();

    Storage::assertMissing($derivativePath);
    expect($failedTikTok->fresh()->status)->toBe(PlatformStatus::Pending)
        ->and($failedTikTok->fresh()->error_context)->toBeNull();

    Bus::assertDispatched(PublishToSocialPlatform::class, fn (PublishToSocialPlatform $job): bool => $job->postPlatform->is($failedTikTok));
});

test('it does not change the post when confirmation is declined', function () {
    Bus::fake([PublishToSocialPlatform::class]);

    $failedPlatform = PostPlatform::factory()->threads()->failed()->create([
        'post_id' => $this->post->id,
        'social_account_id' => SocialAccount::factory()->threads()->create([
            'workspace_id' => $this->workspace->id,
        ]),
    ]);

    $this->artisan('posts:retry', ['post' => $this->post->id])
        ->expectsConfirmation('Start new publish attempts for these failed platforms?', 'no')
        ->expectsOutput('Retry cancelled.')
        ->assertSuccessful();

    expect($this->post->fresh()->status)->toBe(PostStatus::PartiallyPublished)
        ->and($failedPlatform->fresh()->status)->toBe(PlatformStatus::Failed);

    Bus::assertNotDispatched(PublishToSocialPlatform::class);
});

test('it rejects posts that are not in a terminal failure state', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    $this->post->update(['status' => PostStatus::Publishing]);

    $this->artisan('posts:retry', ['post' => $this->post->id, '--force' => true])
        ->expectsOutput('Only failed or partially published posts can be retried.')
        ->assertFailed();

    Bus::assertNotDispatched(PublishToSocialPlatform::class);
});

test('it retries a completely failed post', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    $this->post->update(['status' => PostStatus::Failed]);
    $failedPlatform = PostPlatform::factory()->threads()->failed()->create([
        'post_id' => $this->post->id,
        'social_account_id' => SocialAccount::factory()->threads()->create([
            'workspace_id' => $this->workspace->id,
        ]),
    ]);

    $this->artisan('posts:retry', ['post' => $this->post->id, '--force' => true])
        ->assertSuccessful();

    expect($this->post->fresh()->status)->toBe(PostStatus::Publishing)
        ->and($failedPlatform->fresh()->status)->toBe(PlatformStatus::Pending);

    Bus::assertDispatched(PublishToSocialPlatform::class, fn (PublishToSocialPlatform $job): bool => $job->postPlatform->is($failedPlatform));
});

test('it fails when no failed enabled platform matches', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    PostPlatform::factory()->published()->create([
        'post_id' => $this->post->id,
        'social_account_id' => SocialAccount::factory()->linkedin()->create([
            'workspace_id' => $this->workspace->id,
        ]),
    ]);

    $this->artisan('posts:retry', ['post' => $this->post->id, '--force' => true])
        ->expectsOutput('No failed enabled platforms matched this post.')
        ->assertFailed();

    expect($this->post->fresh()->status)->toBe(PostStatus::PartiallyPublished);
    Bus::assertNotDispatched(PublishToSocialPlatform::class);
});

test('it fails when the post does not exist', function () {
    Bus::fake([PublishToSocialPlatform::class]);

    $this->artisan('posts:retry', ['post' => '019ff9ae-068b-72bf-9f2e-0314ce7dc0e2', '--force' => true])
        ->expectsOutput('Post not found.')
        ->assertFailed();

    Bus::assertNotDispatched(PublishToSocialPlatform::class);
});

test('it rejects an unknown platform option', function () {
    Bus::fake([PublishToSocialPlatform::class]);

    $this->artisan('posts:retry', [
        'post' => $this->post->id,
        '--platform' => 'myspace',
        '--force' => true,
    ])->expectsOutputToContain('Unknown platform.')
        ->assertFailed();

    Bus::assertNotDispatched(PublishToSocialPlatform::class);
});
