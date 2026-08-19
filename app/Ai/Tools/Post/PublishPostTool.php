<?php

declare(strict_types=1);

namespace App\Ai\Tools\Post;

use App\Actions\Post\UpdatePost;
use App\Ai\Tools\WorkspaceWriteTool;
use App\Enums\Post\Action as PostAction;
use App\Enums\Post\Status;
use App\Http\Resources\Chat\ChatPostResource;
use App\Models\Post;
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
 * once it's live. A post that's genuinely ready to publish always needs
 * human approval first. A post that ISN'T ready — already finalized
 * (published/publishing/partially published/failed), no enabled platform,
 * or missing meta a platform needs — can't succeed no matter what the user
 * answers, so {@see needsApproval()} and {@see run()} share the same
 * readiness check ({@see publishBlockedReason()}): refuse immediately with
 * the specific reason instead of asking the user to confirm something
 * that's going to fail regardless. The actual publish path mirrors the MCP
 * publish tool (see App\Mcp\Tools\Post\PublishPostTool) exactly: the same
 * readiness checks, the same App\Actions\Post\UpdatePost call. A member the
 * workspace policy refuses is likewise never asked to confirm a publish
 * {@see WorkspaceTool::writeDenied()} will refuse anyway.
 */
class PublishPostTool extends WorkspaceWriteTool implements Approvable
{
    use InteractsWithApprovals;

    public function name(): string
    {
        return 'publish_post';
    }

    public function description(): Stringable|string
    {
        return 'Publish a post in the current workspace immediately. The post must already have at least one enabled platform with everything that platform needs to publish. Asks the user to confirm first when the post is ready; otherwise refuses immediately with the reason.';
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
        if ($this->writeDenied()) {
            return false;
        }

        $post = $this->resolvePost($request->string('post_id')->value());

        if ($post === null || $this->publishBlockedReason($post) !== null) {
            return false;
        }

        return Approval::required(__('chat.approvals.publish'));
    }

    protected function run(Request $request): string
    {
        $post = $this->resolvePost($request->string('post_id')->value());

        if ($post === null) {
            return $this->error(__('chat.tools.post_not_found'));
        }

        if ($reason = $this->publishBlockedReason($post)) {
            return $this->error($reason);
        }

        $result = UpdatePost::execute($this->workspace, $post, [
            'status' => Status::Publishing->value,
        ]);

        if (data_get($result, 'action') === PostAction::Finalized) {
            return $this->error(PostStatusRules::editBlockedMessage());
        }

        return $this->json([
            'data' => (new ChatPostResource($post->fresh()->load('postPlatforms.socialAccount')))->withFullContent()->resolve(),
        ]);
    }

    /**
     * Why this post can't be published right now, or null when it's ready.
     * The single source of truth for both the approval gate and the actual
     * refusal message, so the two can never drift apart.
     */
    private function publishBlockedReason(Post $post): ?string
    {
        if (PostStatusRules::blocksEditing($post)) {
            return PostStatusRules::editBlockedMessage();
        }

        if (! $post->postPlatforms()->enabled()->exists()) {
            return __('chat.tools.publish_no_enabled_platforms');
        }

        try {
            PostPlatformMetaRules::assertStoredPostPublishable($post);
            ContentTypeCompatibleWithMedia::assertStoredPostCompatible($post);
        } catch (ValidationException $e) {
            return (string) $e->validator->errors()->first();
        }

        return null;
    }
}
