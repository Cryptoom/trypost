<?php

declare(strict_types=1);

namespace App\Enums\User;

enum PublishMethod: string
{
    case Manual = 'manual';
    case Ai = 'ai';
}
