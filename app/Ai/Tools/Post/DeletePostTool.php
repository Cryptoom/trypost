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
 * gone out anywhere — so it runs straight through. Deleting anything else
 * asks for human approval first, and the actual deletion still respects
 * {@see PostStatusRules::blocksDeletion()} — the same rule the web UI
 * enforces — so an approved request against a post that's live on a
 * platform still fails with an honest explanation instead of quietly
 * deleting TryPost's only record of it.
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
        return 'Delete a post from the current workspace. Deleting a draft happens immediately; deleting anything else asks the user to confirm first, and posts that are already live on a platform cannot be deleted at all.';
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

        if ($post === null || $post->status === Status::Draft) {
            return false;
        }

        return Approval::required(__('chat.approvals.delete_published'));
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
