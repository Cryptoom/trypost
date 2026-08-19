<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Ai\Tools\Post\GetPostMetricsTool;
use App\Ai\Tools\Post\GetPostTool;
use App\Ai\Tools\Post\ListPostsTool;
use App\Models\WorkspaceConversation;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * Rebuilds the UI payload for every tool call in a stored conversation.
 *
 * Read tools re-run so a reopened conversation shows current data; write
 * tools cannot be replayed and keep whatever they returned at the time.
 *
 * A read tool that no longer finds its record (e.g. the post was deleted
 * since the conversation happened) does not throw: WorkspaceTool::handle()
 * already catches everything run() can throw, and "not found" is itself a
 * deliberate `{"error": "..."}` return, not an exception. So the fallback
 * this class needs is a check on the replayed payload's shape, not a
 * try/catch — an error payload is swapped back for the original stored
 * result, because the assistant's message above the card described real
 * data at the time, and an error card under it reads broken.
 */
class ToolReplayer
{
    /**
     * @var array<string, class-string>
     */
    private const REPLAYABLE = [
        'list_posts' => ListPostsTool::class,
        'get_post' => GetPostTool::class,
        'get_post_metrics' => GetPostMetricsTool::class,
    ];

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
                $class = self::REPLAYABLE[data_get($call, 'name')] ?? null;

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

    private function isErrorPayload(string $payload): bool
    {
        return data_get(json_decode($payload, true), 'error') !== null;
    }
}
