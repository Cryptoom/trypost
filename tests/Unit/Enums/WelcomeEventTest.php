<?php

declare(strict_types=1);

use App\Enums\PostHog\CheckoutEvent;
use App\Enums\PostHog\WelcomeEvent;

test('welcome funnel puts publish method between referral and connect', function () {
    expect(WelcomeEvent::funnel())->toBe([
        WelcomeEvent::Persona->value,
        WelcomeEvent::Goals->value,
        WelcomeEvent::Referral->value,
        WelcomeEvent::PublishMethod->value,
        WelcomeEvent::Connect->value,
        CheckoutEvent::Started->value,
    ]);
});
