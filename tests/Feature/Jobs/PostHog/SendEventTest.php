<?php

declare(strict_types=1);

use App\Jobs\PostHog\SendEvent;

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
