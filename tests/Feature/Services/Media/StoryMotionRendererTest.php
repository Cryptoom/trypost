<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Media\StoryMotionRenderer;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\StoryMotionFixtures;

/**
 * Switches Story videos on for the installation (config) and for the workspace (its own setting) and
 * points the service at a fake host.
 *
 * @param  array<string, mixed>  $settings  workspace Story video settings on top of `enabled`
 */
function sm02EnableMotion(Workspace $workspace, array $settings = []): void
{
    config()->set('trypost.platforms.facebook.story_motion', [
        'enabled' => true,
        'token' => 'secret-token-123',
        'url' => 'http://story-render:3000',
        'timeout_seconds' => 255,
    ]);
    $workspace->update(['story_motion_settings' => ['enabled' => true, ...$settings]]);
}

function sm02ImageFile(): string
{
    $path = tempnam(sys_get_temp_dir(), 'sm02_img_');
    file_put_contents($path, 'fake-image-bytes');

    return $path;
}

/**
 * A real transparent PNG (what a brand logo usually is).
 */
function sm02TransparentPng(int $width = 600, int $height = 200): string
{
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
    imagefilledellipse($image, (int) ($width / 2), (int) ($height / 2), (int) ($width * 0.8), (int) ($height * 0.8), imagecolorallocatealpha($image, 200, 30, 30, 0));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

/**
 * The parts of a multipart request to the render service, by name.
 *
 * @return array<string, mixed>
 */
function sm02Parts(Request $request): array
{
    return collect($request->data())->mapWithKeys(fn (array $part) => [$part['name'] => $part['contents']])->all();
}

/**
 * Registers a fake answer and returns the spec of the one request the renderer sent.
 *
 * @return array{spec: array<string, mixed>, parts: array<string, mixed>}
 */
function sm02RenderedSpec(object $test, int $seconds = 3): array
{
    $video = StoryMotionFixtures::video(['seconds' => $seconds]);
    Http::fake(['story-render:3000/*' => Http::response(file_get_contents($video), 200, ['Content-Type' => 'video/mp4'])]);

    $path = $test->renderer->render($test->image, $seconds, $test->workspace, $test->postPlatform);
    @unlink($video);
    @unlink((string) $path);

    $request = collect(Http::recorded())->map(fn (array $pair) => $pair[0])->last(fn (Request $request) => str_contains($request->url(), 'story-render'));
    $parts = sm02Parts($request);

    return ['spec' => json_decode($parts['spec'], true), 'parts' => $parts];
}

beforeEach(function () {
    if (! StoryMotionFixtures::ffmpegAvailable()) {
        $this->markTestSkipped('ffmpeg or ffprobe is not installed on this machine.');
    }

    $this->workspace = Workspace::factory()->create(['brand_color' => '#FFE066']);
    $account = SocialAccount::factory()->facebook()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);
    $this->postPlatform = PostPlatform::factory()->facebook()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::Facebook,
        'content_type' => ContentType::FacebookStory,
        'meta' => [],
    ]);
    $this->renderer = app(StoryMotionRenderer::class);
    $this->image = sm02ImageFile();
    Storage::fake();
    Http::preventStrayRequests();
    Log::spy();
});

afterEach(function () {
    @unlink($this->image);
    Carbon::setTestNow();
});

test('story motion is off by default and has no workspace allowlist any more', function () {
    expect($this->renderer->isEnabledFor($this->workspace))->toBeFalse()
        ->and($this->renderer->isAvailable())->toBeFalse()
        ->and($this->renderer->skipReason($this->workspace))->toBe('disabled')
        ->and(config('trypost.platforms.facebook.story_motion.enabled'))->toBeFalse()
        ->and(array_keys(config('trypost.platforms.facebook.story_motion')))->toBe(['enabled', 'token', 'url', 'timeout_seconds']);
});

test('render sends the photo and the spec as multipart with the token and returns the silent video', function () {
    sm02EnableMotion($this->workspace);
    $this->postPlatform->update(['meta' => ['story_headline' => 'Ελληνικά Παιχνίδια']]);
    $video = StoryMotionFixtures::video();

    Http::fake(['story-render:3000/*' => Http::response(file_get_contents($video), 200, ['Content-Type' => 'video/mp4'])]);

    $path = $this->renderer->render($this->image, 3, $this->workspace, $this->postPlatform->fresh());

    expect($path)->toBeString()->and(file_exists($path))->toBeTrue()->and(filesize($path))->toBe(filesize($video));

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request) {
        $parts = sm02Parts($request);
        $spec = json_decode($parts['spec'], true);

        return $request->method() === 'POST'
            && $request->url() === 'http://story-render:3000/render'
            && ($request->header('X-Story-Render-Token')[0] ?? null) === 'secret-token-123'
            && str_starts_with($request->header('Content-Type')[0] ?? '', 'multipart/form-data')
            && array_keys($parts) === ['photo', 'spec']
            && $parts['photo'] === 'fake-image-bytes'
            && $spec['duration'] === 3
            && $spec['headline'] === 'Ελληνικά Παιχνίδια';
    });

    @unlink($path);
    @unlink($video);
});

test('the spec goes as a plain text field, not as a file, and every key is one the service knows', function () {
    sm02EnableMotion($this->workspace, ['ai_notice' => ['enabled' => true, 'text' => 'AI-generated image'], 'ending' => ['enabled' => true, 'name' => 'PlayCraft', 'line' => 'Paphos']]);
    $this->postPlatform->update(['meta' => ['story_headline' => 'Hi', 'story_subline' => 'Sub', 'story_badge' => 'NEW', 'story_headline_alt' => 'Γεια', 'story_subline_alt' => 'Υπο']]);
    $run = sm02RenderedSpec($this);

    $request = collect(Http::recorded())->map(fn (array $pair) => $pair[0])->first();
    $specPart = collect($request->data())->firstWhere('name', 'spec');
    $photoPart = collect($request->data())->firstWhere('name', 'photo');

    // A text field has no filename, a file part does.
    expect($specPart)->not->toHaveKey('filename')
        ->and($photoPart)->toHaveKey('filename');

    // The service rejects every key it does not list, so the two sides must agree exactly.
    $service = (string) file_get_contents(base_path('docker/story-render/lib/spec.mjs'));
    preg_match('/const TOP_LEVEL = Object\\.freeze\\(\\[(.*?)\\]\\)/s', $service, $top);
    preg_match('/const ENDING_KEYS = Object\\.freeze\\(\\[(.*?)\\]\\)/s', $service, $ending);
    $serviceTop = array_map(fn (string $key) => trim($key, " '"), explode(',', $top[1]));
    $serviceEnding = array_map(fn (string $key) => trim($key, " '"), explode(',', $ending[1]));

    expect(array_keys($run['spec']))->toEqualCanonicalizing($serviceTop)
        ->and(array_keys($run['spec']['ending']))->toEqualCanonicalizing($serviceEnding);
});

test('render builds the spec from the workspace settings: font, notice, ending, effect and accent colour', function () {
    sm02EnableMotion($this->workspace, [
        'font' => 'comfortaa',
        'effects' => ['sparkle', 'snap'],
        'mode' => 'fixed',
        'ai_notice' => ['enabled' => true, 'text' => 'KI-Bild'],
        'ending' => ['enabled' => true, 'name' => 'PlayCraft Premium Toys', 'line' => 'Paphos'],
    ]);

    $spec = sm02RenderedSpec($this)['spec'];

    expect($spec['font'])->toBe('comfortaa')
        ->and($spec['effect'])->toBe('snap', 'the first selected effect in the canonical order')
        ->and($spec['aiNotice'])->toBe('KI-Bild')
        ->and($spec['ending'])->toBe(['name' => 'PlayCraft Premium Toys', 'line' => 'Paphos'])
        ->and($spec['accent'])->toBe('#FFE066')
        ->and($spec['logoLayer'])->toBeFalse();

    // notice and ending off: nothing of them goes out
    $this->workspace->update(['story_motion_settings' => ['enabled' => true, 'ai_notice' => ['enabled' => false, 'text' => 'KI-Bild'], 'ending' => ['enabled' => false, 'name' => 'x', 'line' => 'y']]]);
    $off = sm02RenderedSpec($this)['spec'];

    expect($off['aiNotice'])->toBeNull()->and($off['ending'])->toBeNull();
});

test('render falls back to gold when the brand colour cannot be read on the text veil', function (?string $brand, string $expected) {
    sm02EnableMotion($this->workspace);
    $this->workspace->update(['brand_color' => $brand]);

    expect(sm02RenderedSpec($this)['spec']['accent'])->toBe($expected);
})->with([
    'orange fails against the light side' => ['#EF4C1E', '#FDB31B'],
    'navy fails against black' => ['#000080', '#FDB31B'],
    'light yellow stays' => ['#FFE066', '#FFE066'],
    'short hex is expanded' => ['#fff', '#FFFFFF'],
    'no colour at all' => [null, '#FDB31B'],
]);

test('the workspace logo goes through the real upload path and reaches the service as a JPEG with the logo layer on', function () {
    sm02EnableMotion($this->workspace, ['logo_layer' => true, 'ai_notice' => ['enabled' => true, 'text' => 'AI-generated image']]);
    $media = $this->workspace->addMedia(UploadedFile::fake()->createWithContent('logo.png', sm02TransparentPng()), 'logo');
    $run = sm02RenderedSpec($this);

    // the upload path turns the PNG into a JPEG (the transparent area is filled), that is what is sent
    expect($media->mime_type)->toBe('image/jpeg')
        ->and(data_get($media->meta, 'width'))->toBe(600)
        ->and(str_starts_with($run['parts']['logo'], "\xFF\xD8\xFF"))->toBeTrue()
        ->and($run['parts']['logo'])->toBe(Storage::get($media->path))
        ->and($run['spec']['logoLayer'])->toBeTrue()
        ->and(array_keys($run['parts']))->toBe(['photo', 'logo', 'spec']);
});

test('a 4000 x 1528 logo is scaled on upload, so it is sent and the Story gets the logo layer and the strong motion', function () {
    sm02EnableMotion($this->workspace, ['logo_layer' => true, 'ai_notice' => ['enabled' => true, 'text' => 'AI-generated image']]);
    $media = $this->workspace->addMedia(UploadedFile::fake()->createWithContent('logo.png', sm02TransparentPng(4000, 1528)), 'logo');
    $run = sm02RenderedSpec($this);

    expect(data_get($media->meta, 'width'))->toBe(2000)
        ->and(data_get($media->meta, 'height'))->toBe(764)
        ->and($run['parts'])->toHaveKey('logo')
        ->and($run['spec']['logoLayer'])->toBeTrue();
    Log::shouldNotHaveReceived('info');
});

test('a PNG logo that bypassed the conversion is sent as it is', function () {
    sm02EnableMotion($this->workspace, ['logo_layer' => true]);
    $png = sm02TransparentPng();
    Storage::put('medias/direct.png', $png);
    $this->workspace->media()->create(['collection' => 'logo', 'type' => 'image', 'path' => 'medias/direct.png', 'original_filename' => 'direct.png', 'mime_type' => 'image/png', 'size' => strlen($png), 'order' => 0, 'meta' => ['width' => 600, 'height' => 200]]);

    $run = sm02RenderedSpec($this);

    expect($run['parts']['logo'])->toBe($png)->and($run['spec']['logoLayer'])->toBeTrue();
});

test('a logo the service cannot take is left out with a log line, the story still renders without the logo layer', function (string $detail, Closure $makeMedia) {
    sm02EnableMotion($this->workspace, ['logo_layer' => true]);
    $makeMedia($this->workspace);

    $run = sm02RenderedSpec($this);

    expect($run['spec']['logoLayer'])->toBeFalse()
        ->and($run['parts'])->not->toHaveKey('logo');
    Log::shouldHaveReceived('info')->with('Story motion logo layer dropped', ['reason' => 'logo_unusable', 'detail' => $detail])->once();
})->with([
    'no logo uploaded' => ['missing', function (Workspace $workspace) {}],
    'over 2 MB' => ['size', function (Workspace $workspace) {
        Storage::put('medias/big.jpg', "\xFF\xD8\xFF".str_repeat('x', 100));
        $workspace->media()->create(['collection' => 'logo', 'type' => 'image', 'path' => 'medias/big.jpg', 'original_filename' => 'big.jpg', 'mime_type' => 'image/jpeg', 'size' => 2 * 1024 * 1024 + 1, 'order' => 0, 'meta' => ['width' => 500, 'height' => 500]]);
    }],
    'wider than 2000 pixels' => ['dimensions', function (Workspace $workspace) {
        Storage::put('medias/wide.jpg', "\xFF\xD8\xFF".str_repeat('x', 100));
        $workspace->media()->create(['collection' => 'logo', 'type' => 'image', 'path' => 'medias/wide.jpg', 'original_filename' => 'wide.jpg', 'mime_type' => 'image/jpeg', 'size' => 1000, 'order' => 0, 'meta' => ['width' => 4000, 'height' => 1528]]);
    }],
    'dimensions unknown' => ['dimensions', function (Workspace $workspace) {
        Storage::put('medias/nodim.jpg', "\xFF\xD8\xFF".str_repeat('x', 100));
        $workspace->media()->create(['collection' => 'logo', 'type' => 'image', 'path' => 'medias/nodim.jpg', 'original_filename' => 'nodim.jpg', 'mime_type' => 'image/jpeg', 'size' => 1000, 'order' => 0, 'meta' => []]);
    }],
    'a WebP' => ['format', function (Workspace $workspace) {
        Storage::put('medias/logo.webp', 'RIFF'.str_repeat('x', 4).'WEBP'.str_repeat('x', 100));
        $workspace->media()->create(['collection' => 'logo', 'type' => 'image', 'path' => 'medias/logo.webp', 'original_filename' => 'logo.webp', 'mime_type' => 'image/webp', 'size' => 1000, 'order' => 0, 'meta' => ['width' => 500, 'height' => 500]]);
    }],
    'a file that is gone from storage' => ['unreadable', function (Workspace $workspace) {
        $workspace->media()->create(['collection' => 'logo', 'type' => 'image', 'path' => 'medias/gone.jpg', 'original_filename' => 'gone.jpg', 'mime_type' => 'image/jpeg', 'size' => 1000, 'order' => 0, 'meta' => ['width' => 500, 'height' => 500]]);
    }],
]);

test('with the logo layer off the stored logo is not read at all', function () {
    sm02EnableMotion($this->workspace, ['logo_layer' => false]);
    $this->workspace->addMedia(UploadedFile::fake()->createWithContent('logo.png', sm02TransparentPng()), 'logo');
    Storage::shouldReceive('get')->never();

    $run = sm02RenderedSpec($this);

    expect($run['spec']['logoLayer'])->toBeFalse()->and($run['parts'])->not->toHaveKey('logo');
});

test('render falls back with a reason and leaves no file for every kind of failure', function (string $reason, Closure $fake) {
    sm02EnableMotion($this->workspace);
    Http::fake(['story-render:3000/*' => $fake]);
    $before = glob(sys_get_temp_dir().'/*.mp4') ?: [];

    expect($this->renderer->render($this->image, 3, $this->workspace, $this->postPlatform))->toBeNull();

    Log::shouldHaveReceived('warning')->with('Story motion render fell back to the still frame', ['reason' => $reason])->once();
    expect(array_values(array_diff(glob(sys_get_temp_dir().'/*.mp4') ?: [], $before)))->toBe([]);
})->with([
    'service busy' => ['busy', fn () => Http::response(['error' => 'busy'], 503)],
    'service timeout' => ['timeout', fn () => Http::response(['error' => 'render_timeout'], 504)],
    'wrong token' => ['unauthorized', fn () => Http::response(['error' => 'unauthorized'], 401)],
    'server error' => ['http_error', fn () => Http::response(['error' => 'render_failed'], 500)],
    'spec rejected by the service' => ['rejected', fn () => Http::response(['error' => 'spec_unknown_key'], 400)],
    'client side timeout' => ['timeout', fn () => throw new ConnectionException('cURL error 28: Operation timed out after 255001 milliseconds')],
    'connection refused' => ['unavailable', fn () => throw new ConnectionException('cURL error 7: Failed to connect to story-render port 3000')],
    'unexpected exception' => ['error', fn () => throw new RuntimeException('something odd')],
    'empty body' => ['invalid_output', fn () => Http::response('', 200)],
    'not a video' => ['invalid_output', fn () => Http::response('definitely not a video', 200)],
]);

test('settings the spec cannot carry end in the still frame with the reason invalid_settings and no request', function () {
    sm02EnableMotion($this->workspace);
    Http::fake();

    // a clip length outside 3 to 20 seconds is not sendable
    expect($this->renderer->render($this->image, 2, $this->workspace, $this->postPlatform))->toBeNull()
        ->and($this->renderer->render($this->image, 21, $this->workspace, $this->postPlatform))->toBeNull();

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->with('Story motion render fell back to the still frame', ['reason' => 'invalid_settings'])->twice();
});

test('a settings column full of garbage renders with the defaults instead of failing', function (mixed $stored) {
    config()->set('trypost.platforms.facebook.story_motion', ['enabled' => true, 'token' => 'secret-token-123', 'url' => 'http://story-render:3000', 'timeout_seconds' => 255]);
    $this->workspace->forceFill(['story_motion_settings' => $stored])->saveQuietly();

    // the workspace is off for garbage (default), but a caller that renders anyway still gets a valid spec
    $spec = sm02RenderedSpec($this)['spec'];

    expect($spec['effect'])->toBe('focus')->and($spec['font'])->toBe('inter')->and($spec['aiNotice'])->toBeNull()->and($spec['ending'])->toBeNull();
})->with([
    'null' => [null],
    'empty array' => [[]],
    'a list' => [['a', 'b']],
    'wrong types' => [['enabled' => 'yes', 'font' => 7, 'effects' => 'focus', 'mode' => [], 'logo_layer' => 1, 'ai_notice' => 'x', 'ending' => false]],
    'unknown values' => [['font' => 'comic-sans', 'effects' => ['wobble'], 'mode' => 'shuffle']],
]);

test('render rejects a video of the wrong size or with an audio stream', function (array $options) {
    sm02EnableMotion($this->workspace);
    $video = StoryMotionFixtures::video($options);
    Http::fake(['story-render:3000/*' => Http::response(file_get_contents($video), 200)]);

    expect($this->renderer->render($this->image, 3, $this->workspace, $this->postPlatform))->toBeNull();

    Log::shouldHaveReceived('warning')->with('Story motion render fell back to the still frame', ['reason' => 'invalid_output'])->once();
    @unlink($video);
})->with([
    'wrong dimensions' => [['size' => '540x960']],
    'wrong frame rate' => [['fps' => 25]],
    'has audio' => [['audio' => true]],
    'wrong duration' => [['seconds' => 5]],
]);

test('render skips the service once more than 300 seconds of the job are used up', function () {
    sm02EnableMotion($this->workspace);
    Carbon::setTestNow('2026-10-20 10:00:00');
    Http::fake();

    expect($this->renderer->render($this->image, 3, $this->workspace, $this->postPlatform, now()->subSeconds(301)))->toBeNull();

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->with('Story motion render fell back to the still frame', ['reason' => 'budget'])->once();
});

test('render still runs at exactly 300 seconds of the job', function () {
    sm02EnableMotion($this->workspace);
    Carbon::setTestNow('2026-10-20 10:00:00');
    $video = StoryMotionFixtures::video();
    Http::fake(['story-render:3000/*' => Http::response(file_get_contents($video), 200)]);

    $path = $this->renderer->render($this->image, 3, $this->workspace, $this->postPlatform, now()->subSeconds(300));

    expect($path)->toBeString();
    Http::assertSentCount(1);

    @unlink($path);
    @unlink($video);
});

test('render does not call the service for a missing or oversized photo', function () {
    sm02EnableMotion($this->workspace);
    Http::fake();

    expect($this->renderer->render('/nonexistent/photo.jpg', 3, $this->workspace, $this->postPlatform))->toBeNull();

    file_put_contents($this->image, str_repeat('x', 8 * 1024 * 1024 + 1));
    expect($this->renderer->render($this->image, 3, $this->workspace, $this->postPlatform))->toBeNull();

    Http::assertNothingSent();
});

test('the request timeout is the service limit plus 15 seconds at most', function () {
    sm02EnableMotion($this->workspace);

    expect($this->renderer->timeoutSeconds())->toBe(255);

    config()->set('trypost.platforms.facebook.story_motion.timeout_seconds', 9999);
    expect($this->renderer->timeoutSeconds())->toBe(255);

    config()->set('trypost.platforms.facebook.story_motion.timeout_seconds', 1);
    expect($this->renderer->timeoutSeconds())->toBe(10);

    config()->set('trypost.platforms.facebook.story_motion.timeout_seconds', 120);
    expect($this->renderer->timeoutSeconds())->toBe(120);
});

test('render applies the clamped timeout and a short connect timeout to the request itself', function () {
    sm02EnableMotion($this->workspace);
    config()->set('trypost.platforms.facebook.story_motion.timeout_seconds', 9999);
    $options = null;

    Http::fake(['story-render:3000/*' => function (Request $request, array $requestOptions) use (&$options) {
        $options = $requestOptions;

        return Http::response('', 503);
    }]);

    $this->renderer->render($this->image, 3, $this->workspace, $this->postPlatform);

    // Without ->timeout() Laravel's default of 30 seconds would cut every real render short.
    expect($options['timeout'])->toBe($this->renderer->timeoutSeconds())->toBe(255)
        ->and($options['connect_timeout'])->toBe(5);
});

test('story motion logs never contain headline, subline, badge, second language, ending, token or logo bytes', function (Closure $fake) {
    sm02EnableMotion($this->workspace, [
        'logo_layer' => true,
        'ai_notice' => ['enabled' => true, 'text' => 'Geheim-Hinweis'],
        'ending' => ['enabled' => true, 'name' => 'Geheim-Abschluss', 'line' => 'Geheim-Zeile-Ende'],
    ]);
    $logo = sm02TransparentPng();
    $media = $this->workspace->addMedia(UploadedFile::fake()->createWithContent('logo.png', $logo), 'logo');
    $logoBytes = Storage::get($media->path);
    $this->postPlatform->update(['meta' => ['story_headline' => 'Ελληνικά Geheim-Headline', 'story_subline' => 'Geheim-Subline', 'story_badge' => 'Geheim-Bdg', 'story_headline_alt' => 'Geheim-Alt', 'story_subline_alt' => 'Geheim-Alt-Sub']]);
    Http::fake(['story-render:3000/*' => $fake]);

    $logged = [];
    foreach (['warning', 'info', 'error', 'notice', 'debug'] as $level) {
        Log::shouldReceive($level)->zeroOrMoreTimes()->andReturnUsing(function (...$arguments) use (&$logged) {
            $logged[] = $arguments;
        });
    }

    $this->renderer->render($this->image, 3, $this->workspace, $this->postPlatform->fresh());

    $encoded = json_encode($logged, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    expect($logged)->not->toBe([])
        ->and($encoded)->not->toContain('secret-token-123')
        ->and($encoded)->not->toContain('Geheim')
        ->and($encoded)->not->toContain('Ελληνικά')
        ->and($encoded)->not->toContain(base64_encode(substr($logoBytes, 0, 60)))
        ->and($encoded)->not->toContain('JFIF');
})->with([
    'busy' => [fn () => Http::response(['error' => 'busy'], 503)],
    'server error echoing the request' => [fn (Request $request) => Http::response('failed for Ελληνικά Geheim-Headline with secret-token-123', 500)],
    'connection error quoting the headers' => [fn () => throw new ConnectionException('cURL error 7 sending X-Story-Render-Token: secret-token-123 Ελληνικά Geheim-Headline')],
    'invalid output' => [fn () => Http::response('not a video', 200)],
    'spec rejected' => [fn () => Http::response(['error' => 'spec_text_too_long', 'field' => 'headline'], 400)],
    'unexpected exception quoting the text' => [fn () => throw new RuntimeException('boom with Ελληνικά Geheim-Headline secret-token-123')],
]);
