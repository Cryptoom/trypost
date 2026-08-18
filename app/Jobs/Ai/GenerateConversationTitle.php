<?php

declare(strict_types=1);

namespace App\Jobs\Ai;

use App\Ai\Agents\ConversationTitleGenerator;
use App\Enums\WorkspaceConversation\Message\Role;
use App\Models\WorkspaceConversation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Titles a conversation from its opening user message, in the background.
 *
 * The sidebar only lists conversations with a non-null title (see
 * WorkspaceConversation::scopeListable()), so this job may run late, run
 * twice, or never run at all without ever breaking the UI: a conversation
 * simply stays out of the sidebar until it is titled.
 */
class GenerateConversationTitle implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $conversationId)
    {
        $this->onQueue('ai');
    }

    public function handle(): void
    {
        $conversation = WorkspaceConversation::find($this->conversationId);

        if ($conversation === null || $conversation->title !== null) {
            return;
        }

        $firstUserMessage = $conversation->messages()->where('role', Role::User)->first();

        if ($firstUserMessage === null) {
            return;
        }

        $response = (new ConversationTitleGenerator)->prompt(Str::limit($firstUserMessage->content, 500));

        $title = $this->sanitizeTitle($response->text);

        if ($title === null) {
            return;
        }

        $conversation->update(['title' => $title]);
    }

    /**
     * Defend against a chatty model response landing verbatim in the sidebar.
     *
     * The reliable signal that a model wrapped its answer in a preamble is
     * that it quoted the answer, so when the response contains a quoted
     * segment we take that segment as the title; otherwise we take the whole
     * response as-is. A leading-word-list heuristic ("Sure!", "Here's...")
     * was tried and rejected: it both damages legitimate titles that start
     * with one of those words followed by a colon (e.g. "Okay Computer: A
     * Retrospective") and misses chatty forms that don't use those words
     * (e.g. "I would say: Draft post count"). An occasionally chatty title
     * is a far better failure than a silently damaged one.
     *
     * Wrapping quotes and trailing punctuation are trimmed with a
     * Unicode-aware regex, never `trim()`'s byte-wise charlist — passing
     * multi-byte characters to `trim()` corrupts adjacent multi-byte text
     * (e.g. CJK) whose bytes happen to collide with the charlist bytes.
     */
    private function sanitizeTitle(string $text): ?string
    {
        $title = Str::squish($text);
        $title = $this->extractQuoted($title) ?? $title;
        $title = $this->trimWrappingQuotesAndSpace($title);
        $title = (string) preg_replace('/\p{P}+$/u', '', $title);
        $title = trim($this->trimWrappingQuotesAndSpace($title));

        if ($title === '') {
            return null;
        }

        return Str::limit($title, 250, '');
    }

    /**
     * Extract the contents of the first quoted segment in the given text, if
     * any. Matches straight and curly quote pairs; a mismatched or missing
     * pair yields null.
     */
    private function extractQuoted(string $text): ?string
    {
        if (preg_match('/"([^"]+)"|\'([^\']+)\'|“([^”]+)”|‘([^’]+)’/u', $text, $matches) !== 1) {
            return null;
        }

        foreach (array_slice($matches, 1) as $group) {
            if ($group !== '') {
                return $group;
            }
        }

        return null;
    }

    /**
     * Trim leading/trailing whitespace and wrapping quote characters,
     * Unicode-aware.
     */
    private function trimWrappingQuotesAndSpace(string $text): string
    {
        return (string) preg_replace('/^[\s"\'“”‘’]+|[\s"\'“”‘’]+$/u', '', $text);
    }
}
