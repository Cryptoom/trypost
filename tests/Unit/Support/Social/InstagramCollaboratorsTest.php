<?php

declare(strict_types=1);

use App\Support\Social\InstagramCollaborators;

test('normalize strips at signs, trims, and deduplicates case-insensitively', function () {
    expect(InstagramCollaborators::normalize([' @Host_One ', 'host_one', 'host_two', '', 1]))
        ->toBe(['Host_One', 'host_two']);
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

test('vue collaborator limits stay in sync with the php constants', function () {
    $vue = file_get_contents(resource_path('js/components/posts/editor/InstagramSettings.vue'));

    expect($vue)->toContain('const MAX_COLLABORATORS = '.InstagramCollaborators::MAX)
        ->and($vue)->toContain(InstagramCollaborators::USERNAME_PATTERN);
});

test('collaborator copy treats the field as optional', function () {
    expect(__('posts.form.instagram.collaborators_hint'))
        ->toContain('Optional')
        ->toContain('must accept');
});
