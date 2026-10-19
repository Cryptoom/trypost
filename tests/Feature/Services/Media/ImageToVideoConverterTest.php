<?php

declare(strict_types=1);

use App\Services\Media\ImageToVideoConverter;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Symfony\Component\Process\Process;

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

test('tps01 ffmpeg scale pad', function () {
    if (! ffmpegAvailableForTest()) {
        $this->markTestSkipped('ffmpeg is not installed on this machine.');
    }

    // Odd, landscape source: without scale/pad libx264 would reject the odd size.
    $manager = new ImageManager(Driver::class);
    $this->imagePath = tempnam(sys_get_temp_dir(), 'i2v_odd_').'.jpg';
    file_put_contents($this->imagePath, (string) $manager->createImage(321, 201)->fill('336699')->encodeUsingMediaType('image/jpeg', quality: 80));

    $this->outputPath = (new ImageToVideoConverter)->convert($this->imagePath, 1);

    $size = trim((string) shell_exec('ffprobe -v error -select_streams v:0 -show_entries stream=width,height -of csv=p=0 '.escapeshellarg($this->outputPath)));

    expect($size)->toBe('1080,1920');
});

test('throws when ffmpeg fails to convert a non-existent image', function () {
    if (! ffmpegAvailableForTest()) {
        $this->markTestSkipped('ffmpeg is not installed on this machine.');
    }

    expect(fn () => (new ImageToVideoConverter)->convert('/tmp/definitely-not-a-real-file-'.uniqid().'.jpg', 2))
        ->toThrow(RuntimeException::class);
});

test('story-motion addAudioTrack muxes a silent video with a silent aac track and copies the video stream', function () {
    if (! ffmpegAvailableForTest()) {
        $this->markTestSkipped('ffmpeg is not installed on this machine.');
    }

    $converter = new ImageToVideoConverter;
    $silent = (new Process(['ffmpeg', '-y', '-f', 'lavfi', '-i', 'color=c=black:s=1080x1920:r=30:d=3', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-preset', 'ultrafast', $silentPath = sys_get_temp_dir().'/sm01_silent_'.uniqid().'.mp4']));
    $silent->mustRun();

    $this->outputPath = $converter->addAudioTrack($silentPath, 3);
    @unlink($silentPath);

    $streams = shell_exec('ffprobe -v error -show_entries stream=codec_type,codec_name -of csv=p=0 '.escapeshellarg($this->outputPath));

    expect($streams)->toContain('h264,video')->toContain('aac,audio')
        ->and(substr_count($streams, 'video'))->toBe(1)
        ->and(substr_count($streams, 'audio'))->toBe(1);
});

test('story-motion addAudioTrack uses a supplied music track instead of silence', function () {
    if (! ffmpegAvailableForTest()) {
        $this->markTestSkipped('ffmpeg is not installed on this machine.');
    }

    $silentPath = sys_get_temp_dir().'/sm01_silent_'.uniqid().'.mp4';
    $musicPath = sys_get_temp_dir().'/sm01_music_'.uniqid().'.mp3';
    (new Process(['ffmpeg', '-y', '-f', 'lavfi', '-i', 'color=c=black:s=1080x1920:r=30:d=3', '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-preset', 'ultrafast', $silentPath]))->mustRun();
    (new Process(['ffmpeg', '-y', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=1', $musicPath]))->mustRun();

    $this->outputPath = (new ImageToVideoConverter)->addAudioTrack($silentPath, 3, $musicPath);
    @unlink($silentPath);
    @unlink($musicPath);

    $duration = (float) trim((string) shell_exec('ffprobe -v error -show_entries format=duration -of csv=p=0 '.escapeshellarg($this->outputPath)));
    $streams = shell_exec('ffprobe -v error -show_entries stream=codec_type -of csv=p=0 '.escapeshellarg($this->outputPath));

    // The one second track is looped to the full three seconds.
    expect($streams)->toContain('audio')->and($duration)->toBeGreaterThan(2.5)->toBeLessThan(3.5);
});

test('story-motion addAudioTrack throws and leaves no output file when the video cannot be read', function () {
    if (! ffmpegAvailableForTest()) {
        $this->markTestSkipped('ffmpeg is not installed on this machine.');
    }

    $before = glob(sys_get_temp_dir().'/*.mp4') ?: [];

    expect(fn () => (new ImageToVideoConverter)->addAudioTrack('/nonexistent/silent.mp4', 3))->toThrow(RuntimeException::class);

    expect(array_values(array_diff(glob(sys_get_temp_dir().'/*.mp4') ?: [], $before)))->toBe([]);
});
