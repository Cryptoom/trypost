<?php

declare(strict_types=1);

use App\Support\PostPlatformMetaRules;

test('custom meta messages cover pinterest and instagram collaborator fields', function () {
    expect(PostPlatformMetaRules::messages())->toBe([
        'platforms.*.meta.link.url' => __('posts.form.pinterest.link_invalid'),
        'platforms.*.meta.link.max' => __('posts.form.pinterest.link_max'),
        'platforms.*.meta.title.max' => __('posts.form.pinterest.title_max'),
        'platforms.*.meta.collaborators.max' => __('posts.form.instagram.collaborators_max'),
        'platforms.*.meta.collaborators.*.regex' => __('posts.form.instagram.collaborators_invalid'),
        'platforms.*.meta.collaborators.*.distinct' => __('posts.form.instagram.collaborators_invalid'),
    ]);
});

test('custom meta attributes rename pinterest and instagram collaborator fields', function () {
    expect(PostPlatformMetaRules::attributes())->toBe([
        'platforms.*.meta.title' => __('posts.form.pinterest.title'),
        'platforms.*.meta.link' => __('posts.form.pinterest.link'),
        'platforms.*.meta.collaborators' => __('posts.form.instagram.collaborators'),
    ]);
});

test('shared meta rules still include non-pinterest platform fields', function () {
    $rules = PostPlatformMetaRules::rules();

    expect($rules)->toHaveKeys([
        'platforms.*.meta.aspect_ratio',
        'platforms.*.meta.collaborators',
        'platforms.*.meta.collaborators.*',
        'platforms.*.meta.privacy_level',
        'platforms.*.meta.board_id',
        'platforms.*.meta.channel_id',
        'platforms.*.meta.title',
        'platforms.*.meta.link',
    ]);
});
