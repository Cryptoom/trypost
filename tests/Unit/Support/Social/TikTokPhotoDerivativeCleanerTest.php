<?php

declare(strict_types=1);

use App\Support\Social\TikTokPhotoDerivativeCleaner;
use Illuminate\Support\Facades\Storage;

test('it deletes only managed TikTok photo derivatives', function () {
    Storage::fake();

    $managedPath = 'social-tiktok-photos/123e4567-e89b-12d3-a456-426614174000.jpg';
    $unmanagedPaths = [
        'customer-media/123e4567-e89b-12d3-a456-426614174000.jpg',
        'social-tiktok-photos/nested/123e4567-e89b-12d3-a456-426614174000.jpg',
        'social-tiktok-photos/../customer-media/123e4567-e89b-12d3-a456-426614174000.jpg',
        'social-tiktok-photos/not-a-uuid.jpg',
        'social-tiktok-photos/123e4567-e89b-12d3-a456-426614174000.png',
    ];

    Storage::put($managedPath, 'managed');
    foreach ($unmanagedPaths as $path) {
        Storage::put($path, 'keep');
    }

    app(TikTokPhotoDerivativeCleaner::class)->cleanup([
        'tiktok_derivative_paths' => [$managedPath, ...$unmanagedPaths, null, 123],
    ]);

    Storage::assertMissing($managedPath);
    Storage::assertExists($unmanagedPaths);
});

test('it ignores invalid retry context', function () {
    Storage::fake();

    $cleaner = app(TikTokPhotoDerivativeCleaner::class);

    $cleaner->cleanup(null);
    $cleaner->cleanup([]);
    $cleaner->cleanup(['tiktok_derivative_paths' => 'invalid']);

    Storage::assertDirectoryEmpty('/');
});
