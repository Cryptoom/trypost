<?php

declare(strict_types=1);

use App\Support\Billing\CashierCheckoutEnv;

test('empty or null trial days fall back to the default', function (mixed $value) {
    expect(CashierCheckoutEnv::trialDays($value))->toBe(8);
})->with([
    'null' => null,
    'empty string' => '',
]);

test('numeric trial days are parsed and floored at zero', function (mixed $value, int $expected) {
    expect(CashierCheckoutEnv::trialDays($value))->toBe($expected);
})->with([
    'eight' => ['8', 8],
    'zero' => ['0', 0],
    'integer' => [8, 8],
    'negative' => ['-3', 0],
]);

test('empty or null allow_promotion_codes fall back to the default', function (mixed $value) {
    expect(CashierCheckoutEnv::allowPromotionCodes($value))->toBeTrue();
})->with([
    'null' => null,
    'empty string' => '',
]);

test('allow_promotion_codes parses boolean-ish env strings', function (mixed $value, bool $expected) {
    expect(CashierCheckoutEnv::allowPromotionCodes($value))->toBe($expected);
})->with([
    'true string' => ['true', true],
    'false string' => ['false', false],
    'one' => ['1', true],
    'zero' => ['0', false],
    'bool true' => [true, true],
    'bool false' => [false, false],
]);
