<?php

declare(strict_types=1);

use App\Enums\Workspace\StoryMotionEffect;
use App\Enums\Workspace\StoryMotionFont;
use App\Enums\Workspace\StoryMotionMode;
use App\Models\Workspace;
use App\Services\Media\StoryMotionSettings;

/**
 * PATCH:story-motion-settings: the settings object reads a JSON column that may hold anything, and must
 * never throw. The default of "enabled" is off.
 */
test('story motion settings: NULL and an empty array give the defaults, which are off', function (?array $stored) {
    $settings = StoryMotionSettings::fromArray($stored);

    expect($settings->enabled)->toBeFalse()
        ->and($settings->font)->toBe(StoryMotionFont::Inter)
        ->and($settings->effects)->toBe([StoryMotionEffect::Focus])
        ->and($settings->mode)->toBe(StoryMotionMode::Fixed)
        ->and($settings->logoLayer)->toBeFalse()
        ->and($settings->aiNoticeEnabled)->toBeFalse()
        ->and($settings->aiNoticeText)->toBe('AI-generated image')
        ->and($settings->endingEnabled)->toBeFalse()
        ->and($settings->aiNotice())->toBeNull()
        ->and($settings->ending())->toBeNull();
})->with([
    'null' => [null],
    'empty array' => [[]],
]);

test('story motion settings: garbage never throws and every bad value falls back on its own default', function (array $stored, string $key, mixed $expected) {
    $settings = StoryMotionSettings::fromArray($stored);

    expect(data_get($settings->toArray(), $key))->toBe($expected);
})->with([
    'enabled as a string' => [['enabled' => 'true'], 'enabled', false],
    'enabled as a number' => [['enabled' => 1], 'enabled', false],
    'enabled missing, rest valid' => [['font' => 'noto-serif'], 'enabled', false],
    'enabled true is kept' => [['enabled' => true], 'enabled', true],
    'unknown font' => [['font' => 'comic-sans'], 'font', 'inter'],
    'font of the wrong type' => [['font' => ['inter']], 'font', 'inter'],
    'a valid font is kept' => [['font' => 'comfortaa'], 'font', 'comfortaa'],
    'unknown mode' => [['mode' => 'shuffle'], 'mode', 'fixed'],
    'mode of the wrong type' => [['mode' => 7], 'mode', 'fixed'],
    'valid mode' => [['mode' => 'random'], 'mode', 'random'],
    'unknown effect only' => [['effects' => ['wobble']], 'effects', ['focus']],
    'effects as a string' => [['effects' => 'snap'], 'effects', ['focus']],
    'effects empty' => [['effects' => []], 'effects', ['focus']],
    'effects with junk and one valid' => [['effects' => ['wobble', 7, null, 'snap']], 'effects', ['snap']],
    'logo layer of the wrong type' => [['logo_layer' => 'yes'], 'logo_layer', false],
    'notice section of the wrong type' => [['ai_notice' => 'on'], 'ai_notice.enabled', false],
    'notice text too long' => [['ai_notice' => ['text' => str_repeat('x', 41)]], 'ai_notice.text', 'AI-generated image'],
    'notice text of the wrong type' => [['ai_notice' => ['text' => ['x']]], 'ai_notice.text', 'AI-generated image'],
    'ending name too long' => [['ending' => ['name' => str_repeat('x', 41)]], 'ending.name', ''],
    'ending line too long' => [['ending' => ['line' => str_repeat('x', 61)]], 'ending.line', ''],
    'ending section a list' => [['ending' => ['a', 'b']], 'ending.enabled', false],
    'nested values in a flat key' => [['enabled' => ['x' => 1]], 'enabled', false],
    'a list instead of an object' => [['a', 'b', 'c'], 'enabled', false],
    'an unknown key is ignored' => [['pwned' => true, 'enabled' => true], 'enabled', true],
]);

test('story motion settings: selected effects come back in the canonical order without duplicates', function () {
    $settings = StoryMotionSettings::fromArray(['effects' => ['sparkle', 'focus', 'drift', 'sparkle', 'snap']]);

    expect($settings->effects)->toBe([StoryMotionEffect::Drift, StoryMotionEffect::Focus, StoryMotionEffect::Snap, StoryMotionEffect::Sparkle])
        ->and(StoryMotionEffect::values())->toBe(['drift', 'focus', 'snap', 'sparkle']);
});

test('story motion settings: the default font follows the brand font when the render service has it, else Inter', function (?string $brandFont, string $expected) {
    expect(StoryMotionSettings::defaults($brandFont)->font->value)->toBe($expected)
        ->and(StoryMotionSettings::fromArray(null, $brandFont)->font->value)->toBe($expected)
        ->and(StoryMotionSettings::fromArray(['font' => 'comfortaa'], $brandFont)->font->value)->toBe('comfortaa', 'a stored font wins over the brand font');
})->with([
    'Noto Sans' => ['Noto Sans', 'noto-sans'],
    'Noto Serif' => ['Noto Serif', 'noto-serif'],
    'Inter' => ['Inter', 'inter'],
    'a font the service does not ship' => ['Roboto', 'inter'],
    'no brand font' => [null, 'inter'],
]);

test('story motion settings: a workspace with NULL, a list or text in the column reads as the defaults', function (mixed $stored) {
    $workspace = Workspace::factory()->create(['brand_font' => 'Noto Serif']);
    $workspace->forceFill(['story_motion_settings' => $stored])->saveQuietly();

    $settings = StoryMotionSettings::forWorkspace($workspace->fresh());

    expect($settings->enabled)->toBeFalse()->and($settings->font)->toBe(StoryMotionFont::NotoSerif);
})->with([
    'null' => [null],
    'empty' => [[]],
    'list' => [['a']],
]);

test('story motion settings: toArray round-trips through fromArray', function () {
    $settings = new StoryMotionSettings(true, StoryMotionFont::Comfortaa, [StoryMotionEffect::Snap, StoryMotionEffect::Sparkle], StoryMotionMode::Rotate, true, true, 'KI', true, 'Name', 'Zeile');

    expect(StoryMotionSettings::fromArray($settings->toArray())->toArray())->toBe($settings->toArray())
        ->and($settings->toArray())->toBe([
            'enabled' => true,
            'font' => 'comfortaa',
            'effects' => ['snap', 'sparkle'],
            'mode' => 'rotate',
            'logo_layer' => true,
            'ai_notice' => ['enabled' => true, 'text' => 'KI'],
            'ending' => ['enabled' => true, 'name' => 'Name', 'line' => 'Zeile'],
        ]);
});

test('story motion settings: with() merges only the sent keys, sections key by key', function () {
    $settings = StoryMotionSettings::fromArray(['enabled' => true, 'font' => 'inter', 'ai_notice' => ['enabled' => true, 'text' => 'Mein Hinweis'], 'ending' => ['enabled' => true, 'name' => 'A', 'line' => 'B']]);

    $changed = $settings->with(['font' => 'noto-sans', 'ai_notice' => ['text' => 'Neuer Hinweis'], 'ending' => ['line' => 'C']])->toArray();

    expect($changed['enabled'])->toBeTrue()
        ->and($changed['font'])->toBe('noto-sans')
        ->and($changed['ai_notice'])->toBe(['enabled' => true, 'text' => 'Neuer Hinweis'])
        ->and($changed['ending'])->toBe(['enabled' => true, 'name' => 'A', 'line' => 'C'])
        ->and($settings->font)->toBe(StoryMotionFont::Inter, 'the original is not changed');
});

test('story motion settings: with() replaces the effects list as a whole', function () {
    $settings = StoryMotionSettings::fromArray(['effects' => ['drift', 'focus']]);

    expect($settings->with(['effects' => ['sparkle']])->toArray()['effects'])->toBe(['sparkle']);
});

test('story motion settings: the notice is shown only when on and not blank, the ending only when on and not empty', function () {
    expect(StoryMotionSettings::fromArray(['ai_notice' => ['enabled' => true, 'text' => '  Hinweis  ']])->aiNotice())->toBe('Hinweis')
        ->and(StoryMotionSettings::fromArray(['ai_notice' => ['enabled' => true, 'text' => '   ']])->aiNotice())->toBeNull()
        ->and(StoryMotionSettings::fromArray(['ai_notice' => ['enabled' => false, 'text' => 'Hinweis']])->aiNotice())->toBeNull()
        ->and(StoryMotionSettings::fromArray(['ending' => ['enabled' => true, 'name' => 'Name', 'line' => '']])->ending())->toBe(['name' => 'Name', 'line' => null])
        ->and(StoryMotionSettings::fromArray(['ending' => ['enabled' => true, 'name' => '', 'line' => 'Zeile']])->ending())->toBe(['name' => null, 'line' => 'Zeile'])
        ->and(StoryMotionSettings::fromArray(['ending' => ['enabled' => true, 'name' => ' ', 'line' => ' ']])->ending())->toBeNull()
        ->and(StoryMotionSettings::fromArray(['ending' => ['enabled' => false, 'name' => 'Name', 'line' => 'Zeile']])->ending())->toBeNull();
});

test('story motion settings: the default notice is the placeholder of the form in the current locale', function (string $locale, string $expected) {
    app()->setLocale($locale);

    expect(StoryMotionSettings::defaults()->aiNoticeText)->toBe($expected)
        ->and(StoryMotionSettings::fromArray(['ai_notice' => ['enabled' => true]])->aiNoticeText)->toBe($expected)
        ->and(StoryMotionSettings::fromArray(['ai_notice' => ['enabled' => true, 'text' => 'Mein Hinweis']])->aiNoticeText)->toBe('Mein Hinweis');
})->with([
    'English' => ['en', 'AI-generated image'],
    'German' => ['de', 'KI-generiertes Bild'],
    'Greek' => ['el', 'Εικόνα που δημιουργήθηκε με AI'],
    'Russian' => ['ru', 'Изображение создано ИИ'],
    'Turkish' => ['tr', 'Yapay zekâ ile üretilmiş görsel'],
    'Arabic falls back, no font draws it' => ['ar', 'AI-generated image'],
    'Japanese falls back' => ['ja', 'AI-generated image'],
    'Korean falls back' => ['ko', 'AI-generated image'],
    'Chinese falls back' => ['zh', 'AI-generated image'],
]);

test('story motion settings: in every locale the default notice fits the limit and is what the form suggests, or the English text', function () {
    foreach (array_map('basename', glob(lang_path('*'), GLOB_ONLYDIR)) as $locale) {
        app()->setLocale($locale);
        $placeholder = __('settings.story_video.ai_notice_placeholder');
        $default = StoryMotionSettings::defaultAiNotice();

        expect(mb_strlen($default))->toBeLessThanOrEqual(StoryMotionSettings::AI_NOTICE_MAX)
            ->and(in_array($default, [$placeholder, StoryMotionSettings::DEFAULT_AI_NOTICE], true))->toBeTrue("locale {$locale}");
    }
});

test('story motion settings: switching the notice on without a text stores the default of the user locale', function () {
    app()->setLocale('de');
    $workspace = Workspace::factory()->create();

    $workspace->update(['story_motion_settings' => StoryMotionSettings::forWorkspace($workspace)->with(['ai_notice' => ['enabled' => true]])->toArray()]);

    expect($workspace->fresh()->story_motion_settings['ai_notice'])->toBe(['enabled' => true, 'text' => 'KI-generiertes Bild']);
});

test('story motion settings: a translated placeholder over 40 characters is not used as the default notice', function () {
    app()->setLocale('en');
    app('translator')->addLines(['settings.story_video.ai_notice_placeholder' => str_repeat('x', 41)], 'en');

    expect(StoryMotionSettings::defaultAiNotice())->toBe('AI-generated image');

    app('translator')->addLines(['settings.story_video.ai_notice_placeholder' => str_repeat('x', 40)], 'en');

    expect(StoryMotionSettings::defaultAiNotice())->toBe(str_repeat('x', 40));
});
