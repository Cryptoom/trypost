<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\InstagramPublishException;
use App\Exceptions\Social\SocialPublishException;
use App\Models\PostPlatform;
use App\Services\Social\Concerns\CropsImageForAspectRatio;
use App\Services\Social\Concerns\HasSocialHttpClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

class InstagramPublisher
{
    use CropsImageForAspectRatio;
    use HasSocialHttpClient;

    private string $baseUrl;

    private const int STATUS_RETRY_DELAY_SECONDS = 10;

    private const int STATUS_MAX_RETRIES = 90;

    public function publish(PostPlatform $postPlatform): array
    {
        $this->validateContentLength($postPlatform);

        $account = $postPlatform->socialAccount;
        $this->baseUrl = $account->platform->instagramGraphBaseUrl();

        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        $instagramId = $account->platform_user_id;
        $accessToken = $account->access_token;

        $content = $postPlatform->post->content ? app(ContentSanitizer::class)->sanitize($postPlatform->post->content, $postPlatform->platform) : null;

        $pendingWorkflow = data_get($postPlatform->error_context, 'instagram_workflow');

        if (is_array($pendingWorkflow)) {
            return $this->resumeWorkflow($instagramId, $accessToken, $content, $pendingWorkflow);
        }

        $media = $postPlatform->post->mediaItems;

        if ($media->isEmpty()) {
            throw new InstagramPublishException(
                userMessage: 'Instagram requires at least one image or video.',
                category: ErrorCategory::MediaFormat,
            );
        }

        $firstMedia = $media->first();
        $contentType = $postPlatform->content_type;

        $aspectRatio = data_get($postPlatform->meta, 'aspect_ratio');

        return match ($contentType) {
            ContentType::InstagramReel => $this->publishReel($instagramId, $accessToken, $content, $firstMedia),
            ContentType::InstagramStory => $this->publishStory($instagramId, $accessToken, $firstMedia),
            ContentType::InstagramFeed => $this->publishFeed($instagramId, $accessToken, $content, $media, $aspectRatio),
            default => throw new InstagramPublishException(
                userMessage: "Unsupported Instagram content type: {$contentType?->value}",
                category: ErrorCategory::ContentPolicy,
            ),
        };
    }

    private function publishFeed(string $instagramId, string $accessToken, ?string $content, $media, ?string $aspectRatio): array
    {
        if ($media->count() > 1) {
            return $this->publishCarousel($instagramId, $accessToken, $content, $media, $aspectRatio);
        }

        $firstMedia = $media->first();

        if ($firstMedia->isVideo()) {
            return $this->publishReel($instagramId, $accessToken, $content, $firstMedia);
        }

        return $this->publishSingleImage($instagramId, $accessToken, $content, $firstMedia, $aspectRatio);
    }

    private function publishSingleImage(string $instagramId, string $accessToken, ?string $content, $media, ?string $aspectRatio): array
    {
        $imageUrl = $this->cropImageForAspectRatio($media->url, $aspectRatio);

        $params = [
            'image_url' => $imageUrl,
            'caption' => $content,
            'access_token' => $accessToken,
        ];

        $alt = $media->altTextFor(Platform::Instagram);

        if ($alt !== null) {
            $params['alt_text'] = $alt;
        }

        // Step 1: Create container
        $containerResponse = $this->socialHttp()->post("{$this->baseUrl}/{$instagramId}/media", $params);

        if ($containerResponse->failed()) {
            Log::error('Instagram container creation failed', [
                'status' => $containerResponse->status(),
                'body' => $this->redactResponseBody($containerResponse->body()),
            ]);
            $this->handleApiError($containerResponse);
        }

        $containerId = $containerResponse->json()['id'] ?? null;

        if (! $containerId) {
            throw new InstagramPublishException(
                userMessage: 'Instagram container creation failed: No container ID returned',
                category: ErrorCategory::ServerError,
            );
        }

        // Step 2: Wait for container to be ready
        return $this->finishContainer($instagramId, $accessToken, $containerId);
    }

    private function publishReel(string $instagramId, string $accessToken, ?string $content, $media): array
    {
        // Step 1: Create container for video/reel
        $containerResponse = $this->socialHttp()->post("{$this->baseUrl}/{$instagramId}/media", [
            'video_url' => $media->url,
            'caption' => $content,
            'media_type' => 'REELS',
            'access_token' => $accessToken,
        ]);

        if ($containerResponse->failed()) {
            Log::error('Instagram reel container creation failed', [
                'status' => $containerResponse->status(),
                'body' => $this->redactResponseBody($containerResponse->body()),
            ]);
            $this->handleApiError($containerResponse);
        }

        $containerId = $containerResponse->json()['id'] ?? null;

        if (! $containerId) {
            throw new InstagramPublishException(
                userMessage: 'Instagram reel container creation failed: No container ID returned',
                category: ErrorCategory::ServerError,
            );
        }

        // Wait for video processing
        return $this->finishContainer($instagramId, $accessToken, $containerId);
    }

    private function publishStory(string $instagramId, string $accessToken, $media): array
    {
        $isVideo = $media->isVideo();

        $params = [
            'media_type' => 'STORIES',
            'access_token' => $accessToken,
        ];

        if ($isVideo) {
            $params['video_url'] = $media->url;
        } else {
            $dimensions = ContentType::InstagramStory->aiImageDimensions();
            $params['image_url'] = $this->fitImageToCanvas($media->url, data_get($dimensions, 'width'), data_get($dimensions, 'height'));
        }

        // Step 1: Create story container
        $containerResponse = $this->socialHttp()->post("{$this->baseUrl}/{$instagramId}/media", $params);

        if ($containerResponse->failed()) {
            Log::error('Instagram story container creation failed', [
                'status' => $containerResponse->status(),
                'body' => $this->redactResponseBody($containerResponse->body()),
            ]);
            $this->handleApiError($containerResponse);
        }

        $containerId = $containerResponse->json()['id'] ?? null;

        if (! $containerId) {
            throw new InstagramPublishException(
                userMessage: 'Instagram story container creation failed: No container ID returned',
                category: ErrorCategory::ServerError,
            );
        }

        // Step 2: Wait for media processing
        return $this->finishContainer($instagramId, $accessToken, $containerId);
    }

    private function publishCarousel(string $instagramId, string $accessToken, ?string $content, $mediaCollection, ?string $aspectRatio): array
    {
        // Step 1: Create containers for each media item
        $childContainers = [];
        $processingChildContainers = [];

        foreach ($mediaCollection as $media) {
            $isVideo = $media->isVideo();

            $params = [
                'is_carousel_item' => 'true',
                'access_token' => $accessToken,
            ];

            if ($isVideo) {
                $params['video_url'] = $media->url;
                $params['media_type'] = 'VIDEO';
            } else {
                $params['image_url'] = $this->cropImageForAspectRatio($media->url, $aspectRatio);

                $alt = $media->altTextFor(Platform::Instagram);

                if ($alt !== null) {
                    $params['alt_text'] = $alt;
                }
            }

            $containerResponse = $this->socialHttp()->post("{$this->baseUrl}/{$instagramId}/media", $params);

            if ($containerResponse->failed()) {
                Log::error('Instagram carousel item creation failed', [
                    'body' => $this->redactResponseBody($containerResponse->body()),
                ]);

                continue;
            }

            $childId = $containerResponse->json()['id'] ?? null;

            if (! $childId) {
                Log::error('Instagram carousel item creation returned no ID', ['body' => $this->redactResponseBody($containerResponse->body())]);

                continue;
            }

            $childContainers[] = $childId;

            if ($isVideo) {
                $processingChildContainers[] = $childId;
            }
        }

        if (empty($childContainers)) {
            throw new InstagramPublishException(
                userMessage: 'Failed to create any carousel items',
                category: ErrorCategory::ServerError,
            );
        }

        return $this->finishCarousel($instagramId, $accessToken, $content, $childContainers, $processingChildContainers);
    }

    /**
     * @param  list<string>  $childContainers
     * @param  list<string>  $processingChildContainers
     */
    private function finishCarousel(string $instagramId, string $accessToken, ?string $content, array $childContainers, array $processingChildContainers): array
    {
        $workflow = [
            'stage' => 'carousel_children',
            'child_container_ids' => $childContainers,
            'processing_child_container_ids' => $processingChildContainers,
        ];

        foreach ($processingChildContainers as $childId) {
            $this->waitForMediaProcessing($childId, $accessToken, $workflow);
        }

        $carouselResponse = $this->socialHttp()->post("{$this->baseUrl}/{$instagramId}/media", [
            'media_type' => 'CAROUSEL',
            'caption' => $content,
            'children' => implode(',', $childContainers),
            'access_token' => $accessToken,
        ]);

        if ($carouselResponse->failed()) {
            Log::error('Instagram carousel container creation failed', [
                'body' => $this->redactResponseBody($carouselResponse->body()),
            ]);
            $this->handleApiError($carouselResponse);
        }

        $carouselId = $carouselResponse->json()['id'] ?? null;

        if (! $carouselId) {
            throw new InstagramPublishException(
                userMessage: 'Instagram carousel container creation failed: No container ID returned',
                category: ErrorCategory::ServerError,
            );
        }

        return $this->finishContainer($instagramId, $accessToken, $carouselId);
    }

    /**
     * @param  array<string, mixed>  $workflow
     */
    private function resumeWorkflow(string $instagramId, string $accessToken, ?string $content, array $workflow): array
    {
        if (data_get($workflow, 'stage') === 'final_container') {
            $containerId = data_get($workflow, 'container_id');

            if (is_string($containerId) && $containerId !== '') {
                return $this->finishContainer($instagramId, $accessToken, $containerId);
            }
        }

        if (data_get($workflow, 'stage') === 'carousel_children') {
            $children = data_get($workflow, 'child_container_ids');
            $processingChildren = data_get($workflow, 'processing_child_container_ids', []);

            if (is_array($children) && $children !== [] && is_array($processingChildren)) {
                return $this->finishCarousel(
                    $instagramId,
                    $accessToken,
                    $content,
                    array_values(array_filter($children, 'is_string')),
                    array_values(array_filter($processingChildren, 'is_string')),
                );
            }
        }

        throw new InstagramPublishException(
            userMessage: 'Instagram publish state is invalid and cannot be resumed.',
            category: ErrorCategory::ServerError,
        );
    }

    private function finishContainer(string $instagramId, string $accessToken, string $containerId): array
    {
        $this->waitForMediaProcessing($containerId, $accessToken, [
            'stage' => 'final_container',
            'container_id' => $containerId,
        ]);

        return $this->publishContainer($instagramId, $accessToken, $containerId);
    }

    private function publishContainer(string $instagramId, string $accessToken, string $containerId): array
    {
        $publishResponse = $this->socialHttp()->post("{$this->baseUrl}/{$instagramId}/media_publish", [
            'creation_id' => $containerId,
            'access_token' => $accessToken,
        ]);

        if ($publishResponse->failed()) {
            Log::error('Instagram publish failed', [
                'status' => $publishResponse->status(),
                'body' => $this->redactResponseBody($publishResponse->body()),
            ]);
            $this->handleApiError($publishResponse);
        }

        $mediaId = $publishResponse->json()['id'] ?? null;

        if (! $mediaId) {
            throw new InstagramPublishException(
                userMessage: 'Instagram publish failed: no media ID returned',
                category: ErrorCategory::ServerError,
            );
        }

        // Get permalink
        $permalinkResponse = $this->socialHttp()->get("{$this->baseUrl}/{$mediaId}", [
            'fields' => 'permalink',
            'access_token' => $accessToken,
        ]);

        $permalink = $permalinkResponse->json()['permalink'] ?? null;

        return [
            'id' => $mediaId,
            'url' => $permalink,
        ];
    }

    protected function cropFailureException(string $message): SocialPublishException
    {
        return new InstagramPublishException(
            userMessage: $message,
            category: ErrorCategory::ServerError,
        );
    }

    /**
     * @param  array<string, mixed>  $workflow
     */
    private function waitForMediaProcessing(string $containerId, string $accessToken, array $workflow): void
    {
        $statusResponse = $this->socialHttp()->get("{$this->baseUrl}/{$containerId}", [
            'fields' => 'status_code',
            'access_token' => $accessToken,
        ]);

        if ($statusResponse->failed()) {
            if ($statusResponse->status() !== 429 && $statusResponse->status() < 500) {
                $this->handleApiError($statusResponse);
            }

            throw $this->pendingContainerException($containerId, $workflow, $statusResponse->status());
        }

        $status = $statusResponse->json()['status_code'] ?? 'UNKNOWN';

        if ($status === 'FINISHED') {
            return;
        }

        if ($status === 'ERROR') {
            throw new InstagramPublishException(
                userMessage: 'Instagram media processing failed',
                category: ErrorCategory::ServerError,
            );
        }

        throw $this->pendingContainerException($containerId, $workflow);
    }

    /**
     * @param  array<string, mixed>  $workflow
     */
    private function pendingContainerException(string $containerId, array $workflow, ?int $httpStatus = null): PlatformUnavailableException
    {
        return new PlatformUnavailableException(
            message: "Instagram is still processing container {$containerId}",
            httpStatus: $httpStatus,
            context: ['instagram_workflow' => $workflow],
            retryDelaySeconds: self::STATUS_RETRY_DELAY_SECONDS,
            maxRetries: self::STATUS_MAX_RETRIES,
        );
    }

    private function handleApiError(Response $response): never
    {
        throw InstagramPublishException::fromApiResponse($response);
    }
}
