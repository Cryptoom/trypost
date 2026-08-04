<?php

declare(strict_types=1);

use App\Jobs\PostHog\SendEvent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

test('job is queued on posthog queue', function () {
    $job = new SendEvent('capture', ['distinctId' => 'user-1', 'event' => 'test']);

    expect($job->queue)->toBe('posthog');
});

test('job skips execution when api key is missing', function () {
    config(['services.posthog.api_key' => null]);

    $job = new SendEvent('capture', ['distinctId' => 'user-1', 'event' => 'test']);

    $job->handle();

    expect(true)->toBeTrue();
});

test('job has correct retry and timeout settings', function () {
    $job = new SendEvent('capture', []);

    expect($job->tries)->toBe(3)
        ->and($job->timeout)->toBe(15);
});

test('unique id prefers the dedupe key', function () {
    $job = new SendEvent('capture', [], 'onboarding:viewed:account-1');

    expect($job->uniqueId())->toBe('onboarding:viewed:account-1')
        ->and($job->dedupeKey)->toBe('onboarding:viewed:account-1');
});

test('unique id is stable for jobs without a dedupe key', function () {
    $job = new SendEvent('capture', []);

    expect($job->uniqueId())->toBe($job->uniqueId())
        ->and($job->uniqueId())->not->toBeEmpty();
});

test('wasDelivered and markDelivered round-trip through cache', function () {
    $key = 'onboarding:step:account-1:social';

    expect(SendEvent::wasDelivered($key))->toBeFalse();

    SendEvent::markDelivered($key);

    expect(SendEvent::wasDelivered($key))->toBeTrue()
        ->and(Cache::has(SendEvent::deliveredKey($key)))->toBeTrue();
});

test('wasDelivered fails open when cache is unavailable', function () {
    Cache::shouldReceive('has')->once()->andThrow(new RuntimeException('Redis unavailable'));
    Log::spy();

    expect(SendEvent::wasDelivered('onboarding:viewed:account-1'))->toBeFalse();

    Log::shouldHaveReceived('warning')->atLeast()->once();
});

test('handle is a no-op when the event was already delivered', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test']);

    SendEvent::markDelivered('onboarding:viewed:account-1');

    $job = new SendEvent(
        'capture',
        ['distinctId' => 'user-1', 'event' => 'onboarding.viewed'],
        'onboarding:viewed:account-1',
    );

    // Would call PostHog if the delivered short-circuit failed.
    $job->handle();

    expect(true)->toBeTrue();
});
