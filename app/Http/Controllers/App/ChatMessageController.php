<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Ai\Agents\WorkspaceConversationAgent;
use App\Enums\WorkspaceConversation\Message\Role;
use App\Enums\WorkspaceConversation\Status;
use App\Http\Requests\App\Chat\StoreChatMessageRequest;
use App\Jobs\Ai\GenerateConversationTitle;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceConversation;
use App\Models\WorkspaceConversationMessage;
use App\Services\Ai\RecordAiUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Ai\Approvals\Decision;
use Laravel\Ai\Approvals\Decisions;
use Laravel\Ai\Responses\StreamableAgentResponse;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Symfony\Component\HttpFoundation\Response;

class ChatMessageController extends Controller
{
    /**
     * Run one chat turn and stream it back over Vercel's data stream protocol.
     */
    public function store(StoreChatMessageRequest $request, string $conversation): StreamableAgentResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Workspace $workspace */
        $workspace = $user->currentWorkspace;

        $this->authorize('view', $workspace);

        $gate = Gate::inspect('useAi', $workspace->account);
        if ($gate->denied()) {
            return response()->json(['message' => $gate->message()], Response::HTTP_PAYMENT_REQUIRED);
        }

        $prompt = $this->prompt($request);

        $model = $this->claim($conversation, $workspace, $user, $prompt);

        return (new WorkspaceConversationAgent($workspace, $user))
            ->continue($model->id, as: $user)
            ->stream($prompt)
            ->usingVercelDataProtocol()
            ->then(function (StreamedAgentResponse $response) use ($model, $workspace, $user): void {
                RecordAiUsage::recordText(
                    workspace: $workspace,
                    promptTokens: $response->usage->promptTokens,
                    completionTokens: $response->usage->completionTokens,
                    provider: (string) $response->meta->provider,
                    model: (string) $response->meta->model,
                    userId: $user->id,
                    metadata: ['agent' => 'workspace_conversation'],
                );

                $model->update(['status' => Status::Idle]);

                if ($model->title === null) {
                    GenerateConversationTitle::dispatch($model->id);
                }
            });
    }

    /**
     * Take exclusive ownership of the conversation for this turn.
     *
     * The row lock, the idle check, the user-message write and the flip to
     * in-progress all live in one transaction on purpose. Locking only long
     * enough to read the status would let two simultaneous requests both see
     * Idle and both start streaming: the lock is released at commit, so the
     * check has to happen while it is still held, and the status has to be
     * written before it is released. Holding the lock across all four means the
     * loser blocks on the SELECT ... FOR UPDATE, wakes up after the winner has
     * committed InProgress, and gets a 409.
     *
     * The conversation is looked up with its client-supplied id rather than
     * route-model binding: the very first message of a conversation targets a
     * row that does not exist yet.
     */
    private function claim(
        string $conversation,
        Workspace $workspace,
        User $user,
        Decisions|string $prompt,
    ): WorkspaceConversation {
        return DB::transaction(function () use ($conversation, $workspace, $user, $prompt): WorkspaceConversation {
            $model = WorkspaceConversation::withTrashed()
                ->lockForUpdate()
                ->firstOrCreate(
                    ['id' => $conversation],
                    [
                        'workspace_id' => $workspace->id,
                        'user_id' => $user->id,
                        'status' => Status::Idle,
                    ],
                );

            abort_if(
                $model->user_id !== $user->id || $model->workspace_id !== $workspace->id,
                Response::HTTP_FORBIDDEN,
            );

            abort_if($model->trashed(), Response::HTTP_NOT_FOUND);

            abort_if(! $model->isIdle(), Response::HTTP_CONFLICT, __('chat.errors.turn_in_progress'));

            // The user's message is persisted before the stream opens so a dropped
            // connection never loses what they typed. The exact same string is handed
            // to the agent below: WorkspaceConversationStore::storeUserMessage() is
            // idempotent only against a trailing user row whose content matches the
            // prompt byte for byte, so any trimming or decorating here would make the
            // SDK's end-of-turn write a duplicate instead of a no-op...
            if (is_string($prompt)) {
                WorkspaceConversationMessage::create([
                    'workspace_conversation_id' => $model->id,
                    'role' => Role::User,
                    'content' => $prompt,
                ]);
            }

            $model->update(['status' => Status::InProgress]);

            return $model;
        });
    }

    /**
     * Resolve the turn's prompt: a new user message, or the approval decisions
     * that resume a run paused on a tool call.
     */
    private function prompt(StoreChatMessageRequest $request): Decisions|string
    {
        $validated = $request->validated();
        $decisions = data_get($validated, 'decisions');

        if (blank($decisions)) {
            return (string) data_get($validated, 'message');
        }

        return Decisions::from(collect($decisions)->map(
            fn (array $decision): Decision => data_get($decision, 'action') === 'approve'
                ? Decision::approve()
                : Decision::reject(data_get($decision, 'result')),
        )->all());
    }
}
