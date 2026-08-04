<?php

declare(strict_types=1);

namespace App\Exceptions\SocialAccount;

use App\Enums\SocialAccount\Platform;
use Illuminate\Database\QueryException;
use RuntimeException;
use Throwable;

class NetworkAlreadyConnectedException extends RuntimeException
{
    private const UNIQUE_INDEX = 'social_accounts_workspace_network_unique';

    public function __construct(public readonly Platform $platform)
    {
        parent::__construct("This workspace already has a {$platform->network()} account connected.");
    }

    /**
     * Observer throw or a race that hit the workspace/network unique index.
     */
    public static function matches(Throwable $exception): bool
    {
        if ($exception instanceof self) {
            return true;
        }

        return $exception instanceof QueryException
            && str_contains($exception->getMessage(), self::UNIQUE_INDEX);
    }
}
