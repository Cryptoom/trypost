<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Exceptions\Media\StoryVideoBuildException;
use App\Models\PostPlatform;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * PATCH:story-motion-instagram: the chain that turns a Story photo into a
 * video, shared by the Facebook and the Instagram publisher: download, fit to
 * 1080x1920, best-effort AI music, then the camera-move render of the
 * workspace's Story video look (StoryMotionRenderer) with the audio muxed in.
 *
 * Facebook needs a video, so its caller asks for the held-frame fallback
 * (`$heldFrameFallback = true`): whatever goes wrong with the camera move ends
 * in a held-frame MP4. Instagram can publish the photo itself, so its caller
 * asks for the camera move only and gets null when there is none.
 *
 * Every temp file of the chain is removed, except the returned video, which
 * the caller owns.
 *
 * @phpstan-type StoryMotion array{workspace?: ?Workspace, postPlatform?: ?PostPlatform, startedAt?: ?CarbonInterface, active?: bool}
 */
class StoryVideoBuilder
{
    /**
     * Whether a photo story takes the motion path: the installation's master
     * switch is on AND the workspace turned Story videos on (for Instagram:
     * AND its "also for Instagram Stories" switch). A broken setup gets one
     * info line with the reason, the normal states (switch off, workspace
     * off, Instagram off) log nothing.
     */
    public function motionActive(?Workspace $workspace, bool $forInstagram = false): bool
    {
        if ($workspace === null) {
            return false;
        }

        $skipReason = app(StoryMotionRenderer::class)->skipReason($workspace);

        // `disabled` (master switch off) and `workspace_off` (the workspace has not turned Story videos on) are
        // the normal states, only a broken setup (`no_url`, `no_token`) is worth a line.
        if ($skipReason !== null && ! in_array($skipReason, ['disabled', 'workspace_off'], true)) {
            Log::info('Story motion skipped', ['reason' => $skipReason]);
        }

        if ($skipReason !== null) {
            return false;
        }

        return ! $forInstagram || StoryMotionSettings::forWorkspace($workspace)->instagram;
    }

    /**
     * Builds the video for the photo at `$imageUrl`. Returns the path of a temp
     * file the caller owns, or null when `$heldFrameFallback` is off and there
     * is no camera-move video.
     *
     * @param  StoryMotion  $storyMotion
     * @param  array<string, mixed>  $storyFit
     *
     * @throws StoryVideoBuildException when the photo cannot be downloaded or (held-frame fallback on) converted
     */
    public function build(string $imageUrl, ?string $musicDescription, array $storyFit, array $storyMotion, string $tempPrefix, bool $heldFrameFallback): ?string
    {
        $imagePath = tempnam(sys_get_temp_dir(), $tempPrefix);
        $audioPath = null;

        try {
            $download = Http::withOptions(['sink' => $imagePath])
                ->timeout(600)
                ->get($imageUrl);

            if ($download->failed()) {
                throw new StoryVideoBuildException(StoryVideoBuildException::DOWNLOAD, $download->status());
            }

            // PATCH:story-photo-fit: fit to 1080x1920 first, so music and video use the final frame.
            $fittedPath = app(StoryImageFitter::class)->fitOrKeep($imagePath, data_get($storyFit, 'mode'), data_get($storyFit, 'rect'));

            $durationSeconds = (int) config('trypost.platforms.facebook.story_photo_duration_seconds');

            $audioPath = app(StoryMusicGenerator::class)->generate($fittedPath, $durationSeconds, $musicDescription);

            try {
                $videoPath = $this->renderMotionVideo($fittedPath, $durationSeconds, $audioPath, $storyMotion);

                if ($videoPath === null && $heldFrameFallback) {
                    $videoPath = app(ImageToVideoConverter::class)->convert($fittedPath, $durationSeconds, $audioPath);
                }
            } catch (RuntimeException $e) {
                throw new StoryVideoBuildException(StoryVideoBuildException::CONVERT, null, $e->getMessage());
            }

            return $videoPath;
        } finally {
            foreach ([$imagePath, $fittedPath ?? null, $audioPath] as $path) {
                if ($path !== null && file_exists($path) && ! unlink($path)) {
                    Log::warning('Story temp file cleanup failed', ['path' => $path]);
                }
            }
        }
    }

    /**
     * The camera-move video with audio, or null whenever motion is off for
     * the workspace or anything about it goes wrong. Every temp file this
     * creates is removed unless it is returned.
     *
     * @param  StoryMotion  $storyMotion
     */
    private function renderMotionVideo(string $fittedPath, int $durationSeconds, ?string $audioPath, array $storyMotion): ?string
    {
        if (! data_get($storyMotion, 'active')) {
            return null;
        }

        $workspace = data_get($storyMotion, 'workspace');
        $postPlatform = data_get($storyMotion, 'postPlatform');

        if (! $workspace instanceof Workspace || ! $postPlatform instanceof PostPlatform) {
            return null;
        }

        $silentPath = app(StoryMotionRenderer::class)->render($fittedPath, $durationSeconds, $workspace, $postPlatform, data_get($storyMotion, 'startedAt'));

        if ($silentPath === null) {
            return null;
        }

        $finalPath = null;

        try {
            $finalPath = app(ImageToVideoConverter::class)->addAudioTrack($silentPath, $durationSeconds, $audioPath);

            $problem = app(StoryVideoInspector::class)->problem($finalPath, $durationSeconds, expectAudio: true);

            if ($problem === null) {
                return $finalPath;
            }

            Log::warning('Story motion render fell back to the still frame', ['reason' => 'invalid_output', 'problem' => $problem]);
        } catch (RuntimeException) {
            Log::warning('Story motion render fell back to the still frame', ['reason' => 'mux_failed']);
        } catch (Throwable) {
            Log::warning('Story motion render fell back to the still frame', ['reason' => 'error']);
        } finally {
            if (file_exists($silentPath)) {
                @unlink($silentPath);
            }
        }

        if ($finalPath !== null && file_exists($finalPath)) {
            @unlink($finalPath);
        }

        return null;
    }
}
