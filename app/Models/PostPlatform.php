<?php

declare(strict_types=1);

namespace App\Models;

use App\Dto\MediaItem;
use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status;
use App\Enums\SocialAccount\Platform as SocialPlatform;
use Database\Factories\PostPlatformFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class PostPlatform extends Model
{
    /** @use HasFactory<PostPlatformFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'post_id',
        'social_account_id',
        'enabled',
        'platform',
        'platform_name',
        'platform_username',
        'platform_avatar',
        'content_type',
        'status',
        'platform_post_id',
        'platform_url',
        'error_message',
        'error_context',
        'published_at',
        'meta',
        'connection_warning_sent_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'platform' => SocialPlatform::class,
            'content_type' => ContentType::class,
            'status' => Status::class,
            'published_at' => 'datetime',
            'meta' => 'array',
            'error_context' => 'array',
            'connection_warning_sent_at' => 'datetime',
        ];
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    /**
     * The subset of the post's media items scoped to this platform. Empty
     * unless a caller has explicitly narrowed which media this platform
     * publishes (per-platform media selection).
     */
    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'media_post_platform')
            ->using(MediaPostPlatform::class)
            ->withTimestamps();
    }

    /**
     * The media items this platform actually publishes. An empty pivot set
     * for this platform means "no per-platform selection was made", which is
     * today's behaviour: fall back to every media item on the post. This is
     * the one place that decides that, so every publisher and validator
     * reads media through this helper instead of `$postPlatform->post->mediaItems`
     * directly, or the two would silently disagree once a selection exists.
     *
     * @return Collection<int, MediaItem>
     */
    public function scopedMediaItems(): Collection
    {
        $selectedIds = $this->media()->pluck('medias.id');

        $allMediaItems = $this->post->mediaItems;

        if ($selectedIds->isEmpty()) {
            return $allMediaItems;
        }

        return $allMediaItems->filter(
            fn (MediaItem $item) => $selectedIds->contains($item->id)
        )->values();
    }

    /**
     * Only platforms still enabled for publishing (disabled ones are
     * excluded from PublishPost, so anything else that mirrors publish
     * eligibility (previews, validation, proactive checks) must too).
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('post_platforms.enabled', true);
    }

    /**
     * The ids of media items currently scoped to this platform. Empty means
     * "no scoping, applies to every post media item", see scopedMediaItems().
     * Only used for the editor's per-platform media assignment UI, so this
     * relies on `media` already being eager-loaded (or explicitly appended
     * via `append('media_ids')`, see PostController::edit()) to avoid a lazy
     * load nobody asked for on every other page that serializes this model.
     *
     * @return array<int, string>
     */
    public function getMediaIdsAttribute(): array
    {
        return $this->media->pluck('id')->all();
    }

    /**
     * Get display name, falling back to snapshot if account was deleted.
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->socialAccount?->accountDisplayName() ?? $this->platform_name ?? $this->platform->label();
    }

    /**
     * Get username, falling back to snapshot if account was deleted.
     */
    public function getDisplayUsernameAttribute(): ?string
    {
        return $this->socialAccount?->username ?? $this->platform_username;
    }

    /**
     * "Facebook Page (@handle)" for emails and in-app notifications.
     * Username first, then display name (live account or the snapshot
     * kept on this row). When neither is set — or the account is gone
     * and there is no snapshot — just the platform name, never "(@)".
     */
    public function notificationLabel(): string
    {
        $identifier = $this->display_username
            ?: $this->socialAccount?->display_name
            ?: $this->platform_name;

        if (! filled($identifier) || $identifier === $this->platform->label()) {
            return $this->platform->label();
        }

        return "{$this->platform->label()} (@{$identifier})";
    }

    /**
     * Get avatar URL, falling back to snapshot if account was deleted.
     */
    public function getDisplayAvatarAttribute(): ?string
    {
        if ($this->socialAccount?->avatar_url) {
            return $this->socialAccount->avatar_url;
        }

        return $this->platform_avatar ? Storage::url($this->platform_avatar) : null;
    }

    public function markAsPublishing(): void
    {
        $this->update(['status' => Status::Publishing]);
    }

    public function markAsPublished(string $platformPostId, ?string $platformUrl = null): void
    {
        $now = now();

        $this->update([
            'status' => Status::Published,
            'platform_post_id' => $platformPostId,
            'platform_url' => $platformUrl,
            'published_at' => $now,
            'error_message' => null,
            'error_context' => null,
        ]);

        $this->socialAccount?->update(['last_used_at' => $now]);
    }

    public function markAsFailed(string $errorMessage, ?array $errorContext = null): void
    {
        $this->update([
            'status' => Status::Failed,
            'error_message' => $errorMessage,
            'error_context' => $errorContext,
            'platform_post_id' => null,
            'platform_url' => null,
        ]);
    }

    /**
     * Reverts a published row back to its pre-publish state after the remote
     * post was successfully deleted (see App\Actions\Post\UnpublishPost).
     * `Pending` is the same status a freshly created row starts in.
     */
    public function markAsUnpublished(): void
    {
        $this->update([
            'status' => Status::Pending,
            'platform_post_id' => null,
            'platform_url' => null,
            'published_at' => null,
            'error_message' => null,
            'error_context' => null,
        ]);
    }
}
