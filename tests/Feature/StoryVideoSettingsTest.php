<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\User;
use App\Models\Workspace;

// PATCH:story-motion-settings: the Story video part of Settings, Brand.

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);

    $this->save = fn (array $payload) => $this->actingAs($this->user)
        ->from(route('app.workspace.brand'))
        ->put(route('app.workspace.story-video.update'), $payload);
});

test('story video settings: the endpoint requires authentication', function () {
    $this->put(route('app.workspace.story-video.update'), ['enabled' => true])->assertRedirect(route('login'));
});

test('story video settings: a workspace member who is not an admin or the owner is denied and nothing changes', function () {
    $member = User::factory()->create(['account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($member->id, ['role' => Role::Member->value]);
    $member->update(['current_workspace_id' => $this->workspace->id]);

    $this->actingAs($member)->put(route('app.workspace.story-video.update'), ['enabled' => true])->assertForbidden();

    expect($this->workspace->fresh()->story_motion_settings)->toBeNull();
});

test('story video settings: the brand page carries the settings, the option lists and the availability', function () {
    config()->set('trypost.platforms.facebook.story_motion', ['enabled' => true, 'url' => 'http://story-render:3000', 'token' => 'secret', 'timeout_seconds' => 255]);
    $this->workspace->update(['brand_font' => 'Noto Serif']);

    $this->actingAs($this->user)->get(route('app.workspace.brand'))->assertOk()->assertInertia(fn ($page) => $page
        ->component('settings/workspace/Brand', false)
        ->where('storyMotion.available', true)
        ->where('storyMotion.settings.enabled', false)
        ->where('storyMotion.settings.font', 'noto-serif')
        ->where('storyMotion.fonts', ['inter', 'commissioner', 'comfortaa', 'noto-sans', 'noto-serif'])
        ->where('storyMotion.effects', ['drift', 'focus', 'snap', 'sparkle'])
        ->where('storyMotion.modes', ['fixed', 'rotate', 'random'])
        ->where('storyMotion.hasLogo', false)
    );
});

test('story video settings: the brand page reports the section as unavailable on an installation without the service', function () {
    $this->actingAs($this->user)->get(route('app.workspace.brand'))->assertInertia(fn ($page) => $page->where('storyMotion.available', false));
});

test('story video settings: saving stores the full normalized object and redirects back with a success flash', function () {
    ($this->save)([
        'enabled' => true,
        'font' => 'comfortaa',
        'effects' => ['snap', 'drift'],
        'mode' => 'rotate',
        'logo_layer' => true,
        'ai_notice' => ['enabled' => true, 'text' => 'AI-generated image'],
        'ending' => ['enabled' => true, 'name' => 'PlayCraft', 'line' => 'Paphos'],
    ])->assertRedirect(route('app.workspace.brand'))->assertSessionHasNoErrors();

    expect($this->workspace->fresh()->story_motion_settings)->toBe([
        'enabled' => true,
        'font' => 'comfortaa',
        'effects' => ['drift', 'snap'],
        'mode' => 'rotate',
        'logo_layer' => true,
        'ai_notice' => ['enabled' => true, 'text' => 'AI-generated image'],
        'ending' => ['enabled' => true, 'name' => 'PlayCraft', 'line' => 'Paphos'],
    ]);
});

test('story video settings: a partial request keeps what is stored', function () {
    ($this->save)(['enabled' => true, 'font' => 'commissioner', 'ending' => ['enabled' => true, 'name' => 'A', 'line' => 'B']]);
    ($this->save)(['ending' => ['line' => 'C']])->assertSessionHasNoErrors();

    $stored = $this->workspace->fresh()->story_motion_settings;

    expect($stored['enabled'])->toBeTrue()
        ->and($stored['font'])->toBe('commissioner')
        ->and($stored['ending'])->toBe(['enabled' => true, 'name' => 'A', 'line' => 'C']);
});

test('story video settings: invalid input is rejected at the right field and nothing is stored', function (array $payload, string $field) {
    ($this->save)($payload)->assertSessionHasErrors($field);

    expect($this->workspace->fresh()->story_motion_settings)->toBeNull();
})->with([
    'unknown font' => [['font' => 'comic-sans'], 'font'],
    'unknown mode' => [['mode' => 'shuffle'], 'mode'],
    'unknown effect' => [['effects' => ['wobble']], 'effects.0'],
    'no effect at all' => [['effects' => []], 'effects'],
    'duplicate effect' => [['effects' => ['snap', 'snap']], 'effects.0'],
    'more effects than exist' => [['effects' => ['drift', 'focus', 'snap', 'sparkle', 'drift']], 'effects'],
    'enabled as text' => [['enabled' => 'maybe'], 'enabled'],
    'notice text over 40 characters' => [['ai_notice' => ['enabled' => true, 'text' => str_repeat('x', 41)]], 'ai_notice.text'],
    'ending name over 40 characters' => [['ending' => ['enabled' => true, 'name' => str_repeat('x', 41)]], 'ending.name'],
    'ending line over 60 characters' => [['ending' => ['enabled' => true, 'line' => str_repeat('x', 61)]], 'ending.line'],
    'ending switched on without name and line' => [['ending' => ['enabled' => true, 'name' => '', 'line' => '']], 'ending.name'],
]);

test('story video settings: a notice text the video cannot draw is rejected at the notice field', function (string $text, bool $rejected) {
    $response = ($this->save)(['ai_notice' => ['enabled' => true, 'text' => $text]]);

    if ($rejected) {
        $response->assertSessionHasErrors(['ai_notice.text' => __('settings.story_video.errors.notice_text_unsupported')]);
        expect($this->workspace->fresh()->story_motion_settings)->toBeNull();
    } else {
        $response->assertSessionHasNoErrors();
        expect($this->workspace->fresh()->story_motion_settings['ai_notice']['text'])->toBe($text);
    }
})->with([
    'Arabic' => ["\u{635}\u{648}\u{631}\u{629} \u{623}\u{64F}\u{646}\u{634}\u{626}\u{62A}", true],
    'only emoji' => ["\u{1F916}\u{2728}", true],
    'only a combining accent' => ["\u{0301}", true],
    'Chinese with a Latin rest' => ["AI\u{751F}\u{6210}\u{753B}\u{50CF}", true],
    'only signs' => ['!!! 123', true],
    'German' => ['KI-generiertes Bild', false],
    'Greek' => ["\u{0395}\u{03B9}\u{03BA}\u{03CC}\u{03BD}\u{03B1} AI", false],
    'Russian' => ["\u{0418}\u{0418}-\u{0438}\u{0437}\u{043E}\u{0431}\u{0440}\u{0430}\u{0436}\u{0435}\u{043D}\u{0438}\u{0435}", false],
    // The script gate permits these; actual per-font completeness is enforced by the render service.
    'Vietnamese uses service fallback' => ['Ảnh do AI tạo', false],
    'polytonic Greek uses service fallback' => ['ἀἁἂ ἄρτος', false],
]);

test('story video settings: a notice text the video cannot draw is rejected even while the notice is off', function () {
    ($this->save)(['ai_notice' => ['enabled' => false, 'text' => "\u{1F916}\u{2728}"]])
        ->assertSessionHasErrors(['ai_notice.text' => __('settings.story_video.errors.notice_text_unsupported')]);

    expect($this->workspace->fresh()->story_motion_settings)->toBeNull();
});

test('story video settings: switching on a stored notice text the video cannot draw is rejected', function () {
    $this->workspace->update(['story_motion_settings' => ['ai_notice' => ['enabled' => false, 'text' => "\u{1F916}\u{2728}"]]]);

    ($this->save)(['ai_notice' => ['enabled' => true]])
        ->assertSessionHasErrors(['ai_notice.text' => __('settings.story_video.errors.notice_text_unsupported')]);

    expect($this->workspace->fresh()->story_motion_settings['ai_notice']['enabled'])->toBeFalse();
});

test('story video settings: a notice switched on with an empty text gets the default text', function () {
    ($this->save)(['ai_notice' => ['enabled' => true, 'text' => '']])->assertSessionHasNoErrors();

    expect($this->workspace->fresh()->story_motion_settings['ai_notice'])->toBe(['enabled' => true, 'text' => 'AI-generated image']);
});

test('story video settings: the ending takes a name or a line but no phone number, address contact or link', function (string $text, bool $rejected) {
    ($this->save)(['ending' => ['enabled' => true, 'name' => $text]]);

    expect($this->workspace->fresh()->story_motion_settings === null)->toBe($rejected);
})->with([
    'a plain shop name' => ['PlayCraft Toys', false],
    'a name with a year' => ['Since 2024 Paphos', false],
    'six digits in a row' => ['Call 123456', true],
    'a number with spaces' => ['Call 357 99 123 456', true],
    'a number with separators' => ['99-123-456', true],
    'a plus sign and a digit' => ['Call +357', true],
    'a tel link' => ['tel:call me', true],
    'five digits are fine' => ['Shop 12345', false],
    'a pair of years' => ['Seit 1998 2026', false],
    'a pair of years without a word' => ['1998 2026', false],
    'three years' => ['1999 2000 2010', false],
    'years in a phone-like run' => ['1998 2026 123', true],
    'a phone number that starts with a year-like group' => ['2026 99 123 456', true],
    'a phone number with slashes' => ['99/123/456', true],
    'a phone number with a bracketed area code' => ['(0172) 7935396', true],
    'two groups that are no years' => ['1899 2100', true],
]);

test('story video settings: unknown keys are not stored', function () {
    ($this->save)(['enabled' => true, 'pwned' => 'x', 'ai_notice' => ['enabled' => false, 'evil' => '<b>']]);

    expect(array_keys($this->workspace->fresh()->story_motion_settings))->toBe(['enabled', 'font', 'effects', 'mode', 'logo_layer', 'ai_notice', 'ending'])
        ->and(array_keys($this->workspace->fresh()->story_motion_settings['ai_notice']))->toBe(['enabled', 'text']);
});

test('story video settings: the brand settings form is untouched and does not write the Story video column', function () {
    ($this->save)(['enabled' => true]);

    $this->actingAs($this->user)->from(route('app.workspace.brand'))->put(route('app.workspace.settings.update'), [
        'name' => 'Renamed',
        'brand_font' => 'Inter',
        'image_style' => 'cinematic',
    ])->assertRedirect(route('app.workspace.brand'));

    expect($this->workspace->fresh()->story_motion_settings['enabled'])->toBeTrue();
});

test('story video settings: the page props carry the workspace-aware flag for the post editor', function () {
    config()->set('trypost.platforms.facebook.story_motion', ['enabled' => true, 'url' => 'http://story-render:3000', 'token' => 'secret', 'timeout_seconds' => 255]);

    $this->actingAs($this->user)->get(route('app.workspace.brand'))->assertInertia(fn ($page) => $page
        ->missing('storyMotionAvailable')
        ->where('storyMotion.available', true)
        ->where('facebookStoryMotionEnabled', false));

    ($this->save)(['enabled' => true]);

    $this->actingAs($this->user)->get(route('app.workspace.brand'))->assertInertia(fn ($page) => $page
        ->missing('storyMotionAvailable')
        ->where('storyMotion.available', true)
        ->where('facebookStoryMotionEnabled', true));
});
