<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\Status as PlatformStatus;
use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Jobs\PublishToSocialPlatform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Support\Social\TikTokPhotoDerivativeCleaner;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RetryFailedPost extends Command
{
    protected $signature = 'posts:retry
        {post : ID of the post whose failed platforms should be retried}
        {--platform= : Retry only this platform (for example, threads or tiktok)}';

    protected $description = 'Retry failed platforms for a post as new publish attempts';

    public function __construct(
        private readonly TikTokPhotoDerivativeCleaner $tiktokPhotoDerivativeCleaner,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $post = Post::query()->find((string) $this->argument('post'));

        if (! $post) {
            $this->error('Post not found.');

            return self::FAILURE;
        }

        if (! $this->isRetryable($post)) {
            $this->error('Only failed or partially published posts can be retried.');

            return self::FAILURE;
        }

        $platform = $this->requestedPlatform();

        if ($platform === false) {
            return self::FAILURE;
        }

        $failedPlatforms = $this->failedPlatforms($post, $platform);

        if ($failedPlatforms->isEmpty()) {
            $this->warn('No failed enabled platforms matched this post.');

            return self::FAILURE;
        }

        $this->table(
            ['Post platform ID', 'Platform', 'Account', 'Last error'],
            $failedPlatforms->map(fn (PostPlatform $postPlatform): array => [
                $postPlatform->id,
                $postPlatform->platform->value,
                $postPlatform->display_username ?? '—',
                $postPlatform->error_message ?? '—',
            ])->all(),
        );

        if (! $this->confirm('Start new publish attempts for these failed platforms?')) {
            $this->info('Retry cancelled.');

            return self::SUCCESS;
        }

        $retryEntries = DB::transaction(function () use ($post, $platform): array {
            $lockedPost = Post::query()->lockForUpdate()->find($post->id);

            if (! $lockedPost || ! $this->isRetryable($lockedPost)) {
                return [];
            }

            $platforms = $this->failedPlatforms($lockedPost, $platform, lockForUpdate: true);
            $entries = $platforms->map(fn (PostPlatform $postPlatform): array => [
                'id' => $postPlatform->id,
                'platform' => $postPlatform->platform,
                'error_context' => $postPlatform->error_context,
            ])->all();

            $platforms->each(function (PostPlatform $postPlatform): void {
                $postPlatform->update([
                    'status' => PlatformStatus::Pending,
                    'platform_post_id' => null,
                    'platform_url' => null,
                    'error_message' => null,
                    'error_context' => null,
                    'published_at' => null,
                ]);
            });

            if ($entries !== []) {
                $lockedPost->update(['status' => PostStatus::Publishing]);
            }

            return $entries;
        });

        if ($retryEntries === []) {
            $this->warn('The post changed while the command was running; nothing was retried.');

            return self::FAILURE;
        }

        foreach ($retryEntries as $entry) {
            if ($entry['platform'] === SocialPlatform::TikTok) {
                $this->tiktokPhotoDerivativeCleaner->cleanup($entry['error_context'], $entry['id']);
            }

            $postPlatform = PostPlatform::query()->findOrFail($entry['id']);
            PublishToSocialPlatform::dispatch($postPlatform);
        }

        Log::info('Failed post platforms queued for manual retry', [
            'post_id' => $post->id,
            'post_platform_ids' => array_column($retryEntries, 'id'),
            'platform_filter' => $platform?->value,
        ]);

        $this->info(count($retryEntries).' publish attempt(s) queued.');

        return self::SUCCESS;
    }

    private function requestedPlatform(): SocialPlatform|false|null
    {
        $platform = $this->option('platform');

        if ($platform === null) {
            return null;
        }

        if (! is_string($platform) || SocialPlatform::tryFrom($platform) === null) {
            $this->error('Unknown platform. Use one of: '.implode(', ', array_column(SocialPlatform::cases(), 'value')).'.');

            return false;
        }

        return SocialPlatform::from($platform);
    }

    private function isRetryable(Post $post): bool
    {
        return in_array($post->status, [PostStatus::Failed, PostStatus::PartiallyPublished], true);
    }

    /**
     * @return Collection<int, PostPlatform>
     */
    private function failedPlatforms(Post $post, ?SocialPlatform $platform, bool $lockForUpdate = false): Collection
    {
        $query = PostPlatform::query()
            ->with('socialAccount')
            ->where('post_id', $post->id)
            ->enabled()
            ->where('status', PlatformStatus::Failed)
            ->when($platform, fn (Builder $query) => $query->where('platform', $platform));

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get();
    }
}
