<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Actions\Post\UnpublishPost;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Post;
use App\Models\PostPlatform;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description("Delete a post's already-published platforms remotely and reset it back to Draft locally. Unlike delete-post-tool, the post itself is kept, this only unpublishes it. Best-effort per platform: a platform that fails, or does not support deletion at all (most platforms today, see unsupported_platforms in the response), never blocks the others. Optional post_platform_ids narrows the call to specific platforms; omit to attempt every published platform on the post.")]
class UnpublishPostTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        // post_id is validated in isolation first, before Post::find() sees
        // it: a non-uuid could otherwise throw at the DB driver, and an
        // array would make find() return a Collection instead of Post|null,
        // which the Rule::exists()->where() below cannot accept. Same
        // pattern as AttachMediaFromUploadTool/UpdatePostTool.
        $postIdValidated = $request->validate(['post_id' => ['required', 'uuid']]);

        $post = Post::where('workspace_id', $request->user()?->current_workspace_id)
            ->find(data_get($postIdValidated, 'post_id'));

        if (! $post) {
            return Response::error('Post not found.');
        }

        // update, not delete: unpublishing keeps the post itself, it only
        // removes the remote copies and resets the post's status locally.
        if ($denied = $this->denyUnlessCan($request, 'update', $post, 'Not authorized to update this post.')) {
            return $denied;
        }

        $validated = $request->validate([
            'post_id' => ['required', 'uuid'],
            'post_platform_ids' => ['sometimes', 'array'],
            'post_platform_ids.*' => [
                'uuid',
                Rule::exists('post_platforms', 'id')->where('post_id', $post->id),
            ],
        ]);

        $result = UnpublishPost::execute($post, data_get($validated, 'post_platform_ids'));

        return Response::structured([
            'post_id' => $post->id,
            'unpublished' => collect(data_get($result, 'unpublished'))
                ->map(fn (PostPlatform $pp) => $this->summarize($pp))
                ->values()
                ->all(),
            'failed' => collect(data_get($result, 'failed'))
                ->map(fn (array $entry) => [
                    ...$this->summarize(data_get($entry, 'post_platform')),
                    'message' => data_get($entry, 'message'),
                ])
                ->values()
                ->all(),
            'unsupported_platforms' => collect(data_get($result, 'unsupported'))
                ->map(fn (PostPlatform $pp) => $this->summarize($pp))
                ->values()
                ->all(),
        ]);
    }

    /**
     * @return array{post_platform_id: string, platform: string}
     */
    private function summarize(PostPlatform $postPlatform): array
    {
        return [
            'post_platform_id' => $postPlatform->id,
            'platform' => $postPlatform->platform->value,
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('UUID of the post to unpublish.'),
            'post_platform_ids' => $schema->array()
                ->items($schema->string())
                ->description('Optional post_platform row UUIDs (from get-post-tool / list-posts-tool) this post already has, to narrow the unpublish to specific platforms. Omit to attempt every already-published platform on the post.'),
        ];
    }
}
