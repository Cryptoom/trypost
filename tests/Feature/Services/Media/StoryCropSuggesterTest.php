<?php

declare(strict_types=1);

use App\Services\Media\StoryCropSuggester;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function tps01SuggesterImage(int $width = 1600, int $height = 900, int $shade = 120): string
{
    $gd = imagecreatetruecolor($width, $height);
    imagefilledrectangle($gd, 0, 0, $width - 1, $height - 1, imagecolorallocate($gd, $shade, $shade, $shade));
    $path = tempnam(sys_get_temp_dir(), 'tps01_sug_');
    imagejpeg($gd, $path, 90);

    return $path;
}

function tps01GeminiFake(array|string $rect, int $status = 200): void
{
    $text = is_string($rect) ? $rect : json_encode($rect);

    // Fresh factory: stubs of an earlier Http::fake() would otherwise keep matching first.
    Http::swap(new Factory);

    Http::fake([
        rtrim((string) config('ai.providers.gemini.url'), '/').'/models/*' => Http::response(
            ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]],
            $status,
        ),
    ]);
}

beforeEach(function () {
    Cache::flush();
    config()->set('services.gemini.api_key', 'test-gemini-key');
    $this->image = tps01SuggesterImage();
});

afterEach(function () {
    @unlink($this->image);
});

test('tps01_suggester_valid', function () {
    // 1600x900 photo: a 9:16 rectangle is 0.3164 wide and full height.
    tps01GeminiFake(['x' => 0.3, 'y' => 0, 'w' => 0.31640625, 'h' => 1]);

    $rect = (new StoryCropSuggester)->suggest($this->image);

    expect($rect)->toBe(['x' => 0.3, 'y' => 0.0, 'w' => 0.31640625, 'h' => 1.0]);

    Http::assertSent(function ($request) {
        $parts = data_get($request->data(), 'contents.0.parts');

        return str_contains($request->url(), config('ai.providers.gemini.url'))
            && $request->hasHeader('x-goog-api-key', 'test-gemini-key')
            && data_get($request->data(), 'generationConfig.responseMimeType') === 'application/json'
            && str_contains((string) data_get($parts, '0.text'), '1600 x 900')
            && data_get($parts, '1.inlineData.mimeType') === 'image/jpeg';
    });
});

test('tps01_suggester_bad_ratio', function () {
    // Square-ish rectangle, not 9:16.
    tps01GeminiFake(['x' => 0.1, 'y' => 0.1, 'w' => 0.5, 'h' => 0.5]);
    expect((new StoryCropSuggester)->suggest($this->image))->toBeNull();

    // Outside the photo.
    Cache::flush();
    tps01GeminiFake(['x' => 0.9, 'y' => 0, 'w' => 0.31640625, 'h' => 1]);
    expect((new StoryCropSuggester)->suggest($this->image))->toBeNull();

    // Inside the 2 percent tolerance (about 1.5 percent too wide) is accepted.
    Cache::flush();
    tps01GeminiFake(['x' => 0.3, 'y' => 0, 'w' => 0.3212, 'h' => 1]);
    expect((new StoryCropSuggester)->suggest($this->image))->not->toBeNull();

    // Garbage answers are rejected.
    foreach (['not json', '[]', '{"x":"a","y":0,"w":1,"h":1}'] as $garbage) {
        Cache::flush();
        tps01GeminiFake($garbage);
        expect((new StoryCropSuggester)->suggest($this->image))->toBeNull();
    }
});

test('tps01_suggester_http500', function () {
    tps01GeminiFake('{}', 500);

    expect((new StoryCropSuggester)->suggest($this->image))->toBeNull();

    // A failed answer is not cached, the next try asks again.
    tps01GeminiFake(['x' => 0.3, 'y' => 0, 'w' => 0.31640625, 'h' => 1]);

    expect((new StoryCropSuggester)->suggest($this->image))->not->toBeNull();
});

test('tps01_suggester_no_key', function () {
    config()->set('services.gemini.api_key', null);
    Http::fake();

    expect((new StoryCropSuggester)->suggest($this->image))->toBeNull();

    config()->set('services.gemini.api_key', '');

    expect((new StoryCropSuggester)->suggest($this->image))->toBeNull();

    Http::assertNothingSent();
});

test('tps01_suggester_cache', function () {
    tps01GeminiFake(['x' => 0.3, 'y' => 0, 'w' => 0.31640625, 'h' => 1]);

    $suggester = new StoryCropSuggester;
    $first = $suggester->suggest($this->image);

    // Same photo content from a different temp path (a retried publish downloads it again).
    $copy = tempnam(sys_get_temp_dir(), 'tps01_sug_copy_');
    copy($this->image, $copy);
    $second = $suggester->suggest($copy);
    @unlink($copy);

    expect($second)->toBe($first);
    Http::assertSentCount(1);

    // A different photo is a different cache entry.
    $other = tps01SuggesterImage(1600, 900, 40);
    $suggester->suggest($other);
    @unlink($other);

    Http::assertSentCount(2);
});

test('tps01_suggester_oversized_image', function () {
    // A tiny PNG that declares huge pixel dimensions must not be decoded by GD.
    $ihdr = pack('N', 20000).pack('N', 20000)."\x08\x02\x00\x00\x00";
    $huge = tempnam(sys_get_temp_dir(), 'tps01_huge_');
    file_put_contents($huge, "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.$ihdr.pack('N', 0));
    Http::fake();

    expect((new StoryCropSuggester)->suggest($huge))->toBeNull();

    Http::assertNothingSent();
    @unlink($huge);
});
