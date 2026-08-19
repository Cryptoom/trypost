<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\Tools\Post\GetPostTool;
use App\Ai\Tools\Post\ListPostsTool;
use App\Ai\Tools\Post\StartPostGenerationTool;
use App\Http\Resources\Chat\ChatPostResource;
use App\Models\WorkspaceConversation;
use App\Models\WorkspaceConversationMessage;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * Rebuilds the UI payload for every tool call in a stored conversation.
 *
 * Read tools re-run so a reopened conversation shows current data; write
 * tools cannot be replayed and keep whatever they returned at the time.
 *
 * get_post_metrics is deliberately NOT replayable even though it is a read.
 * The others cost one database query each, which is the budget the design
 * accepted; get_post_metrics costs one outbound HTTP call to a third-party
 * social platform per enabled published platform (App\Services\Post\
 * PostMetricsFetcher::forPlatform()), behind only a five-minute cache. A
 * metrics-heavy conversation would therefore fire dozens of synchronous
 * third-party requests, serially, during an Inertia page render on a cold
 * cache — and any of them can rate-limit or hang. Stored results are the
 * right trade: metrics on a reopened conversation are a historical record,
 * and the user can ask again for fresh ones. Do not "fix" this by adding it
 * back to the map.
 *
 * start_post_generation belongs in the map for the same reason: one query for
 * the workspace's active accounts plus an in-memory template registry read.
 * Replaying it also keeps its card honest — a conversation reopened after an
 * account was disconnected would otherwise offer that account as a choice,
 * and generate_post would then (correctly, but pointlessly) refuse it.
 *
 * A read tool that no longer finds its record (e.g. the post was deleted
 * since the conversation happened) does not throw: WorkspaceTool::handle()
 * already catches everything run() can throw, and "not found" is itself a
 * deliberate `{"error": "..."}` return, not an exception. So the fallback
 * this class needs is a check on the replayed payload's shape, not a
 * try/catch — an error payload is swapped back for the original stored
 * result, because the assistant's message above the card described real
 * data at the time, and an error card under it reads broken.
 *
 * generate_post is the one tool that is neither replayed nor left entirely
 * alone: it is a WRITE tool, so replaying it would dispatch a second
 * generation — spending the account's AI credits and creating a duplicate
 * post — every single time the conversation is opened. It must never enter
 * REPLAYABLE. Its stored payload is AUGMENTED instead, see
 * {@see withGeneratedPost()}.
 */
class ToolReplayer
{
    /**
     * @var array<string, class-string>
     */
    private const REPLAYABLE = [
        'list_posts' => ListPostsTool::class,
        'get_post' => GetPostTool::class,
        'start_post_generation' => StartPostGenerationTool::class,
    ];

    /**
     * Never add this to REPLAYABLE — see the class docblock.
     */
    private const GENERATE_POST = 'generate_post';

    /**
     * Longest a generation is given before it is treated as over. Mirrors the
     * client's own bound (POST_CREATION_TIMEOUT_MS in
     * resources/js/composables/echo/usePostCreation.ts, itself carried over
     * from the loading screen's GENERATION_TIMEOUT_MS), so the two agree on
     * when a generation stopped being in flight. Keep them in step.
     */
    private const GENERATION_WINDOW_MINUTES = 16;

    /**
     * @return array<string, string> tool call id => JSON payload
     */
    public function replay(WorkspaceConversation $conversation): array
    {
        $payloads = [];

        foreach ($conversation->messages as $message) {
            $storedResults = collect($message->tool_results ?? [])->keyBy('id');

            foreach ($message->tool_calls ?? [] as $call) {
                $id = data_get($call, 'id');
                $stored = (string) data_get($storedResults->get($id), 'result', '');
                $name = data_get($call, 'name');

                if ($name === self::GENERATE_POST) {
                    $payloads[$id] = $this->withGeneratedPost($conversation, $message, $stored);

                    continue;
                }

                $class = self::REPLAYABLE[$name] ?? null;

                if ($class === null) {
                    $payloads[$id] = $stored;

                    continue;
                }

                try {
                    $tool = new $class($conversation->workspace, $conversation->user);
                    $fresh = $tool->handle(new Request((array) data_get($call, 'arguments', [])));
                    $payloads[$id] = $this->isErrorPayload($fresh) ? $stored : $fresh;
                } catch (Throwable) {
                    // Belt-and-braces, not the primary guard: WorkspaceTool::handle()
                    // never lets run() throw, so this only catches a failure to even
                    // construct or dispatch the tool (e.g. the conversation's
                    // workspace or user relation no longer resolves).
                    $payloads[$id] = $stored;
                }
            }
        }

        return $payloads;
    }

    /**
     * Resolve a finished generation back into its post.
     *
     * generate_post dispatches StreamPostCreation and answers immediately with
     * a creation id and the private channel PostCreationReady will announce
     * the post on, so the stored result never contains the post itself. The
     * card can subscribe to that channel while the conversation is open, but a
     * conversation reopened later missed the broadcast for good — and it will
     * never fire again. That is what `posts.creation_id` exists for: the post
     * is found by lookup instead, and the card renders it straight away
     * without subscribing to anything.
     *
     * Scoped to the conversation's own workspace, so a creation id that
     * somehow named another workspace's post resolves to nothing rather than
     * leaking it.
     *
     * Resolving nothing means one of two different things, and the payload
     * says which. A generation started minutes ago may still be running, so
     * that payload passes through untouched and the card subscribes and waits.
     * A generation whose turn happened longer ago than the whole generation
     * window with no post to show for it is over: the broadcast fired and was
     * missed, or the job failed. Nothing is coming, and the card would
     * otherwise sit spinning for the length of its own timeout implying work
     * is in progress. That payload is marked `settled` so the card can say so
     * on first paint.
     */
    private function withGeneratedPost(WorkspaceConversation $conversation, WorkspaceConversationMessage $message, string $stored): string
    {
        $payload = json_decode($stored, true);

        if (! is_array($payload)) {
            return $stored;
        }

        $creationId = data_get($payload, 'data.creation_id');

        if (! is_string($creationId) || $creationId === '') {
            return $stored;
        }

        $post = $conversation->workspace->posts()
            ->with(['postPlatforms.socialAccount'])
            ->where('creation_id', $creationId)
            ->first();

        if ($post === null) {
            if (! $this->hasOutlivedGenerationWindow($message)) {
                return $stored;
            }

            data_set($payload, 'data.settled', true);

            return $this->encode($payload);
        }

        data_set($payload, 'data.post', (new ChatPostResource($post))->withFullContent()->resolve());

        return $this->encode($payload);
    }

    /**
     * Whether the turn that started this generation is older than the window a
     * generation is given to finish, measured from the message's own
     * `created_at` rather than from anything the client reports.
     */
    private function hasOutlivedGenerationWindow(WorkspaceConversationMessage $message): bool
    {
        $createdAt = $message->created_at;

        return $createdAt !== null && $createdAt->lt(now()->subMinutes(self::GENERATION_WINDOW_MINUTES));
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function isErrorPayload(string $payload): bool
    {
        return data_get(json_decode($payload, true), 'error') !== null;
    }
}
