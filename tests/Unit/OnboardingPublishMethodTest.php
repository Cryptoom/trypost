<?php

declare(strict_types=1);

use App\Enums\User\PublishMethod;

test('publish method has the onboarding choices', function () {
    expect(PublishMethod::Manual->value)->toBe('manual')
        ->and(PublishMethod::Ai->value)->toBe('ai');
});
