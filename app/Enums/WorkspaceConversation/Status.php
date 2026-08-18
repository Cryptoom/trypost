<?php

declare(strict_types=1);

namespace App\Enums\WorkspaceConversation;

enum Status: string
{
    case Idle = 'idle';
    case InProgress = 'in_progress';
}
