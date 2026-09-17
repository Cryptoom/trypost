<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Post;
use App\Models\PostPlatform;
use Illuminate\Validation\Rule;

/**
 * Single source of truth for the optional `post_platform_ids` parameter shared
 * by the three MCP attach tools (attach-media-from-upload-tool,
 * attach-media-from-url-tool, attach-existing-asset-tool): narrows the media
 * just attached to specific platforms via the `media_post_platform` pivot
 * (TPX-04) instead of leaving it available to every enabled platform.
 *
 * Omitted or empty is today's behaviour for all three tools: no pivot rows
 * are written, and PostPlatform::scopedMediaItems() already falls back to
 * "every post media item" when a platform has no selection.
 */
class PostPlatformMediaScope
{
    /**
     * IDOR-scoped: only post_platform rows belonging to THIS post can be
     * targeted, mirroring the `platforms.*.id` exists rule in UpdatePostTool.
     *
     * @return array<string, mixed>
     */
    public static function rules(Post $post): array
    {
        return [
            'post_platform_ids' => ['sometimes', 'array'],
            'post_platform_ids.*' => [
                'uuid',
                Rule::exists('post_platforms', 'id')->where('post_id', $post->id),
            ],
        ];
    }

    /**
     * Attach the given media ids to each named platform's pivot. A no-op
     * when either list is empty, so the untouched platforms keep publishing
     * every post media item (today's behaviour).
     *
     * Uses syncWithoutDetaching rather than attach() so a repeated call with
     * the same media/platform pair (e.g. re-attaching the same asset) never
     * hits the pivot's unique constraint.
     *
     * @param  array<int, string>  $postPlatformIds
     * @param  array<int, string>  $mediaIds
     */
    public static function apply(Post $post, array $postPlatformIds, array $mediaIds): void
    {
        if ($postPlatformIds === [] || $mediaIds === []) {
            return;
        }

        $post->postPlatforms()
            ->whereIn('id', $postPlatformIds)
            ->get()
            ->each(fn (PostPlatform $postPlatform) => $postPlatform->media()->syncWithoutDetaching($mediaIds));
    }
}
