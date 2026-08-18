<?php

declare(strict_types=1);

namespace App\Actions\Welcome;

use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\SocialAccount;
use App\Services\Social\TokenRedactor;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FetchLatestSocialPost
{
    private const MISSED_VIEWS_PER_NETWORK = 1000;

    /**
     * Latest public post on a connected account, when that network exposes
     * impression/reach/view analytics. Other networks skip this step.
     *
     * @return array{id: string, caption: string|null, media_url: string|null, permalink: string|null, published_at: string|null, impressions: int|null, reach: array{network: string, network_value: string, others: list<array{value: string, label: string, views: int}>, each_views: int, extra_views: int}}|null
     */
    public function handle(SocialAccount $account): ?array
    {
        if ($account->status !== Status::Connected) {
            return null;
        }

        if (! $account->platform->supportsImpressionAnalytics()) {
            return null;
        }

        try {
            $post = match ($account->platform) {
                Platform::Instagram, Platform::InstagramFacebook => $this->instagram($account),
                Platform::Facebook => $this->facebook($account),
                Platform::X => $this->x($account),
                Platform::Threads => $this->threads($account),
                Platform::TikTok => $this->tiktok($account),
                Platform::YouTube => $this->youtube($account),
                Platform::Pinterest => $this->pinterest($account),
                Platform::LinkedInPage => $this->linkedinPage($account),
                default => null,
            };

            return $post === null ? null : $this->withReach($account, $post);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * @return array{id: string, caption: string|null, media_url: string|null, permalink: string|null, published_at: string|null, impressions: int|null}|null
     */
    private function instagram(SocialAccount $account): ?array
    {
        $base = $account->platform->instagramGraphBaseUrl();
        $response = $this->http()
            ->get("{$base}/{$account->platform_user_id}/media", [
                'fields' => 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp',
                'limit' => 1,
                'access_token' => $account->access_token,
            ]);

        if ($response->failed()) {
            $this->logFailure('Instagram', $response->body());

            return null;
        }

        $item = data_get($response->json(), 'data.0');

        if (! is_array($item)) {
            return null;
        }

        $id = (string) data_get($item, 'id');
        $mediaUrl = data_get($item, 'media_type') === 'VIDEO'
            ? data_get($item, 'thumbnail_url')
            : data_get($item, 'media_url');

        return $this->post(
            $id,
            data_get($item, 'caption'),
            is_string($mediaUrl) ? $mediaUrl : null,
            data_get($item, 'permalink'),
            data_get($item, 'timestamp'),
            $this->graphInsights($account->platform->instagramGraphBaseUrl(), $id, $account->access_token, ['views', 'reach']),
        );
    }

    /**
     * @return array{id: string, caption: string|null, media_url: string|null, permalink: string|null, published_at: string|null, impressions: int|null}|null
     */
    private function facebook(SocialAccount $account): ?array
    {
        $base = (string) config('trypost.platforms.facebook.graph_api');
        $response = $this->http()
            ->get("{$base}/{$account->platform_user_id}/posts", [
                'fields' => 'id,message,full_picture,permalink_url,created_time',
                'limit' => 1,
                'access_token' => $account->access_token,
            ]);

        if ($response->failed()) {
            $this->logFailure('Facebook', $response->body());

            return null;
        }

        $item = data_get($response->json(), 'data.0');

        if (! is_array($item)) {
            return null;
        }

        $id = (string) data_get($item, 'id');

        return $this->post(
            $id,
            data_get($item, 'message'),
            data_get($item, 'full_picture'),
            data_get($item, 'permalink_url'),
            data_get($item, 'created_time'),
            $this->graphInsights((string) config('trypost.platforms.facebook.graph_api'), $id, $account->access_token, ['post_impressions']),
        );
    }

    /**
     * @return array{id: string, caption: string|null, media_url: string|null, permalink: string|null, published_at: string|null, impressions: int|null}|null
     */
    private function x(SocialAccount $account): ?array
    {
        $base = (string) config('trypost.platforms.x.api');
        $response = $this->http()
            ->withToken($account->access_token)
            ->get("{$base}/users/{$account->platform_user_id}/tweets", [
                'max_results' => 5,
                'tweet.fields' => 'created_at,text,attachments,public_metrics',
                'expansions' => 'attachments.media_keys',
                'media.fields' => 'url,preview_image_url',
            ]);

        if ($response->failed()) {
            $this->logFailure('X', $response->body());

            return null;
        }

        $item = data_get($response->json(), 'data.0');

        if (! is_array($item)) {
            return null;
        }

        $mediaKey = data_get($item, 'attachments.media_keys.0');
        $mediaUrl = null;

        if (is_string($mediaKey)) {
            $media = collect(data_get($response->json(), 'includes.media', []))
                ->first(fn (mixed $row): bool => is_array($row) && data_get($row, 'media_key') === $mediaKey);

            $mediaUrl = is_array($media)
                ? (data_get($media, 'preview_image_url') ?? data_get($media, 'url'))
                : null;
        }

        $id = (string) data_get($item, 'id');

        $impressions = data_get($item, 'public_metrics.impression_count');

        return $this->post(
            $id,
            data_get($item, 'text'),
            is_string($mediaUrl) ? $mediaUrl : null,
            "https://x.com/i/web/status/{$id}",
            data_get($item, 'created_at'),
            is_numeric($impressions) ? (int) $impressions : null,
        );
    }

    /**
     * @return array{id: string, caption: string|null, media_url: string|null, permalink: string|null, published_at: string|null, impressions: int|null}|null
     */
    private function threads(SocialAccount $account): ?array
    {
        $base = (string) config('trypost.platforms.threads.graph_api');
        $response = $this->http()
            ->get("{$base}/{$account->platform_user_id}/threads", [
                'fields' => 'id,text,media_type,permalink,timestamp,media_url,thumbnail_url',
                'limit' => 1,
                'access_token' => $account->access_token,
            ]);

        if ($response->failed()) {
            $this->logFailure('Threads', $response->body());

            return null;
        }

        $item = data_get($response->json(), 'data.0');

        if (! is_array($item)) {
            return null;
        }

        $id = (string) data_get($item, 'id');
        $mediaUrl = data_get($item, 'thumbnail_url') ?? data_get($item, 'media_url');

        return $this->post(
            $id,
            data_get($item, 'text'),
            is_string($mediaUrl) ? $mediaUrl : null,
            data_get($item, 'permalink'),
            data_get($item, 'timestamp'),
            $this->graphInsights((string) config('trypost.platforms.threads.graph_api'), $id, $account->access_token, ['views']),
        );
    }

    /**
     * @return array{id: string, caption: string|null, media_url: string|null, permalink: string|null, published_at: string|null, impressions: int|null}|null
     */
    private function tiktok(SocialAccount $account): ?array
    {
        $base = (string) config('trypost.platforms.tiktok.api');
        $response = $this->http()
            ->asJson()
            ->withToken($account->access_token)
            ->post("{$base}/video/list/?fields=id,title,cover_image_url,share_url,create_time", [
                'max_count' => 1,
            ]);

        if ($response->failed()) {
            $this->logFailure('TikTok', $response->body());

            return null;
        }

        $item = data_get($response->json(), 'data.videos.0');

        if (! is_array($item)) {
            return null;
        }

        $id = (string) data_get($item, 'id');
        $created = data_get($item, 'create_time');

        return $this->post(
            $id,
            data_get($item, 'title'),
            data_get($item, 'cover_image_url'),
            data_get($item, 'share_url'),
            is_numeric($created) ? now()->setTimestamp((int) $created)->toIso8601String() : null,
            $this->tiktokViews($account, $id),
        );
    }

    /**
     * @return array{id: string, caption: string|null, media_url: string|null, permalink: string|null, published_at: string|null, impressions: int|null}|null
     */
    private function youtube(SocialAccount $account): ?array
    {
        $base = (string) config('trypost.platforms.youtube.data_api');
        $response = $this->http()
            ->withToken($account->access_token)
            ->get("{$base}/search", [
                'part' => 'snippet',
                'forMine' => 'true',
                'type' => 'video',
                'order' => 'date',
                'maxResults' => 1,
            ]);

        if ($response->failed()) {
            $this->logFailure('YouTube', $response->body());

            return null;
        }

        $item = data_get($response->json(), 'items.0');

        if (! is_array($item)) {
            return null;
        }

        $videoId = (string) data_get($item, 'id.videoId');

        if ($videoId === '') {
            return null;
        }

        return $this->post(
            $videoId,
            data_get($item, 'snippet.title'),
            data_get($item, 'snippet.thumbnails.high.url') ?? data_get($item, 'snippet.thumbnails.default.url'),
            "https://www.youtube.com/watch?v={$videoId}",
            data_get($item, 'snippet.publishedAt'),
            $this->youtubeViews($account, $videoId),
        );
    }

    /**
     * @return array{id: string, caption: string|null, media_url: string|null, permalink: string|null, published_at: string|null, impressions: int|null}|null
     */
    private function pinterest(SocialAccount $account): ?array
    {
        $base = (string) config('trypost.platforms.pinterest.api');
        $response = $this->http()
            ->withToken($account->access_token)
            ->get("{$base}/pins", [
                'page_size' => 1,
            ]);

        if ($response->failed()) {
            $this->logFailure('Pinterest', $response->body());

            return null;
        }

        $item = data_get($response->json(), 'items.0');

        if (! is_array($item)) {
            return null;
        }

        $id = (string) data_get($item, 'id');

        return $this->post(
            $id,
            data_get($item, 'description') ?? data_get($item, 'title'),
            data_get($item, 'media.images.400x300.url') ?? data_get($item, 'media.images.150x150.url'),
            null,
            data_get($item, 'created_at'),
            $this->pinterestImpressions($account, $id),
        );
    }

    /**
     * @return array{id: string, caption: string|null, media_url: string|null, permalink: string|null, published_at: string|null, impressions: int|null}|null
     */
    private function linkedinPage(SocialAccount $account): ?array
    {
        $base = (string) config('trypost.platforms.linkedin-page.api').'/rest';
        $author = rawurlencode('urn:li:organization:'.$account->platform_user_id);
        $response = $this->http()
            ->withToken($account->access_token)
            ->withHeaders([
                'Linkedin-Version' => '202601',
                'X-Restli-Protocol-Version' => '2.0.0',
            ])
            ->get("{$base}/posts?q=author&author={$author}&count=1&sortBy=LAST_MODIFIED");

        if ($response->failed()) {
            $this->logFailure('LinkedIn Page', $response->body());

            return null;
        }

        $item = data_get($response->json(), 'elements.0');

        if (! is_array($item)) {
            return null;
        }

        $id = (string) data_get($item, 'id');
        $createdAt = data_get($item, 'createdAt');

        return $this->post(
            $id,
            data_get($item, 'commentary'),
            null,
            $id !== '' ? "https://www.linkedin.com/feed/update/{$id}" : null,
            is_numeric($createdAt)
                ? now()->setTimestamp((int) ((int) $createdAt / 1000))->toIso8601String()
                : null,
        );
    }

    private function http(): PendingRequest
    {
        return Http::timeout(8)->connectTimeout(3);
    }

    /**
     * @return array{id: string, caption: string|null, media_url: string|null, permalink: string|null, published_at: string|null, impressions: int|null}|null
     */
    private function post(
        string $id,
        mixed $caption,
        mixed $mediaUrl,
        mixed $permalink,
        mixed $publishedAt,
        ?int $impressions = null,
    ): ?array {
        if ($id === '') {
            return null;
        }

        return [
            'id' => $id,
            'caption' => is_string($caption) && $caption !== '' ? $caption : null,
            'media_url' => is_string($mediaUrl) && $mediaUrl !== '' ? $mediaUrl : null,
            'permalink' => is_string($permalink) && $permalink !== '' ? $permalink : null,
            'published_at' => is_string($publishedAt) && $publishedAt !== '' ? $publishedAt : null,
            'impressions' => $impressions,
        ];
    }

    /**
     * @param  array{id: string, caption: string|null, media_url: string|null, permalink: string|null, published_at: string|null, impressions: int|null}  $post
     * @return array{id: string, caption: string|null, media_url: string|null, permalink: string|null, published_at: string|null, impressions: int|null, reach: array{network: string, network_value: string, others: list<array{value: string, label: string, views: int}>, each_views: int, extra_views: int}}
     */
    private function withReach(SocialAccount $account, array $post): array
    {
        $eachViews = max(self::MISSED_VIEWS_PER_NETWORK, $post['impressions'] ?? 0);
        $others = $this->missedNetworks($account->platform, $eachViews);

        return [
            ...$post,
            'reach' => [
                'network' => $this->networkLabel($account->platform),
                'network_value' => $account->platform->network(),
                'others' => $others,
                'each_views' => $eachViews,
                'extra_views' => $eachViews * count($others),
            ],
        ];
    }

    /**
     * @return list<array{value: string, label: string, views: int}>
     */
    private function missedNetworks(Platform $platform, int $eachViews): array
    {
        return collect($platform->welcomeReachComparisons())
            ->filter(fn (Platform $candidate): bool => $candidate->isConnectable())
            ->reject(fn (Platform $candidate): bool => $candidate->network() === $platform->network())
            ->take(2)
            ->map(fn (Platform $candidate): array => [
                'value' => $candidate->value,
                'label' => $this->networkLabel($candidate),
                'views' => $eachViews,
            ])
            ->values()
            ->all();
    }

    private function networkLabel(Platform $platform): string
    {
        return match ($platform) {
            Platform::InstagramFacebook => Platform::Instagram->label(),
            Platform::YouTube => 'YouTube',
            Platform::Facebook => 'Facebook',
            default => $platform->label(),
        };
    }

    /**
     * @param  list<string>  $metrics
     */
    private function graphInsights(string $base, string $id, ?string $token, array $metrics): ?int
    {
        if ($id === '' || $token === null || $token === '') {
            return null;
        }

        foreach ($metrics as $metric) {
            $response = $this->http()->get("{$base}/{$id}/insights", [
                'metric' => $metric,
                'access_token' => $token,
            ]);

            if ($response->failed()) {
                continue;
            }

            $value = data_get($response->json(), 'data.0.values.0.value');

            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return null;
    }

    private function tiktokViews(SocialAccount $account, string $videoId): ?int
    {
        if ($videoId === '') {
            return null;
        }

        $base = (string) config('trypost.platforms.tiktok.api');
        $response = $this->http()
            ->asJson()
            ->withToken($account->access_token)
            ->post("{$base}/video/query/?fields=id,view_count", [
                'filters' => ['video_ids' => [$videoId]],
            ]);

        if ($response->failed()) {
            return null;
        }

        $views = data_get($response->json(), 'data.videos.0.view_count');

        return is_numeric($views) ? (int) $views : null;
    }

    private function youtubeViews(SocialAccount $account, string $videoId): ?int
    {
        $base = (string) config('trypost.platforms.youtube.data_api');
        $response = $this->http()
            ->withToken($account->access_token)
            ->get("{$base}/videos", [
                'part' => 'statistics',
                'id' => $videoId,
            ]);

        if ($response->failed()) {
            return null;
        }

        $views = data_get($response->json(), 'items.0.statistics.viewCount');

        return is_numeric($views) ? (int) $views : null;
    }

    private function pinterestImpressions(SocialAccount $account, string $pinId): ?int
    {
        if ($pinId === '') {
            return null;
        }

        $base = (string) config('trypost.platforms.pinterest.api');
        $response = $this->http()
            ->withToken($account->access_token)
            ->get("{$base}/pins/{$pinId}/analytics", [
                'start_date' => now()->subDays(90)->format('Y-m-d'),
                'end_date' => now()->format('Y-m-d'),
                'metric_types' => 'IMPRESSION',
            ]);

        if ($response->failed()) {
            return null;
        }

        $impressions = data_get($response->json(), 'all.summary_metrics.IMPRESSION');

        return is_numeric($impressions) ? (int) $impressions : null;
    }

    private function logFailure(string $platform, string $body): void
    {
        Log::warning("{$platform} latest post fetch failed", [
            'body' => mb_substr((string) TokenRedactor::redact($body), 0, 500),
        ]);
    }
}
