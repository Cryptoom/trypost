<?php

declare(strict_types=1);

use App\Services\Media\StoryMusicGenerator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function storyMusicJpegPath(): string
{
    $path = tempnam(sys_get_temp_dir(), 'story_music_test_').'.jpg';
    file_put_contents($path, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
    ));

    return $path;
}

afterEach(function () {
    if (isset($this->imagePath) && file_exists($this->imagePath)) {
        unlink($this->imagePath);
    }
});

test('story music generation is disabled by default', function () {
    config()->set('trypost.platforms.facebook.story_ai_music_enabled', false);

    $generator = new StoryMusicGenerator;

    expect($generator->isEnabled())->toBeFalse();
});

test('story music generation stays disabled without a Gemini API key even when the flag is on', function () {
    config()->set('trypost.platforms.facebook.story_ai_music_enabled', true);
    config()->set('services.gemini.api_key', null);

    $generator = new StoryMusicGenerator;

    expect($generator->isEnabled())->toBeFalse();
});

test('story music generation falls back to null without any HTTP call when disabled', function () {
    config()->set('trypost.platforms.facebook.story_ai_music_enabled', false);
    Http::preventStrayRequests();

    $this->imagePath = storyMusicJpegPath();

    $result = (new StoryMusicGenerator)->generate($this->imagePath, 15);

    expect($result)->toBeNull();
});

test('story music generation falls back to null when the Gemini vision call fails', function () {
    config()->set('trypost.platforms.facebook.story_ai_music_enabled', true);
    config()->set('services.gemini.api_key', 'test-gemini-key');

    Log::shouldReceive('warning')->once()->with(
        'Story AI music generation failed, falling back to silent audio',
        Mockery::type('array'),
    );

    Http::fake([
        '*generativelanguage.googleapis.com*' => Http::response(['error' => 'server error'], 500),
    ]);

    $this->imagePath = storyMusicJpegPath();

    $result = (new StoryMusicGenerator)->generate($this->imagePath, 15);

    expect($result)->toBeNull();
});

test('story music generation falls back to null when Gemini returns no audio inline data', function () {
    config()->set('trypost.platforms.facebook.story_ai_music_enabled', true);
    config()->set('services.gemini.api_key', 'test-gemini-key');

    Http::fake(function ($request) {
        if (str_contains($request->url(), 'gemini-3.6-flash')) {
            return Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'Warm acoustic guitar, gentle and upbeat.']]]]],
            ], 200);
        }

        return Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'no audio here']]]]],
        ], 200);
    });

    $this->imagePath = storyMusicJpegPath();

    $result = (new StoryMusicGenerator)->generate($this->imagePath, 15);

    expect($result)->toBeNull();
});

test('story music generation returns a local mp3 path on success', function () {
    config()->set('trypost.platforms.facebook.story_ai_music_enabled', true);
    config()->set('services.gemini.api_key', 'test-gemini-key');

    $audioBase64 = base64_encode('fake-mp3-bytes');

    Http::fake(function ($request) use ($audioBase64) {
        if (str_contains($request->url(), 'gemini-3.6-flash')) {
            expect(data_get($request->data(), 'contents.0.parts.1.inlineData.data'))->not->toBeEmpty();

            return Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'Warm acoustic guitar, gentle and upbeat.']]]]],
            ], 200);
        }

        expect($request->url())->toContain('lyria-3-pro-preview');

        return Http::response([
            'candidates' => [[
                'content' => ['parts' => [
                    ['text' => 'lyrics'],
                    ['text' => 'metadata'],
                    ['inlineData' => ['mimeType' => 'audio/mpeg', 'data' => $audioBase64]],
                ]],
            ]],
        ], 200);
    });

    $this->imagePath = storyMusicJpegPath();

    $audioPath = (new StoryMusicGenerator)->generate($this->imagePath, 15, 'something festive');

    expect($audioPath)->not->toBeNull();
    expect(file_exists($audioPath))->toBeTrue();
    expect(file_get_contents($audioPath))->toBe('fake-mp3-bytes');

    unlink($audioPath);
});
