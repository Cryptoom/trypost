<?php

declare(strict_types=1);

namespace App\Ai\Tools\Post;

use App\Actions\Post\DeletePost;
use App\Ai\Tools\WorkspaceTool;
use App\Enums\Post\Status;
use App\Support\PostStatusRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Approvals\Approval;
use Laravel\Ai\Concerns\InteractsWithApprovals;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Deleting a draft is reversible in every sense that matters — nothing has
 * gone out anywhere — so it runs straight through. A post that's already
 * live on a platform ({@see PostStatusRules::blocksDeletion()} — the same
 * rule the web UI enforces) can never actually be deleted, so there's
 * nothing to approve: refuse immediately and say why, rather than asking
 * the user to confirm an action that's going to fail regardless of their
 * answer. Approval is reserved for the one case it's meaningful — a
 * scheduled or failed post, where confirming genuinely cancels something
 * that would otherwise still happen.
 */
class DeletePostTool extends WorkspaceTool implements Approvable
{
    use InteractsWithApprovals;

    public function name(): string
    {
        return 'delete_post';
    }

    public function description(): Stringable|string
    {
        return 'Delete a post from the current workspace. Deleting a draft happens immediately. Deleting a scheduled or failed post asks the user to confirm first, since it cancels something queued to go out. A post already live on a platform cannot be deleted at all.';
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('The id of the post to delete.'),
        ];
    }

    protected function needsApproval(Request $request): Approval|bool
    {
        $post = $this->resolvePost($request->string('post_id')->value());

        if ($post === null || $post->status === Status::Draft || PostStatusRules::blocksDeletion($post)) {
            return false;
        }

        return Approval::required(__('chat.approvals.delete_scheduled'));
    }

    protected function run(Request $request): string
    {
        $post = $this->resolvePost($request->string('post_id')->value());

        if ($post === null) {
            return $this->error(__('chat.tools.post_not_found'));
        }

        if (PostStatusRules::blocksDeletion($post)) {
            return $this->error(__('chat.tools.delete_blocked'));
        }

        DeletePost::execute($post);

        return $this->json(['data' => ['id' => $post->id, 'deleted' => true]]);
    }
}
