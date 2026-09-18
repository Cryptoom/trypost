<?php

declare(strict_types=1);

namespace App\Services\Media;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Best-effort AI background music for a Facebook Story image, via Gemini:
 *
 * 1. A vision-capable Gemini text model describes suitable music for the
 *    story image, merged with an optional user-supplied description.
 * 2. Gemini's Lyria model renders a short instrumental piece from that
 *    description.
 *
 * Every failure mode (feature flag off, missing API key, network/API
 * error, malformed response) returns null instead of throwing, a Story
 * publish must never fail because AI music generation failed. Callers
 * fall back to a silent audio track, see ImageToVideoConverter.
 */
class StoryMusicGenerator
{
    private const GENERATE_CONTENT_PATH = 'models/%s:generateContent';

    private const DEFAULT_TEXT_MODEL = 'gemini-3.6-flash';

    private const DEFAULT_MUSIC_MODEL = 'lyria-3-pro-preview';

    public function isEnabled(): bool
    {
        return (bool) config('trypost.platforms.facebook.story_ai_music_enabled')
            && filled(config('services.gemini.api_key'));
    }

    /**
     * Generates a short instrumental piece for the given story image, or
     * null on any failure. `$userDescription` supplements (never replaces)
     * the vision-derived description of the image. Returns the path to a
     * generated temp MP3 file, the caller owns cleanup.
     */
    public function generate(string $imagePath, int $durationSeconds, ?string $userDescription = null): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        try {
            $moodDescription = $this->describeImageMood($imagePath, $userDescription);
            $audioBase64 = $this->generateMusic($moodDescription, $durationSeconds);

            if ($audioBase64 === null) {
                Log::warning('Story AI music generation returned no audio, falling back to silent audio');

                return null;
            }

            $decoded = base64_decode($audioBase64, true);

            if ($decoded === false || $decoded === '') {
                return null;
            }

            $audioPath = sys_get_temp_dir().'/'.Str::uuid().'.mp3';
            file_put_contents($audioPath, $decoded);

            return $audioPath;
        } catch (Throwable $e) {
            Log::warning('Story AI music generation failed, falling back to silent audio', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function describeImageMood(string $imagePath, ?string $userDescription): string
    {
        $model = (string) (config('ai.providers.gemini.models.text.default') ?: self::DEFAULT_TEXT_MODEL);

        $prompt = view('prompts.story_music.describe_image', [
            'userDescription' => $userDescription,
        ])->render();

        $response = $this->gemini()->post(sprintf(self::GENERATE_CONTENT_PATH, $model), [
            'contents' => [[
                'parts' => [
                    ['text' => $prompt],
                    [
                        'inlineData' => [
                            'mimeType' => $this->mimeTypeOf($imagePath),
                            'data' => base64_encode((string) file_get_contents($imagePath)),
                        ],
                    ],
                ],
            ]],
        ]);

        if ($response->failed()) {
            throw new RuntimeException("Gemini image description request failed with status {$response->status()}");
        }

        $description = trim((string) data_get($response->json(), 'candidates.0.content.parts.0.text', ''));

        if ($description === '') {
            throw new RuntimeException('Gemini returned an empty image description');
        }

        return $userDescription
            ? "{$description} User guidance: {$userDescription}"
            : $description;
    }

    private function generateMusic(string $moodDescription, int $durationSeconds): ?string
    {
        $model = (string) (config('ai.providers.gemini.models.music.default') ?: self::DEFAULT_MUSIC_MODEL);

        $prompt = view('prompts.story_music.generate_track', [
            'moodDescription' => $moodDescription,
            'durationSeconds' => $durationSeconds,
        ])->render();

        $response = $this->gemini()->post(sprintf(self::GENERATE_CONTENT_PATH, $model), [
            'contents' => [['parts' => [['text' => $prompt]]]],
        ]);

        if ($response->failed()) {
            throw new RuntimeException("Gemini music generation request failed with status {$response->status()}");
        }

        $parts = (array) data_get($response->json(), 'candidates.0.content.parts', []);

        foreach ($parts as $part) {
            $data = data_get($part, 'inlineData.data');

            if (is_string($data) && $data !== '') {
                return $data;
            }
        }

        return null;
    }

    private function gemini(): PendingRequest
    {
        $baseUrl = rtrim((string) config('ai.providers.gemini.url', 'https://generativelanguage.googleapis.com/v1beta/'), '/');

        return Http::baseUrl($baseUrl)
            ->withHeaders(['x-goog-api-key' => config('services.gemini.api_key')])
            ->timeout(120);
    }

    private function mimeTypeOf(string $path): string
    {
        return match (Str::lower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            default => 'image/jpeg',
        };
    }
}
