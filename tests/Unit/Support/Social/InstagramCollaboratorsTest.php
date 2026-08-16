<?php

declare(strict_types=1);

use App\Support\Social\InstagramCollaborators;

test('usernames are accepted with or without at signs', function () {
    expect(InstagramCollaborators::normalize(['@apple']))->toBe(['apple'])
        ->and(InstagramCollaborators::normalize(['apple']))->toBe(['apple'])
        ->and(InstagramCollaborators::normalize(['@@apple@']))->toBe(['apple'])
        ->and(InstagramCollaborators::failures(['@apple'], null))->toBe(['items' => [], 'exceedsMax' => false])
        ->and(InstagramCollaborators::failures(['apple'], null))->toBe(['items' => [], 'exceedsMax' => false]);
});

test('normalize strips at signs, trims, and deduplicates case-insensitively', function () {
    expect(InstagramCollaborators::normalize([' @Host_One ', 'host_one', 'host_two', '', 1]))
        ->toBe(['Host_One', 'host_two']);
});

test('normalize accepts a comma-separated string from the web field', function () {
    expect(InstagramCollaborators::normalize('@Host_One, host_two'))
        ->toBe(['Host_One', 'host_two'])
        ->and(InstagramCollaborators::applyToMeta(['collaborators' => '@Host_One, host_two'])['collaborators_with'])
        ->toBe('@Host_One, @host_two');
});

test('display prefixes usernames for the post preview', function () {
    expect(InstagramCollaborators::display(['Host_One', 'host_two']))->toBe('@Host_One, @host_two')
        ->and(InstagramCollaborators::display([]))->toBe('');
});

test('applyToMeta stores the preview line next to the usernames', function () {
    expect(InstagramCollaborators::applyToMeta(['collaborators' => ['@Host_One', 'host_two'], 'aspect_ratio' => '4:5']))
        ->toMatchArray([
            'collaborators' => ['Host_One', 'host_two'],
            'collaborators_with' => '@Host_One, @host_two',
            'aspect_ratio' => '4:5',
        ]);
});

test('normalize caps at three usernames', function () {
    expect(InstagramCollaborators::normalize(['a', 'b', 'c', 'd']))->toBe(['a', 'b', 'c']);
});

test('payload encodes a json array string', function () {
    expect(InstagramCollaborators::payload(['@a', 'b']))->toBe([
        'collaborators' => '["a","b"]',
    ]);
});

test('payload is empty when there are no usernames', function () {
    expect(InstagramCollaborators::payload([]))->toBe([]);
});

test('isSameUsername ignores at signs and case', function () {
    expect(InstagramCollaborators::isSameUsername('@TestUser', 'testuser'))->toBeTrue()
        ->and(InstagramCollaborators::isSameUsername('host_one', 'host_two'))->toBeFalse()
        ->and(InstagramCollaborators::isSameUsername('host_one', null))->toBeFalse();
});

test('payload omits the connected account username', function () {
    expect(InstagramCollaborators::payload(['@TestUser', 'host_one'], 'testuser'))->toBe([
        'collaborators' => '["host_one"]',
    ]);
});

test('failures flags invalid, duplicate, self, and max', function () {
    expect(InstagramCollaborators::failures(['.user', 'a', 'A', 'b', 'c', 'd'], 'testuser'))
        ->toBe([
            'items' => [
                0 => 'invalid',
                2 => 'duplicate',
            ],
            'exceedsMax' => true,
        ])
        ->and(InstagramCollaborators::failures(['@TestUser'], 'testuser'))
        ->toBe([
            'items' => [0 => 'self'],
            'exceedsMax' => false,
        ]);
});

test('isValidUsername rejects leading, trailing, and consecutive periods', function (string $username, bool $valid) {
    expect(InstagramCollaborators::isValidUsername($username))->toBe($valid);
})->with([
    'plain' => ['host_one', true],
    'at prefix' => ['@Host.One', true],
    'underscore edges' => ['_user_', true],
    'leading period' => ['.user', false],
    'trailing period' => ['user.', false],
    'consecutive periods' => ['user..name', false],
    'at leading period' => ['@.user', false],
    'empty' => ['', false],
]);

test('normalize drops graph-invalid usernames', function () {
    expect(InstagramCollaborators::normalize(['.user', 'host_one', 'user.', 'user..name']))
        ->toBe(['host_one']);
});

test('collaborator copy treats the field as optional', function () {
    expect(__('posts.form.instagram.collaborators_hint'))
        ->toContain('Optional')
        ->toContain('must accept');
});
