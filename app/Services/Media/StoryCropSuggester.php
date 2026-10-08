<?php

declare(strict_types=1);

namespace App\Services\Media;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use RuntimeException;
use Throwable;

/**
 * PATCH:story-photo-fit
 *
 * Asks a vision-capable Gemini model for the best 9:16 crop of a story photo.
 * Returns a normalized rectangle {x, y, w, h} (0..1, relative to the EXIF
 * oriented image) or null. It never throws: a missing key, an API or network
 * error, an unparsable answer or a rectangle with the wrong aspect ratio all
 * yield null so the caller falls back to a center crop.
 *
 * Valid answers are cached per image content, so a retried publish does not
 * pay for the same photo twice.
 */
class StoryCropSuggester
{
    private const GENERATE_CONTENT_PATH = 'models/%s:generateContent';

    private const DEFAULT_TEXT_MODEL = 'gemini-3.6-flash';

    private const TARGET_RATIO = 9 / 16;

    private const REQUEST_IMAGE_WIDTH = 768;

    private const REQUEST_TIMEOUT_SECONDS = 20;

    private const CACHE_TTL_DAYS = 7;

    /**
     * @return array{x: float, y: float, w: float, h: float}|null
     */
    public function suggest(string $imagePath): ?array
    {
        if (blank(config('services.gemini.api_key'))) {
            return null;
        }

        try {
            $cacheKey = 'story-crop:v1:'.sha1_file($imagePath);
            $cached = Cache::get($cacheKey);

            if (is_array($cached)) {
                return $cached;
            }

            $rect = $this->requestRect($imagePath);

            if ($rect !== null) {
                Cache::put($cacheKey, $rect, now()->addDays(self::CACHE_TTL_DAYS));
            }

            return $rect;
        } catch (Throwable $e) {
            Log::warning('Story crop suggestion failed, falling back to center crop', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return array{x: float, y: float, w: float, h: float}|null
     */
    private function requestRect(string $imagePath): ?array
    {
        // A small file with huge pixel dimensions would exhaust GD memory with an uncatchable fatal.
        app(MediaOptimizer::class)->assertWithinMemoryBudget($imagePath);

        $image = (new ImageManager(Driver::class))->decodePath($imagePath);
        $width = $image->width();
        $height = $image->height();
        $encoded = base64_encode((string) $image->scaleDown(width: self::REQUEST_IMAGE_WIDTH)->encodeUsingMediaType('image/jpeg', quality: 85));

        $model = (string) (config('ai.providers.gemini.models.text.default') ?: self::DEFAULT_TEXT_MODEL);

        $prompt = view('prompts.story_crop.suggest', [
            'width' => $width,
            'height' => $height,
        ])->render();

        $response = $this->gemini()->post(sprintf(self::GENERATE_CONTENT_PATH, $model), [
            'contents' => [[
                'parts' => [
                    ['text' => $prompt],
                    ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => $encoded]],
                ],
            ]],
            'generationConfig' => ['responseMimeType' => 'application/json'],
        ]);

        if ($response->failed()) {
            throw new RuntimeException("Gemini crop suggestion request failed with status {$response->status()}");
        }

        $decoded = json_decode(trim((string) data_get($response->json(), 'candidates.0.content.parts.0.text', '')), true);

        return $this->validatedRect($decoded, $width, $height);
    }

    /**
     * @return array{x: float, y: float, w: float, h: float}|null
     */
    private function validatedRect(mixed $candidate, int $width, int $height): ?array
    {
        if (! is_array($candidate)) {
            return null;
        }

        $values = [];

        foreach (['x', 'y', 'w', 'h'] as $key) {
            $value = data_get($candidate, $key);

            if (! is_int($value) && ! is_float($value)) {
                return null;
            }

            $values[$key] = (float) $value;
        }

        ['x' => $x, 'y' => $y, 'w' => $w, 'h' => $h] = $values;

        if ($x < 0 || $y < 0 || $w <= 0 || $h <= 0 || $x + $w > 1.0001 || $y + $h > 1.0001) {
            return null;
        }

        $ratio = ($w * $width) / ($h * $height);

        if (abs($ratio / self::TARGET_RATIO - 1) > MediaOptimizer::RECT_RATIO_TOLERANCE) {
            return null;
        }

        return $values;
    }

    private function gemini(): PendingRequest
    {
        $baseUrl = rtrim((string) config('ai.providers.gemini.url', 'https://generativelanguage.googleapis.com/v1beta/'), '/');

        return Http::baseUrl($baseUrl)
            ->withHeaders(['x-goog-api-key' => config('services.gemini.api_key')])
            ->timeout(self::REQUEST_TIMEOUT_SECONDS);
    }
}
