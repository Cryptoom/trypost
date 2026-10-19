<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Process\Process;

/**
 * PATCH:story-motion: real test videos built with ffmpeg, shared by the story
 * motion tests.
 */
class StoryMotionFixtures
{
    public static function ffmpegAvailable(): bool
    {
        static $available = null;

        return $available ??= (bool) trim((string) shell_exec('command -v ffmpeg 2>/dev/null')) && (bool) trim((string) shell_exec('command -v ffprobe 2>/dev/null'));
    }

    /**
     * Writes a black test video to a temp file and returns its path.
     * Options: seconds (3), size ('1080x1920'), fps (30), audio (false), faststart (true).
     *
     * @param  array{seconds?: int, size?: string, fps?: int, audio?: bool, faststart?: bool}  $options
     */
    public static function video(array $options = []): string
    {
        $seconds = $options['seconds'] ?? 3;
        $size = $options['size'] ?? '1080x1920';
        $fps = $options['fps'] ?? 30;
        $path = sys_get_temp_dir().'/sm01_'.uniqid().'.mp4';

        $args = ['ffmpeg', '-y', '-f', 'lavfi', '-i', "color=c=black:s={$size}:r={$fps}:d={$seconds}"];

        if ($options['audio'] ?? false) {
            array_push($args, '-f', 'lavfi', '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100', '-c:a', 'aac', '-shortest');
        }

        array_push($args, '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-preset', 'ultrafast');

        if ($options['faststart'] ?? true) {
            array_push($args, '-movflags', '+faststart');
        }

        $args[] = $path;

        $process = new Process($args);
        $process->setTimeout(60);
        $process->mustRun();

        return $path;
    }

    /**
     * Like video(), but returns the bytes and leaves no file behind.
     *
     * @param  array{seconds?: int, size?: string, fps?: int, audio?: bool, faststart?: bool}  $options
     */
    public static function videoBytes(array $options = []): string
    {
        $path = self::video($options);
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }
}
