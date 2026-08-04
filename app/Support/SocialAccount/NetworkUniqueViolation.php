<?php

declare(strict_types=1);

namespace App\Support\SocialAccount;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

class NetworkUniqueViolation
{
    public static function matches(Throwable $exception): bool
    {
        if ($exception instanceof UniqueConstraintViolationException) {
            return str_contains($exception->getMessage(), 'social_accounts_workspace_network_unique');
        }

        return $exception instanceof QueryException
            && str_contains($exception->getMessage(), 'social_accounts_workspace_network_unique');
    }
}
