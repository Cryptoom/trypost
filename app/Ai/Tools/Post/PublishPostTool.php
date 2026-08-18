<?php

declare(strict_types=1);

namespace App\Ai\Tools\Post;

use App\Actions\Post\UpdatePost;
use App\Ai\Tools\WorkspaceTool;
use App\Enums\Post\Action as PostAction;
use App\Enums\Post\Status;
use App\Http\Resources\Chat\ChatPostResource;
use App\Rules\ContentTypeCompatibleWithMedia;
use App\Support\PostPlatformMetaRules;
use App\Support\PostStatusRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Publishing is immediate and, for platforms without a delete/unpublish
 * endpoint (TikTok in particular — see the class docs on
 * PostPlatformMetaRules), permanent — TryPost has no way to pull it back
 * once it's live. Every call needs human approval, no matter the post's
 * current state. The actual publish path mirrors the MCP publish tool
 * (see App\Mcp\Tools\Post\PublishPostTool) exactly: the same readiness
 * checks, the same App\Actions\Post\UpdatePost call.
 */
class PublishPostTool extends WorkspaceTool implements Approvable
{
    use InteractsWithApprovals;

    public function name(): string
    {
        return 'publish_post';
    }

    public function description(): Stringable|string
    {
        return 'Publish a post in the current workspace immediately. The post must already have at least one enabled platform with everything that platform needs to publish. Always asks the user to confirm first.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('The id of the post to publish.'),
        ];
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        return Approval::required(__('chat.approvals.publish'));
    }

    protected function run(Request $request): string
    {
        $post = $this->resolvePost($request->string('post_id')->value());

        if ($post === null) {
            return $this->error(__('chat.tools.post_not_found'));
        }

        if (! $post->postPlatforms()->enabled()->exists()) {
            return $this->error(__('chat.tools.publish_no_enabled_platforms'));
        }

        try {
            PostPlatformMetaRules::assertStoredPostPublishable($post);
            ContentTypeCompatibleWithMedia::assertStoredPostCompatible($post);
        } catch (ValidationException $e) {
            return $this->error((string) $e->validator->errors()->first());
        }

        $result = UpdatePost::execute($this->workspace, $post, [
            'status' => Status::Publishing->value,
        ]);

        if (data_get($result, 'action') === PostAction::Finalized) {
            return $this->error(PostStatusRules::editBlockedMessage());
        }

        return $this->json([
            'data' => (new ChatPostResource($post->fresh()->load('postPlatforms.socialAccount')))->resolve(),
        ]);
    }
}
