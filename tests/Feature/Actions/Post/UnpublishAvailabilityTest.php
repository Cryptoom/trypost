<?php

declare(strict_types=1);

use App\Actions\Post\UnpublishPost;
use App\Enums\UserWorkspace\Role;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\FacebookPublisher;
use App\Services\Social\LinkedInPublisher;
use App\Services\Social\YouTubePublisher;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->account = SocialAccount::factory()->facebook()->create(['workspace_id' => $this->workspace->id]);
});

/**
 * @param  list<string>  $states  PostPlatformFactory state names, all created published
 */
function unp01PublishedPost(object $test, array $states): Post
{
    $post = Post::factory()->published()->create([
        'workspace_id' => $test->workspace->id,
        'user_id' => $test->user->id,
    ]);

    foreach ($states as $state) {
        PostPlatform::factory()->{$state}()->published()->create([
            'post_id' => $post->id,
            'social_account_id' => $test->account->id,
        ]);
    }

    return $post;
}

test('unp01 canBeUnpublished is false for a facebook story', function () {
    $post = unp01PublishedPost($this, ['facebookStory']);

    expect($post->postPlatforms->first()->canBeUnpublished())->toBeFalse();
});

test('unp01 canBeUnpublished is true for facebook post and reel', function () {
    $post = unp01PublishedPost($this, ['facebook', 'facebookReel']);

    expect($post->postPlatforms->every(fn (PostPlatform $pp) => $pp->canBeUnpublished()))->toBeTrue();
});

test('unp01 canBeUnpublished keeps the instagram direct login exception', function () {
    $post = unp01PublishedPost($this, ['instagram', 'instagramFacebook']);
    $byPlatform = $post->postPlatforms->keyBy(fn (PostPlatform $pp) => $pp->platform->value);

    expect($byPlatform['instagram']->canBeUnpublished())->toBeFalse();
});

test('unp01 canBeUnpublished matches platforms without a delete publisher', function () {
    $post = unp01PublishedPost($this, ['tiktok', 'youtube', 'linkedin']);
    $byPlatform = $post->postPlatforms->keyBy(fn (PostPlatform $pp) => $pp->platform->value);

    expect($byPlatform['tiktok']->canBeUnpublished())->toBeFalse()
        ->and($byPlatform['youtube']->canBeUnpublished())->toBe(method_exists(app(YouTubePublisher::class), 'delete'))
        ->and($byPlatform['linkedin']->canBeUnpublished())->toBe(method_exists(app(LinkedInPublisher::class), 'delete'));
});

test('unp01 index flags a story-only post as not unpublishable with the story reason', function () {
    $post = unp01PublishedPost($this, ['facebookStory']);

    $this->actingAs($this->user)->get(route('app.posts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('posts.data.0.id', $post->id)
            ->where('posts.data.0.can_unpublish', false)
            ->where('posts.data.0.unpublish_blocked_reason', 'facebook_story')
        );
});

test('unp01 index flags a facebook post and a facebook reel as unpublishable', function () {
    unp01PublishedPost($this, ['facebook']);
    unp01PublishedPost($this, ['facebookReel']);

    $this->actingAs($this->user)->get(route('app.posts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('posts.data', 2)
            ->where('posts.data.0.can_unpublish', true)
            ->where('posts.data.0.unpublish_blocked_reason', null)
            ->where('posts.data.1.can_unpublish', true)
        );
});

test('unp01 index keeps unpublish active for a post mixing a story and a reel', function () {
    unp01PublishedPost($this, ['facebookStory', 'facebookReel']);

    $this->actingAs($this->user)->get(route('app.posts.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('posts.data.0.can_unpublish', true)
            ->where('posts.data.0.unpublish_blocked_reason', null)
        );
});

test('unp01 index uses the generic reason for a tiktok-only post', function () {
    unp01PublishedPost($this, ['tiktok']);

    $this->actingAs($this->user)->get(route('app.posts.index'))
        ->assertInertia(fn ($page) => $page
            ->where('posts.data.0.can_unpublish', false)
            ->where('posts.data.0.unpublish_blocked_reason', 'unsupported')
        );
});

test('unp01 UnpublishPost still sorts a story into unsupported and a reel into unpublished', function () {
    app()->instance(FacebookPublisher::class, new class extends FacebookPublisher
    {
        public function delete(PostPlatform $postPlatform): void {}
    });

    $post = unp01PublishedPost($this, ['facebookStory', 'facebookReel']);

    $result = UnpublishPost::execute($post);

    expect($result['unsupported'])->toHaveCount(1)
        ->and($result['unsupported'][0]->content_type->value)->toBe('facebook_story')
        ->and($result['unpublished'])->toHaveCount(1)
        ->and($result['unpublished'][0]->content_type->value)->toBe('facebook_reel')
        ->and($result['failed'])->toBe([]);
});
