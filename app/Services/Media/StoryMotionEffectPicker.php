<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status;
use App\Enums\Workspace\StoryMotionEffect;
use App\Enums\Workspace\StoryMotionMode;
use App\Models\PostPlatform;
use App\Models\Workspace;

/**
 * PATCH:story-motion-settings: which of the selected effects a Story gets. No
 * stored state: nothing is pinned or written back, so a retry of the same
 * Story picks the same effect again as long as nothing else was published in
 * between.
 *
 * - fixed:  the first selected effect in the canonical order (drift, focus, snap, sparkle)
 * - random: crc32 of the PostPlatform id modulo the number of selected effects
 * - rotate: the number of already published Facebook Stories of the workspace
 *           modulo the number of selected effects
 */
class StoryMotionEffectPicker
{
    public function pick(StoryMotionSettings $settings, Workspace $workspace, PostPlatform $postPlatform): StoryMotionEffect
    {
        $effects = $settings->effects;
        $count = count($effects);

        if ($count === 1) {
            return $effects[0];
        }

        return $effects[match ($settings->mode) {
            StoryMotionMode::Fixed => 0,
            StoryMotionMode::Random => crc32((string) $postPlatform->id) % $count,
            StoryMotionMode::Rotate => $this->publishedStoryCount($workspace) % $count,
        }];
    }

    private function publishedStoryCount(Workspace $workspace): int
    {
        return PostPlatform::query()
            ->where('content_type', ContentType::FacebookStory)
            ->where('status', Status::Published)
            ->whereHas('post', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->count();
    }
}
