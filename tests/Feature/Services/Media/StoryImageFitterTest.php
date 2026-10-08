<?php

declare(strict_types=1);

use App\Services\Media\MediaOptimizer;
use App\Services\Media\StoryImageFitter;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * Native GD JPEG, left half red and right half blue.
 */
function tps01TwoToneJpeg(int $width, int $height): string
{
    $gd = imagecreatetruecolor($width, $height);
    imagefilledrectangle($gd, 0, 0, intdiv($width, 2) - 1, $height - 1, imagecolorallocate($gd, 230, 20, 20));
    imagefilledrectangle($gd, intdiv($width, 2), 0, $width - 1, $height - 1, imagecolorallocate($gd, 20, 20, 230));

    ob_start();
    imagejpeg($gd, null, 95);

    return (string) ob_get_clean();
}

/**
 * Inject an EXIF orientation tag (APP1) right after the JPEG SOI marker.
 */
function tps01WithExifOrientation(string $jpeg, int $orientation): string
{
    $tiff = 'II'.pack('v', 42).pack('V', 8).pack('v', 1)
        .pack('v', 0x0112).pack('v', 3).pack('V', 1).pack('v', $orientation).pack('v', 0)
        .pack('V', 0);
    $app1 = "Exif\0\0".$tiff;

    return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
}

function tps01WriteTemp(string $bytes): string
{
    $path = tempnam(sys_get_temp_dir(), 'tps01_src_');
    file_put_contents($path, $bytes);

    return $path;
}

function tps01Decode(string $path)
{
    return (new ImageManager(Driver::class))->decodePath($path);
}

beforeEach(function () {
    $this->tempFiles = [];
    Cache::flush();

    $this->track = function (string $path): string {
        $this->tempFiles[] = $path;

        return $path;
    };
});

afterEach(function () {
    foreach ($this->tempFiles as $path) {
        @unlink($path);
    }
});

test('tps01_fitter_center_1080x1920', function () {
    $source = ($this->track)(tps01WriteTemp(tps01TwoToneJpeg(1600, 900)));

    $out = ($this->track)(app(StoryImageFitter::class)->fit($source, 'center'));
    $image = tps01Decode($out);

    expect($image->width())->toBe(1080)
        ->and($image->height())->toBe(1920);

    // Center of a left-red / right-blue photo: both halves are cropped away symmetrically,
    // so the left edge of the result is red and the right edge is blue.
    expect($image->colorAt(5, 960)->red()->value())->toBeGreaterThan(150)
        ->and($image->colorAt(1074, 960)->blue()->value())->toBeGreaterThan(150);
});

test('tps01_fitter_fit', function () {
    $source = ($this->track)(tps01WriteTemp(tps01TwoToneJpeg(1600, 900)));

    $out = ($this->track)(app(StoryImageFitter::class)->fit($source, 'fit'));
    $image = tps01Decode($out);

    // Nothing is cropped: the whole photo (both colors) is visible in the middle band.
    expect($image->width())->toBe(1080)
        ->and($image->height())->toBe(1920)
        ->and($image->colorAt(100, 960)->red()->value())->toBeGreaterThan(150)
        ->and($image->colorAt(980, 960)->blue()->value())->toBeGreaterThan(150);
});

test('tps01_fitter_manual', function () {
    $source = ($this->track)(tps01WriteTemp(tps01TwoToneJpeg(1600, 900)));

    // 9:16 rectangle inside the blue (right) half: w * 1600 / (h * 900) = 0.5625.
    $rect = ['x' => 0.6, 'y' => 0.0, 'w' => 0.31640625, 'h' => 1.0];

    $out = ($this->track)(app(StoryImageFitter::class)->fit($source, 'manual', $rect));
    $image = tps01Decode($out);

    expect($image->width())->toBe(1080)
        ->and($image->height())->toBe(1920)
        ->and($image->colorAt(540, 960)->blue()->value())->toBeGreaterThan(150)
        ->and($image->colorAt(540, 960)->red()->value())->toBeLessThan(100);

    // A rectangle with the wrong ratio never fails, it falls back to the center crop.
    $fallback = ($this->track)(app(StoryImageFitter::class)->fit($source, 'manual', ['x' => 0.0, 'y' => 0.0, 'w' => 0.5, 'h' => 0.5]));

    expect(tps01Decode($fallback)->width())->toBe(1080)
        ->and(tps01Decode($fallback)->height())->toBe(1920);

    // A missing or malformed rectangle behaves the same way.
    foreach ([null, 'garbage', ['x' => 'a']] as $broken) {
        $out = ($this->track)(app(StoryImageFitter::class)->fit($source, 'manual', $broken));

        expect(tps01Decode($out)->height())->toBe(1920);
    }
});

test('tps01_fitter_smart_fallback', function () {
    $source = ($this->track)(tps01WriteTemp(tps01TwoToneJpeg(1600, 900)));

    // No API key: smart quietly becomes a center crop.
    config()->set('services.gemini.api_key', null);
    Http::preventStrayRequests();

    $withoutKey = tps01Decode(($this->track)(app(StoryImageFitter::class)->fit($source, 'smart')));

    expect($withoutKey->width())->toBe(1080)->and($withoutKey->height())->toBe(1920);

    // Gemini answers 500: still a center crop, no exception.
    config()->set('services.gemini.api_key', 'test-gemini-key');
    Http::swap(new Factory);
    Http::fake([rtrim((string) config('ai.providers.gemini.url'), '/').'/models/*' => Http::response(['error' => 'boom'], 500)]);

    $withError = tps01Decode(($this->track)(app(StoryImageFitter::class)->fit($source, 'smart')));

    expect($withError->width())->toBe(1080)->and($withError->height())->toBe(1920);
    // Center crop of a left-red / right-blue photo keeps both colors on the edges.
    expect($withError->colorAt(5, 960)->red()->value())->toBeGreaterThan(150);

    // A valid suggestion is used: crop the blue half.
    Cache::flush();
    Http::swap(new Factory);
    Http::fake([rtrim((string) config('ai.providers.gemini.url'), '/').'/models/*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => json_encode(['x' => 0.6, 'y' => 0, 'w' => 0.31640625, 'h' => 1])]]]]],
    ])]);

    $suggested = tps01Decode(($this->track)(app(StoryImageFitter::class)->fit($source, 'smart')));

    expect($suggested->colorAt(540, 960)->blue()->value())->toBeGreaterThan(150)
        ->and($suggested->colorAt(540, 960)->red()->value())->toBeLessThan(100);
});

test('tps01_fitter_unknown_mode_center', function () {
    $source = ($this->track)(tps01WriteTemp(tps01TwoToneJpeg(1600, 900)));
    $fitter = app(StoryImageFitter::class);

    foreach ([null, '', 'nonsense', 42] as $mode) {
        $image = tps01Decode(($this->track)($fitter->fit($source, $mode)));

        expect($image->width())->toBe(1080)
            ->and($image->height())->toBe(1920)
            ->and($image->colorAt(5, 960)->red()->value())->toBeGreaterThan(150);
    }
});

test('tps01_exif_rotated', function () {
    // Stored 1200x800 landscape with EXIF orientation 6 (rotate 90 degrees clockwise to display):
    // as seen by the user it is 800x1200 portrait, the stored red left half is now the TOP.
    $source = ($this->track)(tps01WriteTemp(tps01WithExifOrientation(tps01TwoToneJpeg(1200, 800), 6)));

    $out = ($this->track)(app(StoryImageFitter::class)->fit($source, 'center'));
    $image = tps01Decode($out);

    expect($image->width())->toBe(1080)
        ->and($image->height())->toBe(1920)
        ->and($image->colorAt(540, 40)->red()->value())->toBeGreaterThan(150)
        ->and($image->colorAt(540, 1880)->blue()->value())->toBeGreaterThan(150);
});

test('tps01_gd_driver', function () {
    // The production image has GD only (no Imagick). Cover the GD paths explicitly.
    expect(extension_loaded('gd'))->toBeTrue();

    $source = ($this->track)(tps01WriteTemp(tps01TwoToneJpeg(1600, 900)));

    // Center crop and manual crop run on the GD manager of MediaOptimizer.
    $center = tps01Decode(($this->track)(app(MediaOptimizer::class)->coverToSize($source, 1080, 1920)));

    expect($center->width())->toBe(1080)->and($center->height())->toBe(1920);

    // The blurred-background "fit" has an Imagick and a GD implementation, force the GD one.
    $method = new ReflectionMethod(MediaOptimizer::class, 'fitOntoBlurredBackgroundGd');
    $canvas = $method->invoke(new MediaOptimizer, $source, 1080, 1920);
    $path = ($this->track)(tempnam(sys_get_temp_dir(), 'tps01_gd_'));
    file_put_contents($path, (string) $canvas->encodeUsingMediaType('image/jpeg'));
    $fitted = tps01Decode($path);

    expect($fitted->width())->toBe(1080)->and($fitted->height())->toBe(1920);
});
