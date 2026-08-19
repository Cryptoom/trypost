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
 * Its replayed payload is also stamped `spent` once the conversation went on
 * to start a generation, so the card renders settled instead of re-arming a
 * single-use form — see {@see markSpent()}. A generate_post that was REFUSED
 * does not count, because nothing was generated and nothing was billed; see
 * {@see lastGeneratePostPosition()}.
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
 * post — every single time the conversation is opened. Its stored payload is
 * AUGMENTED instead, see {@see withGeneratedPost()}.
 *
 * What actually prevents it from being re-run is the early branch in
 * {@see replay()}, which handles generate_post and `continue`s BEFORE the
 * REPLAYABLE lookup happens — not its absence from that map. Absent from the
 * map it also is, and it must stay that way, but that is the braces; the
 * branch is the belt. Adding it to REPLAYABLE today would be a silent no-op,
 * which is a safe way to be wrong. Folding the branch back into the map is
 * not: whoever does that removes the only thing standing between a reopened
 * conversation and a second billed generation, and must re-establish the
 * guarantee some other way before doing so.
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
     * Matched by the early branch in {@see replay()}, which is what keeps this
     * tool from being re-run. Never add it to REPLAYABLE either — see the
     * class docblock for why the branch, not the map, is the guarantee.
     */
    private const GENERATE_POST = 'generate_post';

    private const START_POST_GENERATION = 'start_post_generation';

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
        $position = 0;
        $lastGeneratePosition = $this->lastGeneratePostPosition($conversation);

        foreach ($conversation->messages as $message) {
            $storedResults = collect($message->tool_results ?? [])->keyBy('id');

            foreach ($message->tool_calls ?? [] as $call) {
                $id = data_get($call, 'id');
                $stored = (string) data_get($storedResults->get($id), 'result', '');
                $name = data_get($call, 'name');
                $callPosition = $position++;

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
                    $payload = $this->isErrorPayload($fresh) ? $stored : $fresh;

                    $payloads[$id] = $name === self::START_POST_GENERATION
                        ? $this->markSpent($payload, $callPosition < $lastGeneratePosition)
                        : $payload;
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
     * Position of the last generate_post call that actually STARTED a
     * generation, counted over the same flattened call sequence {@see replay()}
     * walks, or -1 when the conversation has none.
     *
     * A refused call does not count. generate_post answers with an `{"error":
     * "..."}` payload whenever it declines — no AI access, exhausted credits,
     * an invalid format or account — and in every one of those cases nothing
     * was generated and nothing was billed. Treating an attempt as a
     * generation would settle the card that collected the choices, so a user
     * who topped up their credits and reopened the conversation would find
     * their own choices frozen behind a disabled form, with no way forward but
     * to ask for a fresh card.
     *
     * A call with no stored result at all is counted as a generation. That is
     * the safe direction: the failure mode of over-counting is a card the user
     * must ask to have re-offered, while under-counting bills them for a
     * duplicate generation.
     */
    private function lastGeneratePostPosition(WorkspaceConversation $conversation): int
    {
        $position = 0;
        $last = -1;

        foreach ($conversation->messages as $message) {
            $storedResults = collect($message->tool_results ?? [])->keyBy('id');

            foreach ($message->tool_calls ?? [] as $call) {
                $stored = (string) data_get($storedResults->get(data_get($call, 'id')), 'result', '');

                if (data_get($call, 'name') === self::GENERATE_POST && ! $this->isErrorPayload($stored)) {
                    $last = $position;
                }

                $position++;
            }
        }

        return $last;
    }

    /**
     * Mark a start_post_generation payload as already acted on.
     *
     * The card that renders this payload is a form: it collects the user's
     * choices and submits them as a message, which is what makes the model
     * call generate_post. That form is single-use, but `submitted` lives in
     * the component, so a reopened conversation would hand the user a blank,
     * fully interactive card sitting above the post it already produced — and
     * a second submit would arrive with a fresh tool call id, so nothing
     * downstream would deduplicate it and the account would be billed for a
     * duplicate generation.
     *
     * The conversation itself is the record of what happened, and this class
     * is where the whole history is already in hand: a generation card whose
     * conversation went on to actually START a generation has been spent, and
     * the card renders settled rather than interactive.
     */
    private function markSpent(string $payload, bool $spent): string
    {
        if (! $spent) {
            return $payload;
        }

        $decoded = json_decode($payload, true);

        if (! is_array($decoded)) {
            return $payload;
        }

        data_set($decoded, 'data.spent', true);

        return $this->encode($decoded);
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
