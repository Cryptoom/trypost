<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Events\PostDeleted;
use App\Models\Post;

class DeletePost
{
    public static function execute(Post $post): void
    {
        $postId = $post->id;
        $workspaceId = $post->workspace_id;

        // Best-effort: UnpublishPost never lets an individual platform
        // failure escape (see its per-row try/catch), so a bad connection
        // or an unsupported platform (e.g. TikTok) never blocks the local
        // delete below.
        UnpublishPost::execute($post);

        $post->delete();

        PostDeleted::dispatch($postId, $workspaceId);
    }
}
