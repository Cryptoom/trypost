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
    'zero width space in a number' => ["99\u{200B}123\u{200B}456", true],
    'soft hyphen in a number' => ["99\u{00AD}123\u{00AD}456", true],
    'word joiner in a number' => ["99\u{2060}123\u{2060}456", true],
    'invisible characters between years' => ["Seit 1998\u{200B} 2026", false],
    'invisible character in opening hours' => ["10:00\u{2060} - 19:00", false],
    'age list' => ['Ages 3, 5, 7, 9', false],
    'year followed by around the clock service (ambiguous)' => ['Seit 2012 · 24/7 Service', true],
    'age groups (ambiguous)' => ['Ab 10, 20, 30 Jahre', true],
    'quantity formatted as a phone number (ambiguous)' => ['1,200,000 toys', true],
]);

// TPS-SM-03 (review N2): the renderer removes every Cc character too, so a control character between digits must not hide a number.
test('story motion rules: control characters between digits do not split a phone number, in both directions', function (string $text, bool $contact) {
    expect(StoryMotionSettingsRules::looksLikeContactDetails($text))->toBe($contact);
})->with([
    'DEL between digit groups' => ["99\u{007F}123\u{007F}456", true],
    'U+0080 between digit groups' => ["99\u{0080}123\u{0080}456", true],
    'DEL inside a plain run' => ["12\u{007F}34\u{007F}56", true],
    'U+0080 inside a plain run' => ["12\u{0080}34\u{0080}56", true],
    'U+009F between digit groups' => ["99\u{009F}123\u{009F}456", true],
    'NUL between digit groups' => ["99\u{0000}123\u{0000}456", true],
    'vertical tab between digit groups' => ["99\u{000B}123\u{000B}456", true],
    'control and format characters mixed' => ["99\u{007F}\u{200B}123\u{0080}456", true],
    'a plus with DEL before the digit' => ["+\u{007F}357", true],
    'tel with DEL inside' => ["te\u{007F}l:123", true],
    'DEL between years' => ["Seit 1998\u{007F} 2026", false],
    'U+0080 between years' => ["Seit 1998\u{0080} 2026", false],
    'DEL inside opening hours' => ["10:00\u{007F} - 19:00", false],
    'U+0080 inside a short number' => ["12\u{0080}345", false],
    'DEL inside a house number' => ["Makarios 1\u{007F}2", false],
    'DEL between two years joins them into one number' => ["1998\u{007F}2026", true],
    'U+0080 between two years joins them into one number' => ["1998\u{0080}2026", true],
    'a tab between two years is a space, as in the renderer' => ["Seit 1998\t2026", false],
    'a line feed between two years is a space, as in the renderer' => ["Seit 1998\n2026", false],
    'a carriage return between two years is a space, as in the renderer' => ["Seit 1998\r2026", false],
    'a tab is whitespace and keeps the groups apart' => ["99\t123", false],
    'a line feed is whitespace and keeps the groups apart' => ["99\n123", false],
    'a tab between long groups still counts' => ["123\t456", true],
]);
