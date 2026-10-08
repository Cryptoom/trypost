<?php

declare(strict_types=1);

// PATCH:tiktok-disclosure-label
test('ttl01 english commercial content toggle uses the official TikTok wording', function () {
    expect(trans('posts.form.tiktok.disclose', [], 'en'))
        ->toBe('Indicate whether this content promotes yourself, a brand, product or service.');
});

test('ttl01 every locale translates the commercial content toggle label', function () {
    $english = trans('posts.form.tiktok.disclose', [], 'en');

    foreach (glob(base_path('lang/*'), GLOB_ONLYDIR) as $dir) {
        $locale = basename($dir);

        if ($locale === 'en') {
            continue;
        }

        expect(trans('posts.form.tiktok.disclose', [], $locale))->not->toBe($english);
    }
});
