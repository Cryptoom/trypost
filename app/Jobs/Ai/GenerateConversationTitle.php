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
     * that it double-quoted the answer, so extraction only fires in two
     * narrow, unambiguous shapes (see extractQuoted()): the entire response
     * is one quoted segment, or the response ends with a colon followed by a
     * quoted segment. Anything else is used as-is. An apostrophe or single
     * quote is never treated as a quote delimiter — pairing arbitrary
     * apostrophes (e.g. two contractions anywhere in the response) matches
     * the wrong span and corrupts the title. A leading-word-list heuristic
     * ("Sure!", "Here's...") was also tried and rejected: it damages
     * legitimate titles that start with one of those words followed by a
     * colon (e.g. "Okay Computer: A Retrospective"). An occasionally chatty
     * title is a far better failure than a silently corrupted one.
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
     * Extract a title the model wrapped in double quotes, in one of two
     * unambiguous shapes only:
     *
     * 1. The entire trimmed response is one double-quoted segment
     *    (straight `"…"` or curly `"…"`) — unwrap it.
     * 2. The response ends with a colon, optional whitespace, and a
     *    double-quoted segment — take that segment.
     *
     * Anything else returns null so the caller falls back to the response
     * unchanged. Single/curly-apostrophe quotes never delimit a match: an
     * apostrophe is not a quote, and pairing arbitrary apostrophes in the
     * response (e.g. two contractions) would capture the wrong span.
     */
    private function extractQuoted(string $text): ?string
    {
        if (preg_match('/^"(.+)"$/u', $text, $matches) === 1
            || preg_match('/^“(.+)”$/u', $text, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/:\s*"([^"]+)"$/u', $text, $matches) === 1
            || preg_match('/:\s*“([^”]+)”$/u', $text, $matches) === 1) {
            return $matches[1];
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
