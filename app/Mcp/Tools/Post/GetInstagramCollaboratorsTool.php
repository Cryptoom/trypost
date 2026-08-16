<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Models\Post;
use App\Support\Social\InstagramCollaborators;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Read Instagram collaborator invite status for a post platform. Live Accepted/Pending/Declined status is only available for Instagram connected via Facebook after the post is published. Instagram Login (standalone) can send invites but cannot read live status. Stories do not support collaborators.')]
class GetInstagramCollaboratorsTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'post_id' => ['required', 'uuid'],
            'post_platform_id' => ['required', 'uuid'],
        ]);

        $post = Post::where('workspace_id', $request->user()->current_workspace_id)
            ->find(data_get($validated, 'post_id'));

        if (! $post) {
            return Response::error('Post not found.');
        }

        $postPlatform = $post->postPlatforms()
            ->with('socialAccount')
            ->find(data_get($validated, 'post_platform_id'));

        if (! $postPlatform) {
            return Response::error('Post platform not found.');
        }

        return Response::structured(InstagramCollaborators::fetchInviteStatus($postPlatform));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('UUID of the post.'),
            'post_platform_id' => $schema->string()->required()->description('UUID of the Instagram post_platform row (from get-post-tool / list-posts-tool).'),
        ];
    }
}
