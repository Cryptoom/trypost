<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Models\PostPlatform;
use App\Models\Workspace;
use App\Support\StoryMotionAccent;
use App\Support\StoryMotionLogo;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * PATCH:story-motion: asks the internal story-render service (docker/story-render)
 * to turn a story photo into a silent camera-move MP4 with the workspace's
 * Story video look (effect, font, accent colour, optional logo and notice
 * layers, optional animated texts and ending).
 *
 * Nothing here may ever fail a publish: every problem (service down, busy,
 * timeout, bad answer, bad settings, a file that is not a valid story video)
 * is logged with a reason and ends in `null`, and the caller falls back to the
 * held-frame video. Two switches must be on: the installation's master switch
 * (env, PATCH:story-motion) and the workspace's own Story video setting
 * (PATCH:story-motion-settings, default off). No text, token or logo bytes are
 * ever logged.
 */
class StoryMotionRenderer
{
    /** Seconds since the publish started after which no render is attempted any more. */
    private const BUDGET_SECONDS = 300;

    private const MAX_IMAGE_BYTES = 8 * 1024 * 1024;

    private const CONNECT_TIMEOUT_SECONDS = 5;

    private const MAX_TIMEOUT_SECONDS = 255;

    public function __construct(private readonly StoryVideoInspector $inspector) {}

    /**
     * Whether the installation can render Story videos at all: master switch on, service URL and token set.
     * This is what the Brand settings page needs to show the Story video section.
     */
    public function isAvailable(): bool
    {
        return $this->unavailableReason() === null;
    }

    public function isEnabledFor(Workspace $workspace): bool
    {
        return $this->skipReason($workspace) === null;
    }

    /**
     * Why motion is not used for this workspace, or null when it is. `disabled` (master switch off, the
     * shipped default) and `workspace_off` (the workspace has not turned it on) are normal states and not
     * worth a log line, every other reason is.
     */
    public function skipReason(Workspace $workspace): ?string
    {
        $unavailable = $this->unavailableReason();

        if ($unavailable !== null) {
            return $unavailable;
        }

        return StoryMotionSettings::forWorkspace($workspace)->enabled ? null : 'workspace_off';
    }

    private function unavailableReason(): ?string
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
    public function render(string $fittedImagePath, int $durationSeconds, Workspace $workspace, PostPlatform $postPlatform, ?CarbonInterface $startedAt = null): ?string
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

        try {
            $settings = StoryMotionSettings::forWorkspace($workspace);
            $logo = $settings->logoLayer ? $this->logoOf($workspace) : null;
            $spec = StoryMotionSpec::build(
                $settings,
                app(StoryMotionEffectPicker::class)->pick($settings, $workspace, $postPlatform),
                [
                    'headline' => data_get($postPlatform->meta, 'story_headline'),
                    'subline' => data_get($postPlatform->meta, 'story_subline'),
                    'badge' => data_get($postPlatform->meta, 'story_badge'),
                    'headline_alt' => data_get($postPlatform->meta, 'story_headline_alt'),
                    'subline_alt' => data_get($postPlatform->meta, 'story_subline_alt'),
                ],
                StoryMotionAccent::resolve($workspace->brand_color),
                $durationSeconds,
                $logo !== null,
            );
        } catch (Throwable) {
            return $this->fallback('invalid_settings');
        }

        $outputPath = sys_get_temp_dir().'/'.Str::uuid().'.mp4';

        try {
            $response = $this->request($image, $logo, $spec, $outputPath);

            $reason = match (true) {
                $response->status() === 503 => 'busy',
                $response->status() === 504 => 'timeout',
                $response->status() === 401 => 'unauthorized',
                $response->status() === 400 => 'rejected',
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

    /**
     * @param  array{bytes: string, ext: string}|null  $logo
     * @param  array<string, mixed>  $spec
     */
    private function request(string $image, ?array $logo, array $spec, string $outputPath): Response
    {
        $timeout = $this->timeoutSeconds();
        $url = rtrim((string) config('trypost.platforms.facebook.story_motion.url'), '/');

        $pending = Http::withHeaders(['X-Story-Render-Token' => (string) config('trypost.platforms.facebook.story_motion.token')])
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout($timeout)
            ->withOptions(['sink' => $outputPath])
            ->asMultipart()
            ->attach('photo', $image, 'photo');

        if ($logo !== null) {
            $pending = $pending->attach('logo', $logo['bytes'], "logo.{$logo['ext']}");
        }

        // The spec goes as a plain text field (no file name), the service reads it as JSON text.
        return $pending
            ->attach('spec', (string) json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR))
            ->post("{$url}/render");
    }

    /**
     * The workspace logo as the service wants it: JPEG or PNG by magic bytes, at most 2 MB and 2000 x 2000
     * pixels (size and dimensions are read from the media record, the bytes from storage, never a URL). Any
     * other logo is left out, with a log line, and the Story gets the calm motion without the logo layer.
     *
     * @return array{bytes: string, ext: string}|null
     */
    private function logoOf(Workspace $workspace): ?array
    {
        $media = $workspace->getFirstMedia('logo');
        $detail = match (true) {
            $media === null => 'missing',
            (int) $media->size > StoryMotionLogo::MAX_BYTES => 'size',
            (int) data_get($media->meta, 'width') < 1 || (int) data_get($media->meta, 'height') < 1 => 'dimensions',
            (int) data_get($media->meta, 'width') > StoryMotionLogo::MAX_SIDE || (int) data_get($media->meta, 'height') > StoryMotionLogo::MAX_SIDE => 'dimensions',
            default => null,
        };

        $bytes = null;

        if ($detail === null) {
            try {
                $bytes = Storage::get($media->path);
            } catch (Throwable) {
                $bytes = null;
            }

            $detail = match (true) {
                ! is_string($bytes) || $bytes === '' => 'unreadable',
                strlen($bytes) > StoryMotionLogo::MAX_BYTES => 'size',
                str_starts_with($bytes, "\xFF\xD8\xFF") => null,
                str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => null,
                default => 'format',
            };
        }

        if ($detail !== null) {
            Log::info('Story motion logo layer dropped', ['reason' => 'logo_unusable', 'detail' => $detail]);

            return null;
        }

        return ['bytes' => $bytes, 'ext' => str_starts_with($bytes, "\xFF\xD8\xFF") ? 'jpg' : 'png'];
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
