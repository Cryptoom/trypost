<?php

declare(strict_types=1);

use App\Support\StoryMotionSettingsRules;

// PATCH:story-motion-settings: the ending takes a name and a line, not a phone number.

test('story motion rules: year pairs and short numbers pass, phone numbers do not', function (string $text, bool $contact) {
    expect(StoryMotionSettingsRules::looksLikeContactDetails($text))->toBe($contact);
})->with([
    'Seit 1998 2026' => ['Seit 1998 2026', false],
    'two years joined by a dash' => ['1998-2026', false],
    'two years joined by a slash' => ['1998/2026', false],
    'years split by words' => ['Seit 1998 bis 2026', false],
    'years around a dot' => ['Seit 1998 · 2026', false],
    'a year in a line' => ['Seit 2024 in Paphos', false],
    'a house number' => ['Makarios 12', false],
    'opening hours' => ['Mo 09 18', false],
    'five digits' => ['12345', false],
    'six digits in a row' => ['123456', true],
    'a Cyprus number with spaces' => ['99 123 456', true],
    'a German number' => ['0172 7935396', true],
    'a number in brackets' => ['(0172) 7935396', true],
    'a number with a plus' => ['+357 99 123 456', true],
    'a plus and one digit' => ['Call +3', true],
    'a tel link' => ['TEL:123', true],
    'a year next to more digits' => ['1998 2026 12', true],
    'a year-like group at the start of a number' => ['2026 99 123 456', true],
    'single digits' => ['1 2 3 4 5 6', true],
    'two groups outside 1900 to 2099' => ['1899 2100', true],
    'a number with dots' => ['99.123.456', true],
    'a German number with dots' => ['0172.7935396', true],
    'two groups of the 21st century' => ['2100 2101', true],
    'two groups of the 19th century' => ['1800 1899', true],
    'the first and the last year that pass' => ['1900 2099', false],
    'groups of three digits are no years' => ['199 202', true],
    'a number with commas' => ['99,123,456', true],
    'a number with underscores' => ['99_123_456', true],
    'a number with middle dots' => ["99\u{00B7}123\u{00B7}456", true],
    'a number with en dashes' => ["99\u{2013}123\u{2013}456", true],
    'a number with em dashes' => ["99\u{2014}123\u{2014}456", true],
    'a number with colons between long groups' => ['123:456:789', true],
    'a number that ends in two colon groups of three' => ['99:123:456', true],
    'opening hours with colons' => ['10:00 - 19:00', false],
    'opening hours with an en dash' => ["10:00\u{2013}19:00", false],
    'short opening hours' => ['Open 9-18', false],
    'a postcode' => ['Paphos 8010', false],
    'a time with two-digit groups' => ['99:12:34', false],
    'years with a comma' => ['Seit 1998, 2026', false],
    'years with an en dash' => ["1998\u{2013}2026", false],
    'around the clock' => ['24/7 4 you', false],
]);
