<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\Automation\Trigger\Type as TriggerType;
use App\Enums\Post\Status as PostStatus;
use App\Events\OnboardingStatusUpdated;
use App\Events\PostCreated;
use App\Jobs\Automation\DispatchPostTriggerAutomationsJob;
use App\Models\Post;
use Illuminate\Support\Facades\DB;

class PostObserver
{
    public function created(Post $post): void
    {
        DB::afterCommit(fn () => PostCreated::dispatch($post));

        // Only the first post unlocks the onboarding step — later creates would
        // just spam Echo reloads while activation is still open.
        $isFirstPost = Post::query()
            ->where('workspace_id', $post->workspace_id)
            ->whereKeyNot($post->id)
            ->doesntExist();

        if ($isFirstPost) {
            OnboardingStatusUpdated::dispatchForWorkspace($post->workspace_id);
        }
    }

    public function deleted(Post $post): void
    {
        OnboardingStatusUpdated::dispatchForWorkspace($post->workspace_id);
    }

    public function saved(Post $post): void
    {
        if (! $post->wasChanged('status')) {
            return;
        }

        $triggerType = match ($post->status) {
            PostStatus::Published => TriggerType::PostPublished,
            PostStatus::Scheduled => TriggerType::PostScheduled,
            default => null,
        };

        if ($triggerType === null) {
            return;
        }

        DispatchPostTriggerAutomationsJob::dispatch($post, $triggerType)->afterCommit();
    }
}
