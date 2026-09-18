<?php

declare(strict_types=1);

namespace App\Services\Media;

use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

/**
 * Converts a static image into a short, held-frame MP4 via ffmpeg, for
 * platforms/content types that require video but a user only supplied a
 * photo (e.g. Facebook Stories). No animation or Ken Burns, just the image
 * displayed for the requested duration.
 *
 * A silent AAC track is muxed in when no music is supplied: Facebook
 * rejects a Story video with zero audio streams ("Problem with file. Try
 * with another file.") even though the identical file publishes fine to
 * Instagram. The silent track is required, not cosmetic.
 */
class ImageToVideoConverter
{
    private const FFMPEG_TIMEOUT_SECONDS = 120;

    /**
     * Renders `$imagePath` into a held-frame MP4 of `$durationSeconds`,
     * muxing in `$audioPath` when provided (looped/trimmed to fit the
     * target duration) or a silent AAC track otherwise. Returns the path
     * to the generated temp file, the caller owns cleanup.
     *
     * @throws RuntimeException when ffmpeg exits non-zero.
     */
    public function convert(string $imagePath, int $durationSeconds, ?string $audioPath = null): string
    {
        $outputPath = sys_get_temp_dir().'/'.Str::uuid().'.mp4';

        $process = new Process($this->ffmpegArgs($imagePath, $outputPath, $durationSeconds, $audioPath));
        $process->setTimeout(self::FFMPEG_TIMEOUT_SECONDS);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $e) {
            // ffmpeg runs with -y and may have written a partial file
            // before failing; it's never returned on this path, so clean
            // it up rather than leaking it into the temp dir.
            if (is_file($outputPath)) {
                @unlink($outputPath);
            }

            throw new RuntimeException("ffmpeg failed to convert image to video: {$e->getMessage()}", previous: $e);
        }

        return $outputPath;
    }

    /**
     * @return array<int, string>
     */
    private function ffmpegArgs(string $imagePath, string $outputPath, int $durationSeconds, ?string $audioPath): array
    {
        return [
            'ffmpeg', '-y', '-loop', '1', '-i', $imagePath,
            ...$this->audioInputArgs($audioPath),
            '-t', (string) $durationSeconds,
            '-c:v', 'libx264',
            '-pix_fmt', 'yuv420p',
            '-vf', 'fps=30,format=yuv420p',
            '-c:a', 'aac',
            '-b:a', '128k',
            '-shortest',
            '-movflags', '+faststart',
            $outputPath,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function audioInputArgs(?string $audioPath): array
    {
        if ($audioPath !== null) {
            // Loop the supplied track indefinitely, the global -t above
            // trims it (or pads a shorter track) to the video's duration.
            return ['-stream_loop', '-1', '-i', $audioPath];
        }

        return ['-f', 'lavfi', '-i', 'anullsrc=channel_layout=stereo:sample_rate=44100'];
    }
}
