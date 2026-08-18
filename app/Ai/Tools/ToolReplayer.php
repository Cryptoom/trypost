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
 * A read tool that now fails (its post was deleted) falls back to the
 * stored result rather than breaking the card.
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
                    $payloads[$id] = $tool->handle(new Request((array) data_get($call, 'arguments', [])));
                } catch (Throwable) {
                    $payloads[$id] = $stored;
                }
            }
        }

        return $payloads;
    }
}
