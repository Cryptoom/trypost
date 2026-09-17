<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use Illuminate\Validation\Rule;

/**
 * Shared post status helpers — edit/delete gates plus the `scheduled_at`
 * update contract used by web, API, and MCP so those entry points cannot drift.
 */
class PostStatusRules
{
    private const EDIT_BLOCKED_MESSAGE_KEY = 'posts.cannot_edit_finalized';

    /**
     * Statuses where the post can no longer be edited.
     *
     * @var array<int, PostStatus>
     */
    private const EDIT_BLOCKED_STATUSES = [
        PostStatus::Published,
        PostStatus::PartiallyPublished,
        PostStatus::Failed,
        PostStatus::Publishing,
    ];

    /**
     * Statuses where the post can no longer be deleted. Published and
     * PartiallyPublished used to be blocked here too, back when deletion had
     * no way to remove the already-published copies first. Since
     * App\Actions\Post\DeletePost now runs UnpublishPost as a best-effort
     * first step (see its docblock), those two are safe to delete through
     * the web UI as well. This list only protects a post that is actively
     * being published right now (a live publish job racing the delete).
     *
     * @var array<int, PostStatus>
     */
    private const DELETE_BLOCKED_STATUSES = [
        PostStatus::Publishing,
    ];

    public static function blocksEditing(Post $post): bool
    {
        return in_array($post->status, self::EDIT_BLOCKED_STATUSES, true);
    }

    public static function blocksDeletion(Post $post): bool
    {
        return in_array($post->status, self::DELETE_BLOCKED_STATUSES, true);
    }

    public static function editBlockedMessage(): string
    {
        return __(self::EDIT_BLOCKED_MESSAGE_KEY);
    }

    /**
     * True when status is scheduled and the post has no future schedule to reuse.
     */
    public static function requiresExplicitSchedule(?Post $post, mixed $status): bool
    {
        if ($status !== PostStatus::Scheduled->value) {
            return false;
        }

        $existing = $post?->scheduled_at;

        return $existing === null || $existing->isPast();
    }

    /**
     * Validation rules for `scheduled_at` on post update (web, API, MCP).
     *
     * @return list<mixed>
     */
    public static function scheduledAtRules(?Post $post, mixed $status): array
    {
        return [
            Rule::requiredIf(fn (): bool => self::requiresExplicitSchedule($post, $status)),
            'nullable',
            'date',
            Rule::when(
                $status === PostStatus::Scheduled->value,
                ['after:now'],
            ),
        ];
    }
}
