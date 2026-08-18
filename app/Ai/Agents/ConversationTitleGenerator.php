<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\UseCheapestModel;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * Titles a conversation from its opening user message. Runs on the cheapest
 * available model since it fires on every first turn and is not user-facing
 * AI usage worth metering.
 */
#[UseCheapestModel]
class ConversationTitleGenerator implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return view('prompts.conversation.title')->render();
    }
}
