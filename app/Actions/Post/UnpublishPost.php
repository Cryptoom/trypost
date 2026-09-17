<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Services\Social\BlueskyPublisher;
use App\Services\Social\Discord\DiscordPublisher;
use App\Services\Social\FacebookPublisher;
use App\Services\Social\InstagramPublisher;
use App\Services\Social\LinkedInPagePublisher;
use App\Services\Social\LinkedInPublisher;
use App\Services\Social\MastodonPublisher;
use App\Services\Social\PinterestPublisher;
use App\Services\Social\Telegram\TelegramPublisher;
use App\Services\Social\ThreadsPublisher;
use App\Services\Social\TikTokPublisher;
use App\Services\Social\XPublisher;
use App\Services\Social\YouTubePublisher;
use Throwable;

class UnpublishPost
{
    /**
     * Deletes every already-published platform of the post remotely, then
     * resets it locally back to Pending. Processes every PostPlatform row
     * with a platform_post_id, optionally narrowed to a subset via
     * $postPlatformIds. Best-effort: one platform failing, or simply not
     * supporting deletion at all (e.g. TikTok, see CLAUDE.md: there is no
     * delete/unpublish endpoint), never blocks the others.
     *
     * @param  array<int, string>|null  $postPlatformIds
     * @return array{
     *     unpublished: list<PostPlatform>,
     *     failed: list<array{post_platform: PostPlatform, message: string}>,
     *     unsupported: list<PostPlatform>,
     * }
     */
    public static function execute(Post $post, ?array $postPlatformIds = null): array
    {
        $query = $post->postPlatforms()->whereNotNull('platform_post_id');

        if ($postPlatformIds !== null) {
            $query->whereIn('id', $postPlatformIds);
        }

        $rows = $query->get();

        $unpublished = [];
        $failed = [];
        $unsupported = [];

        foreach ($rows as $postPlatform) {
            $publisher = self::resolveDeletePublisher($postPlatform->platform);

            if ($publisher === null) {
                $unsupported[] = $postPlatform;

                continue;
            }

            try {
                $publisher->delete($postPlatform);
                $postPlatform->markAsUnpublished();
                $unpublished[] = $postPlatform;
            } catch (Throwable $e) {
                $failed[] = ['post_platform' => $postPlatform, 'message' => $e->getMessage()];
            }
        }

        self::updatePostStatus($post, $rows->count(), count($unpublished));

        return [
            'unpublished' => $unpublished,
            'failed' => $failed,
            'unsupported' => $unsupported,
        ];
    }

    /**
     * A platform's delete capability is opt-in. As soon as its publisher
     * gains a delete() method, it is picked up here automatically, with no
     * further change needed to this dispatch. Today no publisher has one,
     * so every platform currently resolves to null (unsupported).
     */
    private static function resolveDeletePublisher(SocialPlatform $platform): ?object
    {
        $publisher = self::getPublisher($platform);

        return method_exists($publisher, 'delete') ? $publisher : null;
    }

    /**
     * Mirrors App\Jobs\PublishToSocialPlatform::getPublisher(): one
     * publisher instance per platform, resolved through the container.
     */
    private static function getPublisher(SocialPlatform $platform): object
    {
        return match ($platform) {
            SocialPlatform::LinkedIn => app(LinkedInPublisher::class),
            SocialPlatform::LinkedInPage => app(LinkedInPagePublisher::class),
            SocialPlatform::X => app(XPublisher::class),
            SocialPlatform::TikTok => app(TikTokPublisher::class),
            SocialPlatform::YouTube => app(YouTubePublisher::class),
            SocialPlatform::Facebook => app(FacebookPublisher::class),
            SocialPlatform::Instagram, SocialPlatform::InstagramFacebook => app(InstagramPublisher::class),
            SocialPlatform::Threads => app(ThreadsPublisher::class),
            SocialPlatform::Pinterest => app(PinterestPublisher::class),
            SocialPlatform::Bluesky => app(BlueskyPublisher::class),
            SocialPlatform::Mastodon => app(MastodonPublisher::class),
            SocialPlatform::Telegram => app(TelegramPublisher::class),
            SocialPlatform::Discord => app(DiscordPublisher::class),
        };
    }

    /**
     * Recomputes the post's status from the outcome of this call, scoped to
     * the candidate rows that were actually processed. Three cases:
     *
     * 1. Every candidate unpublished: back to Draft (markAsUnpublished).
     * 2. Some, but not all, unpublished: PartiallyPublished (at least one
     *    candidate is still Published, either failed or unsupported).
     * 3. None unpublished (all failed and/or unsupported, e.g. a
     *    TikTok-only post): no status change, the post stays exactly as
     *    it was.
     */
    private static function updatePostStatus(Post $post, int $totalCandidates, int $unpublishedCount): void
    {
        if ($totalCandidates === 0 || $unpublishedCount === 0) {
            return;
        }

        if ($unpublishedCount === $totalCandidates) {
            $post->markAsUnpublished();

            return;
        }

        $post->markAsPartiallyPublished();
    }
}
