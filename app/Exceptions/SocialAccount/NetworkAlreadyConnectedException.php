<?php

declare(strict_types=1);

namespace App\Exceptions\SocialAccount;

use App\Enums\SocialAccount\Platform;
use RuntimeException;
use Throwable;

class NetworkAlreadyConnectedException extends RuntimeException
{
    public function __construct(public readonly Platform $platform)
    {
        parent::__construct("This workspace already has a {$platform->network()} account connected.");
    }

    public static function matches(Throwable $exception): bool
    {
        return $exception instanceof self;
    }
}
