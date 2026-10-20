<?php

declare(strict_types=1);

use App\Support\StoryMotionLogo;

// PATCH:story-motion-settings: the one rule for "this stored logo fits the Story video layer".

test('story motion logo: the limits are the ones the render service takes', function () {
    expect(StoryMotionLogo::MAX_SIDE)->toBe(2000)
        ->and(StoryMotionLogo::MAX_BYTES)->toBe(2 * 1024 * 1024)
        ->and(StoryMotionLogo::MIME_TYPES)->toBe(['image/jpeg', 'image/png']);
});

test('story motion logo: a stored logo is usable only inside every limit', function (mixed $size, mixed $width, mixed $height, mixed $mime, bool $usable) {
    expect(StoryMotionLogo::usable($size, $width, $height, $mime))->toBe($usable);
})->with([
    'jpeg, normal' => [165516, 1200, 458, 'image/jpeg', true],
    'png, normal' => [165516, 1200, 458, 'image/png', true],
    'exactly 2000 wide' => [1000, 2000, 764, 'image/jpeg', true],
    'exactly 2000 high' => [1000, 764, 2000, 'image/jpeg', true],
    'one pixel too wide' => [1000, 2001, 764, 'image/jpeg', false],
    'one pixel too high' => [1000, 764, 2001, 'image/jpeg', false],
    'the PlayCraft original 4000 x 1528' => [647145, 4000, 1528, 'image/jpeg', false],
    'exactly 2 MB' => [2 * 1024 * 1024, 1000, 1000, 'image/jpeg', true],
    'one byte over 2 MB' => [2 * 1024 * 1024 + 1, 1000, 1000, 'image/jpeg', false],
    'empty file' => [0, 1000, 1000, 'image/jpeg', false],
    'gif' => [1000, 100, 100, 'image/gif', false],
    'ico' => [1000, 32, 32, 'image/x-icon', false],
    'webp' => [1000, 100, 100, 'image/webp', false],
    'no mime type' => [1000, 100, 100, null, false],
    'no dimensions stored' => [1000, null, null, 'image/jpeg', false],
    'zero width' => [1000, 0, 100, 'image/jpeg', false],
    'dimensions as strings from a JSON column' => ['1000', '1200', '458', 'image/png', true],
]);
