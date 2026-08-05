<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use RuntimeException;

test('matches itself', function () {
    $exception = new NetworkAlreadyConnectedException(Platform::Instagram);

    expect(NetworkAlreadyConnectedException::matches($exception))->toBeTrue();
});

test('does not match unrelated exceptions', function () {
    expect(NetworkAlreadyConnectedException::matches(new RuntimeException('nope')))->toBeFalse();
});
