import { captureEvent } from '@/posthog';

const push = (data: Record<string, unknown>) => {
    try {
        window.dataLayer = window.dataLayer || [];
        window.dataLayer.push(data);
    } catch {
        // Analytics must never break product flows.
    }
};

export const useTracking = () => ({
    trackSignUp: (authProvider: string) => {
        captureEvent('user.signed_up', {
            auth_provider: authProvider,
        });

        push({
            event: 'sign_up',
            method: authProvider,
        });
    },

    trackBeginCheckout: (plan: { name: string; interval: string }) => {
        captureEvent('checkout.started', {
            plan_name: plan.name,
            interval: plan.interval,
        });

        push({
            event: 'begin_checkout',
            plan_name: plan.name,
            plan_interval: plan.interval,
        });
    },

    trackPurchase: (
        plan: { name: string; interval: string } | null,
        conversion: {
            kind: 'purchase' | 'trial';
            value: number;
            currency: string;
            transaction_id: string;
            verified_at: string;
        },
        persona?: string | null,
    ) => {
        const planProperties = plan
            ? {
                  plan_name: plan.name,
                  interval: plan.interval,
              }
            : {};
        const isTrial = conversion.kind === 'trial';

        captureEvent(
            isTrial ? 'checkout.trial_started' : 'checkout.completed',
            {
                ...planProperties,
                ...(persona ? { persona } : {}),
                $insert_id: conversion.transaction_id,
                event_id: conversion.transaction_id,
                conversion_value: conversion.value,
                conversion_currency: conversion.currency,
                conversion_transaction_id: conversion.transaction_id,
                conversion_verified_at: conversion.verified_at,
            },
        );

        push({
            event: isTrial ? 'start_trial' : 'purchase',
            event_id: conversion.transaction_id,
            transaction_id: conversion.transaction_id,
            value: conversion.value,
            currency: conversion.currency,
            ...(plan
                ? {
                      plan_name: plan.name,
                      plan_interval: plan.interval,
                  }
                : {}),
            ...(persona ? { persona } : {}),
            conversion_value: conversion.value,
            conversion_currency: conversion.currency,
            conversion_transaction_id: conversion.transaction_id,
            ecommerce: {
                transaction_id: conversion.transaction_id,
                value: conversion.value,
                currency: conversion.currency,
                items: [
                    {
                        item_id: plan?.name ?? 'subscription',
                        item_name: plan?.name ?? 'Subscription',
                        item_variant: plan?.interval,
                        price: conversion.value,
                        quantity: 1,
                    },
                ],
            },
        });
    },
});
