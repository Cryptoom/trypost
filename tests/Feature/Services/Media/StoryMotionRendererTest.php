<?php

declare(strict_types=1);

use App\Models\Workspace;
use App\Services\Media\StoryMotionRenderer;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\StoryMotionFixtures;

/**
 * Turns the motion feature on for `$ids` against a fake service.
 *
 * @param  array<int, string>|string  $ids
 */
function sm01EnableMotion(array|string $ids): void
{
    config()->set('trypost.platforms.facebook.story_motion', [
        'enabled' => true,
        'workspaces' => is_array($ids) ? implode(',', $ids) : $ids,
        'token' => 'secret-token-123',
        'url' => 'http://story-render:3000',
        'timeout_seconds' => 255,
    ]);
}

function sm01ImageFile(): string
{
    $path = tempnam(sys_get_temp_dir(), 'sm01_img_');
    file_put_contents($path, 'fake-image-bytes');

    return $path;
}

beforeEach(function () {
    if (! StoryMotionFixtures::ffmpegAvailable()) {
        $this->markTestSkipped('ffmpeg or ffprobe is not installed on this machine.');
    }

    $this->workspace = Workspace::factory()->create();
    $this->renderer = app(StoryMotionRenderer::class);
    $this->image = sm01ImageFile();
    Http::preventStrayRequests();
    Log::spy();
});

afterEach(function () {
    @unlink($this->image);
    Carbon::setTestNow();
});

test('story motion is off by default', function () {
    expect($this->renderer->isEnabledFor($this->workspace))->toBeFalse()
        ->and($this->renderer->skipReason($this->workspace))->toBe('disabled')
        ->and(config('trypost.platforms.facebook.story_motion.enabled'))->toBeFalse()
        ->and(config('trypost.platforms.facebook.story_motion.workspaces'))->toBe('');
});

test('story motion needs the flag, a non-empty allowlist containing the workspace, an url and a token', function () {
    sm01EnableMotion($this->workspace->id);
    expect($this->renderer->isEnabledFor($this->workspace))->toBeTrue();

    // flag on, list empty: nobody
    sm01EnableMotion('');
    expect($this->renderer->isEnabledFor($this->workspace))->toBeFalse()
        ->and($this->renderer->skipReason($this->workspace))->toBe('workspace_not_allowed');

    // list without this workspace
    sm01EnableMotion(['other-id', 'another']);
    expect($this->renderer->skipReason($this->workspace))->toBe('workspace_not_allowed');

    // flag off although the workspace is listed
    sm01EnableMotion($this->workspace->id);
    config()->set('trypost.platforms.facebook.story_motion.enabled', false);
    expect($this->renderer->skipReason($this->workspace))->toBe('disabled');

    // url or token missing
    sm01EnableMotion($this->workspace->id);
    config()->set('trypost.platforms.facebook.story_motion.url', '');
    expect($this->renderer->skipReason($this->workspace))->toBe('no_url');
    sm01EnableMotion($this->workspace->id);
    config()->set('trypost.platforms.facebook.story_motion.token', '');
    expect($this->renderer->skipReason($this->workspace))->toBe('no_token');
});

test('the allowlist is trimmed, ignores empty entries and matches the whole id only', function () {
    sm01EnableMotion(" , other ,, {$this->workspace->id} ,");
    expect($this->renderer->isEnabledFor($this->workspace))->toBeTrue();

    // an id that is only a prefix, a suffix or a longer string never matches
    foreach ([substr($this->workspace->id, 0, 8), substr($this->workspace->id, 4), "{$this->workspace->id}x", strtoupper($this->workspace->id)] as $near) {
        sm01EnableMotion($near);
        expect($this->renderer->isEnabledFor($this->workspace))->toBeFalse($near);
    }

    // a list that is only separators and spaces means nobody
    sm01EnableMotion(' , ,, ');
    expect($this->renderer->isEnabledFor($this->workspace))->toBeFalse();

    // an array config (instead of a string) works the same
    config()->set('trypost.platforms.facebook.story_motion.workspaces', [$this->workspace->id]);
    config()->set('trypost.platforms.facebook.story_motion.enabled', true);
    expect($this->renderer->isEnabledFor($this->workspace))->toBeTrue();
});

test('render sends the photo with token, duration and base64url headline and returns the silent video', function () {
    sm01EnableMotion($this->workspace->id);
    $video = StoryMotionFixtures::video();

    Http::fake(['story-render:3000/*' => Http::response(file_get_contents($video), 200, ['Content-Type' => 'video/mp4'])]);

    $path = $this->renderer->render($this->image, 3, 'Ελληνικά Παιχνίδια');

    expect($path)->toBeString()->and(file_exists($path))->toBeTrue()->and(filesize($path))->toBe(filesize($video));

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        $encoded = $request->header('X-Story-Headline-B64')[0] ?? '';

        return $request->method() === 'POST'
            && str_starts_with($request->url(), 'http://story-render:3000/render?')
            && str_contains($request->url(), 'duration=3')
            && ($request->header('X-Story-Render-Token')[0] ?? null) === 'secret-token-123'
            && $request->body() === 'fake-image-bytes'
            && base64_decode(strtr($encoded, '-_', '+/')) === 'Ελληνικά Παιχνίδια'
            && ! str_contains($encoded, '=');
    });

    @unlink($path);
    @unlink($video);
});

test('render sends no headline header without a headline', function () {
    sm01EnableMotion($this->workspace->id);
    $video = StoryMotionFixtures::video();
    Http::fake(['story-render:3000/*' => Http::response(file_get_contents($video), 200)]);

    $path = $this->renderer->render($this->image, 3);

    Http::assertSent(fn (Request $request) => ! $request->hasHeader('X-Story-Headline-B64'));

    @unlink($path);
    @unlink($video);
});

test('render falls back with a reason and leaves no file for every kind of failure', function (string $reason, Closure $fake) {
    sm01EnableMotion($this->workspace->id);
    Http::fake(['story-render:3000/*' => $fake]);
    $before = glob(sys_get_temp_dir().'/*.mp4') ?: [];

    expect($this->renderer->render($this->image, 3, 'Hi'))->toBeNull();

    Log::shouldHaveReceived('warning')->with('Story motion render fell back to the still frame', ['reason' => $reason])->once();
    expect(array_values(array_diff(glob(sys_get_temp_dir().'/*.mp4') ?: [], $before)))->toBe([]);
})->with([
    'service busy' => ['busy', fn () => Http::response(['error' => 'busy'], 503)],
    'service timeout' => ['timeout', fn () => Http::response(['error' => 'render_timeout'], 504)],
    'wrong token' => ['unauthorized', fn () => Http::response(['error' => 'unauthorized'], 401)],
    'server error' => ['http_error', fn () => Http::response(['error' => 'render_failed'], 500)],
    'bad request' => ['http_error', fn () => Http::response(['error' => 'invalid_duration'], 400)],
    'client side timeout' => ['timeout', fn () => throw new ConnectionException('cURL error 28: Operation timed out after 255001 milliseconds')],
    'connection refused' => ['unavailable', fn () => throw new ConnectionException('cURL error 7: Failed to connect to story-render port 3000')],
    'empty body' => ['invalid_output', fn () => Http::response('', 200)],
    'not a video' => ['invalid_output', fn () => Http::response('definitely not a video', 200)],
]);

test('render rejects a video of the wrong size or with an audio stream', function (array $options) {
    sm01EnableMotion($this->workspace->id);
    $video = StoryMotionFixtures::video($options);
    Http::fake(['story-render:3000/*' => Http::response(file_get_contents($video), 200)]);

    expect($this->renderer->render($this->image, 3))->toBeNull();

    Log::shouldHaveReceived('warning')->with('Story motion render fell back to the still frame', ['reason' => 'invalid_output'])->once();
    @unlink($video);
})->with([
    'wrong dimensions' => [['size' => '540x960']],
    'wrong frame rate' => [['fps' => 25]],
    'has audio' => [['audio' => true]],
    'wrong duration' => [['seconds' => 5]],
]);

test('render skips the service once more than 300 seconds of the job are used up', function () {
    sm01EnableMotion($this->workspace->id);
    Carbon::setTestNow('2026-10-20 10:00:00');
    Http::fake();

    expect($this->renderer->render($this->image, 3, 'Hi', now()->subSeconds(301)))->toBeNull();

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->with('Story motion render fell back to the still frame', ['reason' => 'budget'])->once();
});

test('render still runs at exactly 300 seconds of the job', function () {
    sm01EnableMotion($this->workspace->id);
    Carbon::setTestNow('2026-10-20 10:00:00');
    $video = StoryMotionFixtures::video();
    Http::fake(['story-render:3000/*' => Http::response(file_get_contents($video), 200)]);

    $path = $this->renderer->render($this->image, 3, null, now()->subSeconds(300));

    expect($path)->toBeString();
    Http::assertSentCount(1);

    @unlink($path);
    @unlink($video);
});

test('render does not call the service for a missing or oversized photo', function () {
    sm01EnableMotion($this->workspace->id);
    Http::fake();

    expect($this->renderer->render('/nonexistent/photo.jpg', 3))->toBeNull();

    file_put_contents($this->image, str_repeat('x', 8 * 1024 * 1024 + 1));
    expect($this->renderer->render($this->image, 3))->toBeNull();

    Http::assertNothingSent();
});

test('the request timeout is the service limit plus 15 seconds at most', function () {
    sm01EnableMotion($this->workspace->id);

    expect($this->renderer->timeoutSeconds())->toBe(255);

    config()->set('trypost.platforms.facebook.story_motion.timeout_seconds', 9999);
    expect($this->renderer->timeoutSeconds())->toBe(255);

    config()->set('trypost.platforms.facebook.story_motion.timeout_seconds', 1);
    expect($this->renderer->timeoutSeconds())->toBe(10);

    config()->set('trypost.platforms.facebook.story_motion.timeout_seconds', 120);
    expect($this->renderer->timeoutSeconds())->toBe(120);
});

test('render applies the clamped timeout and a short connect timeout to the request itself', function () {
    sm01EnableMotion($this->workspace->id);
    config()->set('trypost.platforms.facebook.story_motion.timeout_seconds', 9999);
    $options = null;

    Http::fake(['story-render:3000/*' => function (Request $request, array $requestOptions) use (&$options) {
        $options = $requestOptions;

        return Http::response('', 503);
    }]);

    $this->renderer->render($this->image, 3);

    // Without ->timeout() Laravel's default of 30 seconds would cut every real render short.
    expect($options['timeout'])->toBe($this->renderer->timeoutSeconds())->toBe(255)
        ->and($options['connect_timeout'])->toBe(5);
});

test('render never writes the token or the headline to the log, whatever goes wrong', function (Closure $fake) {
    sm01EnableMotion($this->workspace->id);
    Http::fake(['story-render:3000/*' => $fake]);

    $logged = [];
    foreach (['warning', 'info', 'error', 'notice', 'debug'] as $level) {
        Log::shouldReceive($level)->zeroOrMoreTimes()->andReturnUsing(function (...$arguments) use (&$logged) {
            $logged[] = $arguments;
        });
    }

    $this->renderer->render($this->image, 3, 'Ελληνικά Geheim-Headline');

    $encoded = json_encode($logged, JSON_UNESCAPED_UNICODE);
    expect($logged)->not->toBe([])
        ->and($encoded)->not->toContain('secret-token-123')
        ->and($encoded)->not->toContain('Geheim-Headline')
        ->and($encoded)->not->toContain('Ελληνικά');
})->with([
    'busy' => [fn () => Http::response(['error' => 'busy'], 503)],
    'server error echoing the request' => [fn (Request $request) => Http::response('failed for Ελληνικά Geheim-Headline with secret-token-123', 500)],
    'connection error quoting the headers' => [fn () => throw new ConnectionException('cURL error 7 sending X-Story-Render-Token: secret-token-123 Ελληνικά Geheim-Headline')],
    'invalid output' => [fn () => Http::response('not a video', 200)],
]);
