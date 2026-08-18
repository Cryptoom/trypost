<?php

declare(strict_types=1);

namespace App\Enums\PostHog;

enum OnboardingEvent: string
{
    case Viewed = 'onboarding.viewed';
    case StepCompleted = 'onboarding.step_completed';
    case StepSkipped = 'onboarding.step_skipped';
    case Completed = 'onboarding.completed';
    case Persona = 'welcome.persona';
    case Goals = 'welcome.goals';
    case Referral = 'welcome.referral';
    case PublishMethod = 'welcome.publish_method';
    case Connect = 'welcome.connect';

    /**
     * Onboarding capture order through Stripe Checkout.
     *
     * @return list<string>
     */
    public static function funnel(): array
    {
        return [
            self::Persona->value,
            self::Goals->value,
            self::Referral->value,
            self::PublishMethod->value,
            self::Connect->value,
            CheckoutEvent::Started->value,
        ];
    }
}
