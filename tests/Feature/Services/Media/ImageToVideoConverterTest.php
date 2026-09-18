<?php

declare(strict_types=1);

use App\Services\Media\ImageToVideoConverter;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

function imageToVideoJpegPath(): string
{
    // libx264 requires even width/height, so this uses a real (small but
    // even-dimensioned) JPEG rather than a 1x1 pixel fixture.
    $manager = new ImageManager(Driver::class);
    $image = $manager->createImage(64, 64)->fill('336699');

    $path = tempnam(sys_get_temp_dir(), 'i2v_test_').'.jpg';
    file_put_contents($path, (string) $image->encodeUsingMediaType('image/jpeg', quality: 80));

    return $path;
}

function ffmpegAvailableForTest(): bool
{
    static $available = null;

    if ($available === null) {
        $available = (bool) trim((string) shell_exec('command -v ffmpeg 2>/dev/null'));
    }

    return $available;
}

afterEach(function () {
    if (isset($this->imagePath) && file_exists($this->imagePath)) {
        unlink($this->imagePath);
    }

    if (isset($this->outputPath) && file_exists($this->outputPath)) {
        unlink($this->outputPath);
    }
});

test('converts an image into a held-frame mp4 with a silent audio track', function () {
    if (! ffmpegAvailableForTest()) {
        $this->markTestSkipped('ffmpeg is not installed on this machine.');
    }

    $this->imagePath = imageToVideoJpegPath();

    $this->outputPath = (new ImageToVideoConverter)->convert($this->imagePath, 2);

    expect(file_exists($this->outputPath))->toBeTrue();
    expect(filesize($this->outputPath))->toBeGreaterThan(0);

    $streams = shell_exec('ffprobe -v error -show_entries stream=codec_type -of csv=p=0 '.escapeshellarg($this->outputPath));

    expect($streams)->toContain('video')->toContain('audio');
});

test('throws when ffmpeg fails to convert a non-existent image', function () {
    if (! ffmpegAvailableForTest()) {
        $this->markTestSkipped('ffmpeg is not installed on this machine.');
    }

    expect(fn () => (new ImageToVideoConverter)->convert('/tmp/definitely-not-a-real-file-'.uniqid().'.jpg', 2))
        ->toThrow(RuntimeException::class);
});
