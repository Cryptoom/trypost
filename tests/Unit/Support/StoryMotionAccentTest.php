<?php

declare(strict_types=1);

use App\Support\StoryMotionAccent;

test('story motion accent: short, long and alpha hex colours are normalized to upper-case #RRGGBB', function (?string $input, ?string $expected) {
    expect(StoryMotionAccent::normalize($input))->toBe($expected);
})->with([
    '3 digits' => ['#fa0', '#FFAA00'],
    '6 digits' => ['#ef4c1e', '#EF4C1E'],
    '8 digits drop the alpha' => ['#12345678', '#123456'],
    'upper case stays' => ['#FDB31B', '#FDB31B'],
    'no hash' => ['FDB31B', null],
    '5 digits' => ['#12345', null],
    'letters outside hex' => ['#GGGGGG', null],
    'trailing newline' => ["#FDB31B\n", null],
    'a prefix before the hash' => ['xx#FDB31B', null],
    'a prefix of one digit' => ['0#FDB31B', null],
    'a suffix after the colour' => ['#FDB31Bxx', null],
    'a space before the hash' => [' #FDB31B', null],
    'empty' => ['', null],
    'null' => [null, null],
    'a css name' => ['red', null],
]);

test('story motion accent: the contrast formula matches known WCAG pairs', function () {
    expect(StoryMotionAccent::contrast('#FFFFFF', '#000000'))->toBe(21.0)
        ->and(StoryMotionAccent::contrast('#123456', '#123456'))->toBe(1.0)
        // #777777 on white is the classic 4.48:1 that just misses AA for body text
        ->and(round(StoryMotionAccent::contrast('#777777', '#FFFFFF'), 2))->toBe(4.48)
        ->and(round(StoryMotionAccent::contrast('#FDB31B', StoryMotionAccent::LIGHT_BACKGROUND), 1))->toBe(3.3);
});

test('story motion accent: a brand colour must reach 3:1 against the light side of the veil AND against black', function (string $hex, bool $readable) {
    expect(StoryMotionAccent::readable($hex))->toBe($readable);
})->with([
    'gold passes both' => ['#FDB31B', true],
    'white passes both' => ['#FFFFFF', true],
    'light yellow passes both' => ['#FFE066', true],
    'PlayCraft orange fails the light side' => ['#EF4C1E', false],
    'navy fails black' => ['#000080', false],
    'dark teal fails black' => ['#0C6E6D', false],
    'black fails' => ['#000000', false],
    'mid grey #777777 fails the light side' => ['#777777', false],
    'light grey #B0B0B0 is 2.78:1 on the light side, just under 3:1' => ['#B0B0B0', false],
    'light grey #B8B8B8 is 3.04:1 on the light side, just over 3:1' => ['#B8B8B8', true],
]);

test('story motion accent: the chain is brand colour, then gold, then white', function (?string $brand, string $expected) {
    expect(StoryMotionAccent::resolve($brand))->toBe($expected);
})->with([
    'orange falls to gold' => ['#EF4C1E', '#FDB31B'],
    'navy falls to gold' => ['#000080', '#FDB31B'],
    'light yellow stays' => ['#FFE066', '#FFE066'],
    'invalid falls to gold' => ['not a colour', '#FDB31B'],
    'null falls to gold' => [null, '#FDB31B'],
    'a grey just under the limit falls to gold' => ['#B0B0B0', '#FDB31B'],
    'a grey just over the limit stays' => ['#B8B8B8', '#B8B8B8'],
    'short white stays white' => ['#fff', '#FFFFFF'],
    'alpha is dropped before the test' => ['#FFE06680', '#FFE066'],
]);

test('story motion accent: gold itself passes, so the chain never needs the white fallback for a readable gold', function () {
    expect(StoryMotionAccent::readable(StoryMotionAccent::GOLD))->toBeTrue()
        ->and(StoryMotionAccent::readable(StoryMotionAccent::WHITE))->toBeTrue();
});

test('story motion accent: the ending block sits on the lower veil at 0.80 over a white photo, darker than the upper reference, so the check holds there', function () {
    $blend = fn (float $alpha) => sprintf('#%02X%02X%02X', ...array_map(fn (int $veil) => (int) round($alpha * $veil + (1 - $alpha) * 255), [10, 42, 61]));

    expect($blend(0.72))->toBe(StoryMotionAccent::LIGHT_BACKGROUND)
        ->and($blend(0.80))->toBe('#3B5564')
        ->and(StoryMotionAccent::contrast('#FDB31B', $blend(0.80)))->toBeGreaterThan(StoryMotionAccent::contrast('#FDB31B', StoryMotionAccent::LIGHT_BACKGROUND))
        ->and(round(StoryMotionAccent::contrast('#FDB31B', $blend(0.80)), 1))->toBe(4.4);

    foreach (['#FDB31B', '#FFFFFF', '#FFE066', '#B8B8B8'] as $accent) {
        expect(StoryMotionAccent::readable($accent))->toBeTrue()
            ->and(StoryMotionAccent::contrast($accent, $blend(0.80)))->toBeGreaterThanOrEqual(StoryMotionAccent::MIN_CONTRAST);
    }
});
