<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

test('matches itself', function () {
    $exception = new NetworkAlreadyConnectedException(Platform::Instagram);

    expect(NetworkAlreadyConnectedException::matches($exception))->toBeTrue();
});

test('matches a unique constraint violation for the network index', function () {
    $exception = new UniqueConstraintViolationException(
        'pgsql',
        'insert into social_accounts',
        [],
        new RuntimeException('duplicate key value violates unique constraint "social_accounts_workspace_network_unique"'),
    );

    expect(NetworkAlreadyConnectedException::matches($exception))->toBeTrue();
});

test('matches a generic query exception that mentions the network index', function () {
    $exception = new QueryException(
        'pgsql',
        'insert into social_accounts',
        [],
        new RuntimeException('social_accounts_workspace_network_unique'),
    );

    expect(NetworkAlreadyConnectedException::matches($exception))->toBeTrue();
});

test('does not match unrelated exceptions', function () {
    expect(NetworkAlreadyConnectedException::matches(new RuntimeException('nope')))->toBeFalse()
        ->and(NetworkAlreadyConnectedException::matches(new QueryException(
            'pgsql',
            'insert into social_accounts',
            [],
            new RuntimeException('some other failure'),
        )))->toBeFalse();
});
