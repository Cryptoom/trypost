<?php

declare(strict_types=1);

use App\Enums\Workspace\StoryMotionEffect;
use App\Services\Media\StoryMotionSettings;
use App\Services\Media\StoryMotionSpec;

function sm02Spec(array $settings = [], array $texts = [], StoryMotionEffect $effect = StoryMotionEffect::Focus, string $accent = '#FDB31B', int $duration = 15, bool $logoLayer = false): array
{
    return StoryMotionSpec::build(StoryMotionSettings::fromArray(['enabled' => true, ...$settings]), $effect, $texts, $accent, $duration, $logoLayer);
}

test('story motion spec: carries every setting the service reads, under the names the service expects', function () {
    $spec = sm02Spec(
        ['font' => 'noto-serif', 'ai_notice' => ['enabled' => true, 'text' => 'AI-generated image'], 'ending' => ['enabled' => true, 'name' => 'PlayCraft', 'line' => 'Paphos']],
        ['headline' => 'Camping *Peppa*', 'subline' => 'Now in store', 'badge' => '-20%', 'headline_alt' => 'Γεια', 'subline_alt' => 'Υπο'],
        StoryMotionEffect::Snap,
        '#FFE066',
        8,
        true,
    );

    expect($spec)->toBe([
        'duration' => 8,
        'effect' => 'snap',
        'font' => 'noto-serif',
        'accent' => '#FFE066',
        'logoLayer' => true,
        'aiNotice' => 'AI-generated image',
        'headline' => 'Camping *Peppa*',
        'subline' => 'Now in store',
        'badge' => '-20%',
        'headlineAlt' => 'Γεια',
        'sublineAlt' => 'Υπο',
        'ending' => ['name' => 'PlayCraft', 'line' => 'Paphos'],
    ]);
});

test('story motion spec: empty, blank and non-string texts become null so the service leaves the element out', function (mixed $value) {
    $spec = sm02Spec([], ['headline' => $value, 'subline' => $value, 'badge' => $value, 'headline_alt' => $value, 'subline_alt' => $value]);

    expect($spec['headline'])->toBeNull()
        ->and($spec['subline'])->toBeNull()
        ->and($spec['badge'])->toBeNull()
        ->and($spec['headlineAlt'])->toBeNull()
        ->and($spec['sublineAlt'])->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    'blank' => ['   '],
    'number' => [12],
    'array' => [['x']],
]);

test('story motion spec: texts are trimmed but otherwise passed on unchanged for the service to sanitize', function () {
    $bait = '</script><img src=x onerror=alert(1)> "};alert(1);// *x* \u{2028}';

    expect(sm02Spec([], ['headline' => "  {$bait}  "])['headline'])->toBe($bait);
});

test('story motion spec: no notice and no ending unless they are on and filled', function () {
    $spec = sm02Spec(['ai_notice' => ['enabled' => false, 'text' => 'x'], 'ending' => ['enabled' => true, 'name' => '', 'line' => '']]);

    expect($spec['aiNotice'])->toBeNull()->and($spec['ending'])->toBeNull();
});

test('story motion spec: a clip length outside 3 to 20 seconds cannot be sent', function (int $seconds, bool $valid) {
    if ($valid) {
        expect(sm02Spec(duration: $seconds)['duration'])->toBe($seconds);

        return;
    }

    expect(fn () => sm02Spec(duration: $seconds))->toThrow(InvalidArgumentException::class);
})->with([[2, false], [3, true], [8, true], [15, true], [20, true], [21, false], [0, false], [-5, false]]);

test('story motion spec: the accent colour must be an upper-case #RRGGBB, the service checks it again', function (string $accent) {
    expect(fn () => sm02Spec(accent: $accent))->toThrow(InvalidArgumentException::class);
})->with(['#fdb31b', '#FDB31', '#FDB31B0', 'red', '', '#FDB31B"};alert(1);//', "#FDB31B\n"]);

test('story motion spec: the effect name is the enum value, so only the four known effects exist', function () {
    foreach (StoryMotionEffect::cases() as $effect) {
        expect(sm02Spec(effect: $effect)['effect'])->toBe($effect->value);
    }

    expect(array_map(fn (StoryMotionEffect $effect) => $effect->value, StoryMotionEffect::cases()))->toBe(['drift', 'focus', 'snap', 'sparkle']);
});

test('story motion spec: is pure, it needs no database row and the same input gives the same output', function () {
    expect(sm02Spec(['font' => 'commissioner'], ['headline' => 'Hi']))->toBe(sm02Spec(['font' => 'commissioner'], ['headline' => 'Hi']));
});
