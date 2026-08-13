<?php

declare(strict_types=1);

namespace App\Support\Social;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class TikTokPhotoDerivativeCleaner
{
    /**
     * @param  array<string, mixed>|null  $context
     */
    public function cleanup(?array $context, ?string $postPlatformId = null): void
    {
        $paths = data_get($context, 'tiktok_derivative_paths', []);

        if (! is_array($paths)) {
            return;
        }

        $derivativePaths = array_values(array_filter(
            $paths,
            fn (mixed $path): bool => is_string($path)
                && preg_match('/\Asocial-tiktok-photos\/[A-Za-z0-9-]+\.jpg\z/', $path) === 1,
        ));

        if ($derivativePaths === []) {
            return;
        }

        try {
            Storage::delete($derivativePaths);
        } catch (\Throwable $e) {
            Log::warning('Failed to prune TikTok photo derivatives', [
                'post_platform_id' => $postPlatformId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
