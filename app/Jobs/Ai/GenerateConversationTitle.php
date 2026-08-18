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
     * Defend against a chatty model response landing verbatim in the sidebar:
     * strip a conversational preamble, wrapping quotes and trailing
     * punctuation, then enforce the `title` column's length limit.
     */
    private function sanitizeTitle(string $text): ?string
    {
        $title = Str::squish($text);

        $title = (string) preg_replace(
            '/^(sure|okay|ok|certainly|alright|here)\b[^:]{0,60}:\s*/i',
            '',
            $title,
        );

        $title = trim($title, " \t\n\r\0\x0B\"'“”‘’");
        $title = rtrim($title, '.!?,;:');
        $title = trim($title);

        if ($title === '') {
            return null;
        }

        return Str::limit($title, 250, '');
    }
}
