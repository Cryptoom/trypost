<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\Workspace\StoryMotionEffect;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Media\StoryMotionEffectPicker;
use App\Services\Media\StoryMotionSettings;

// PATCH:story-motion-settings

function sm02Platform(Workspace $workspace, bool $published = false, ContentType $type = ContentType::FacebookStory): PostPlatform
{
    $post = Post::factory()->create(['workspace_id' => $workspace->id]);
    $account = SocialAccount::factory()->facebook()->create(['workspace_id' => $workspace->id]);
    $factory = PostPlatform::factory()->state(['post_id' => $post->id, 'social_account_id' => $account->id, 'content_type' => $type]);

    return ($published ? $factory->published() : $factory)->create();
}

beforeEach(function () {
    $this->workspace = Workspace::factory()->create();
    $this->picker = new StoryMotionEffectPicker;
});

test('effect picker: a single selected effect is always the one used, in every mode', function (string $mode) {
    $settings = StoryMotionSettings::fromArray(['effects' => ['sparkle'], 'mode' => $mode]);

    expect($this->picker->pick($settings, $this->workspace, sm02Platform($this->workspace)))->toBe(StoryMotionEffect::Sparkle);
})->with(['fixed', 'rotate', 'random']);

test('effect picker: fixed takes the first selected effect in the canonical order', function () {
    $settings = StoryMotionSettings::fromArray(['effects' => ['sparkle', 'snap', 'focus'], 'mode' => 'fixed']);

    expect($this->picker->pick($settings, $this->workspace, sm02Platform($this->workspace)))->toBe(StoryMotionEffect::Focus);
});

test('effect picker: random is crc32 of the PostPlatform id modulo the number of effects, and the same story always gets the same one', function () {
    $settings = StoryMotionSettings::fromArray(['effects' => ['drift', 'focus', 'snap', 'sparkle'], 'mode' => 'random']);
    $platform = sm02Platform($this->workspace);

    $expected = $settings->effects[crc32((string) $platform->id) % 4];

    expect($this->picker->pick($settings, $this->workspace, $platform))->toBe($expected)
        ->and($this->picker->pick($settings, $this->workspace, $platform))->toBe($expected);
});

test('effect picker: random reaches every effect over a range of ids', function () {
    $settings = StoryMotionSettings::fromArray(['effects' => ['drift', 'focus', 'snap', 'sparkle'], 'mode' => 'random']);
    $seen = [];

    foreach (range(1, 40) as $i) {
        $platform = sm02Platform($this->workspace);
        $seen[$this->picker->pick($settings, $this->workspace, $platform)->value] = true;
    }

    expect(array_keys($seen))->toHaveCount(4);
});

test('effect picker: rotate counts the published Facebook Stories of this workspace only', function () {
    $settings = StoryMotionSettings::fromArray(['effects' => ['drift', 'focus', 'snap'], 'mode' => 'rotate']);
    $next = sm02Platform($this->workspace);

    expect($this->picker->pick($settings, $this->workspace, $next))->toBe(StoryMotionEffect::Drift);

    sm02Platform($this->workspace, published: true);
    expect($this->picker->pick($settings, $this->workspace, $next))->toBe(StoryMotionEffect::Focus);

    sm02Platform($this->workspace, published: true);
    expect($this->picker->pick($settings, $this->workspace, $next))->toBe(StoryMotionEffect::Snap);

    sm02Platform($this->workspace, published: true);
    expect($this->picker->pick($settings, $this->workspace, $next))->toBe(StoryMotionEffect::Drift, 'wraps around');
});

test('effect picker: rotate ignores unpublished stories, other content types and other workspaces', function () {
    $settings = StoryMotionSettings::fromArray(['effects' => ['drift', 'focus'], 'mode' => 'rotate']);
    $next = sm02Platform($this->workspace);

    sm02Platform($this->workspace);
    sm02Platform($this->workspace, published: true, type: ContentType::FacebookReel);
    sm02Platform(Workspace::factory()->create(), published: true);

    expect($this->picker->pick($settings, $this->workspace, $next))->toBe(StoryMotionEffect::Drift);
});
