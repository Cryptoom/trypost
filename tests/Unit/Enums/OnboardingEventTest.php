<?php

declare(strict_types=1);

use App\Enums\PostHog\CheckoutEvent;
use App\Enums\PostHog\OnboardingEvent;

test('onboarding funnel puts publish method between referral and connect', function () {
    expect(OnboardingEvent::funnel())->toBe([
        OnboardingEvent::Persona->value,
        OnboardingEvent::Goals->value,
        OnboardingEvent::Referral->value,
        OnboardingEvent::PublishMethod->value,
        OnboardingEvent::Connect->value,
        CheckoutEvent::Started->value,
    ]);
});
