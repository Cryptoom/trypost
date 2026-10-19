<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * PATCH:story-motion: asks the internal story-render service (docker/story-render)
 * to turn a story photo into a silent camera-move MP4, optionally with an
 * animated headline.
 *
 * Nothing here may ever fail a publish: every problem (service down, busy,
 * timeout, bad answer, a file that is not a valid story video) is logged with
 * a reason and ends in `null`, and the caller falls back to the held-frame
 * video. The service is off by default and serves only the workspaces on its
 * allowlist.
 */
class StoryMotionRenderer
{
    /** Seconds since the publish started after which no render is attempted any more. */
    private const BUDGET_SECONDS = 300;

    private const MAX_IMAGE_BYTES = 8 * 1024 * 1024;

    private const CONNECT_TIMEOUT_SECONDS = 5;

    private const MAX_TIMEOUT_SECONDS = 255;

    public function __construct(private readonly StoryVideoInspector $inspector) {}

    public function isEnabledFor(Workspace $workspace): bool
    {
        return $this->skipReason($workspace) === null;
    }

    /**
     * Why motion is not used for this workspace, or null when it is. `disabled`
     * is the shipped default and not worth a log line, every other reason is.
     */
    public function skipReason(Workspace $workspace): ?string
    {
        if (! (bool) config('trypost.platforms.facebook.story_motion.enabled')) {
            return 'disabled';
        }

        if (blank(config('trypost.platforms.facebook.story_motion.url'))) {
            return 'no_url';
        }

        if (blank(config('trypost.platforms.facebook.story_motion.token'))) {
            return 'no_token';
        }

        if (! in_array((string) $workspace->id, $this->allowedWorkspaceIds(), true)) {
            return 'workspace_not_allowed';
        }

        return null;
    }

    /**
     * Renders `$fittedImagePath` (the final 1080x1920 frame) into a silent MP4
     * of `$durationSeconds`. Returns the path of a temp file the caller owns,
     * or null on any problem.
     *
     * `$startedAt` is when the publish began: once more than 300 seconds are
     * gone the render is skipped so the job keeps its time for the upload.
     */
    public function render(string $fittedImagePath, int $durationSeconds, ?string $headline = null, ?CarbonInterface $startedAt = null): ?string
    {
        if ($startedAt !== null && $startedAt->diffInSeconds(now(), true) > self::BUDGET_SECONDS) {
            return $this->fallback('budget');
        }

        $image = is_file($fittedImagePath) ? file_get_contents($fittedImagePath) : false;

        if ($image === false || $image === '') {
            return $this->fallback('unreadable_image');
        }

        if (strlen($image) > self::MAX_IMAGE_BYTES) {
            return $this->fallback('image_too_large');
        }

        $outputPath = sys_get_temp_dir().'/'.Str::uuid().'.mp4';

        try {
            $response = $this->request($image, $durationSeconds, $headline, $outputPath);

            $reason = match (true) {
                $response->status() === 503 => 'busy',
                $response->status() === 504 => 'timeout',
                $response->status() === 401 => 'unauthorized',
                ! $response->successful() => 'http_error',
                default => null,
            };

            if ($reason !== null) {
                return $this->discard($outputPath, $reason);
            }

            if (! is_file($outputPath) || filesize($outputPath) === 0) {
                return $this->discard($outputPath, 'invalid_output');
            }

            if ($this->inspector->problem($outputPath, $durationSeconds, expectAudio: false) !== null) {
                return $this->discard($outputPath, 'invalid_output');
            }

            return $outputPath;
        } catch (ConnectionException $e) {
            return $this->discard($outputPath, str_contains(strtolower($e->getMessage()), 'timed out') ? 'timeout' : 'unavailable');
        } catch (Throwable) {
            return $this->discard($outputPath, 'error');
        }
    }

    /**
     * The HTTP timeout: the configured value, held between 10 seconds and the
     * service's 240 second render limit plus 15 seconds.
     */
    public function timeoutSeconds(): int
    {
        return max(10, min((int) config('trypost.platforms.facebook.story_motion.timeout_seconds'), self::MAX_TIMEOUT_SECONDS));
    }

    private function request(string $image, int $durationSeconds, ?string $headline, string $outputPath): Response
    {
        $headers = ['X-Story-Render-Token' => (string) config('trypost.platforms.facebook.story_motion.token')];

        if (filled($headline)) {
            // base64url keeps non-ASCII text out of the header value.
            $headers['X-Story-Headline-B64'] = rtrim(strtr(base64_encode((string) $headline), '+/', '-_'), '=');
        }

        $timeout = $this->timeoutSeconds();
        $url = rtrim((string) config('trypost.platforms.facebook.story_motion.url'), '/');

        return Http::withHeaders($headers)
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout($timeout)
            ->withOptions(['sink' => $outputPath])
            ->withBody($image, 'application/octet-stream')
            ->withQueryParameters(['duration' => $durationSeconds])
            ->post("{$url}/render");
    }

    /**
     * @return array<int, string>
     */
    private function allowedWorkspaceIds(): array
    {
        $configured = config('trypost.platforms.facebook.story_motion.workspaces');
        $entries = is_array($configured) ? $configured : explode(',', (string) $configured);

        return array_values(array_filter(array_map(fn ($entry) => trim((string) $entry), $entries), fn (string $entry) => $entry !== ''));
    }

    private function discard(string $outputPath, string $reason): null
    {
        if (is_file($outputPath)) {
            @unlink($outputPath);
        }

        return $this->fallback($reason);
    }

    private function fallback(string $reason): null
    {
        Log::warning('Story motion render fell back to the still frame', ['reason' => $reason]);

        return null;
    }
}
