<?php

declare(strict_types=1);

namespace App\Enums\WorkspaceConversation\Message;

enum Role: string
{
    case User = 'user';
    case Assistant = 'assistant';
}
