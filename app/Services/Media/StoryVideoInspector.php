<?php

declare(strict_types=1);

namespace App\Services\Media;

use Symfony\Component\Process\Process;

/**
 * PATCH:story-motion: run-time guard for story videos. ffprobe tells whether a
 * file is exactly what Facebook accepts for a Story, so a render or mux that
 * went subtly wrong never reaches the upload and the caller falls back to the
 * held-frame video instead.
 */
class StoryVideoInspector
{
    private const FFPROBE_TIMEOUT_SECONDS = 20;

    private const DURATION_TOLERANCE_SECONDS = 0.5;

    /**
     * The reason `$path` is not a valid story video, or null when it is: exactly
     * one h264/yuv420p 1080x1920 30 fps video stream, a duration within half a
     * second of `$expectedSeconds` and, for the final file, exactly one AAC
     * audio stream with the moov atom in front (`$expectAudio`), for the silent
     * render output no audio stream at all.
     */
    public function problem(string $path, int $expectedSeconds, bool $expectAudio): ?string
    {
        $info = $this->probe($path);

        if ($info === null) {
            return 'unreadable';
        }

        $streams = collect(data_get($info, 'streams', []));
        $video = $streams->where('codec_type', 'video')->values();
        $audio = $streams->where('codec_type', 'audio')->values();

        if ($video->count() !== 1) {
            return 'video_stream_count';
        }

        $stream = $video->first();

        $videoProblem = match (true) {
            data_get($stream, 'codec_name') !== 'h264' => 'video_codec',
            data_get($stream, 'pix_fmt') !== 'yuv420p' => 'pixel_format',
            data_get($stream, 'width') !== 1080 || data_get($stream, 'height') !== 1920 => 'dimensions',
            data_get($stream, 'r_frame_rate') !== '30/1' => 'frame_rate',
            default => null,
        };

        if ($videoProblem !== null) {
            return $videoProblem;
        }

        if ($expectAudio && ($audio->count() !== 1 || data_get($audio->first(), 'codec_name') !== 'aac')) {
            return 'audio_stream';
        }

        if (! $expectAudio && $audio->isNotEmpty()) {
            return 'unexpected_audio';
        }

        $duration = data_get($info, 'format.duration');

        if (! is_numeric($duration) || abs((float) $duration - $expectedSeconds) > self::DURATION_TOLERANCE_SECONDS) {
            return 'duration';
        }

        if ($expectAudio && ! $this->moovPrecedesMdat($path)) {
            return 'moov_not_first';
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function probe(string $path): ?array
    {
        $process = new Process(['ffprobe', '-v', 'error', '-print_format', 'json', '-show_streams', '-show_format', $path]);
        $process->setTimeout(self::FFPROBE_TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        $decoded = json_decode($process->getOutput(), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Walks the top-level MP4 boxes: a fast-start file has `moov` before `mdat`.
     */
    private function moovPrecedesMdat(string $path): bool
    {
        $size = filesize($path);
        $handle = fopen($path, 'rb');

        if ($size === false || $handle === false) {
            return false;
        }

        try {
            $offset = 0;

            while ($offset + 8 <= $size) {
                fseek($handle, $offset);
                $header = fread($handle, 8);

                if ($header === false || strlen($header) < 8) {
                    return false;
                }

                $boxSize = (int) data_get(unpack('N', substr($header, 0, 4)), 1);
                $type = substr($header, 4, 4);

                if ($type === 'moov') {
                    return true;
                }

                if ($type === 'mdat') {
                    return false;
                }

                if ($boxSize === 1) {
                    $large = fread($handle, 8);
                    $boxSize = $large === false || strlen($large) < 8 ? 0 : (int) data_get(unpack('J', $large), 1);
                }

                if ($boxSize < 8) {
                    return false;
                }

                $offset += $boxSize;
            }

            return false;
        } finally {
            fclose($handle);
        }
    }
}
