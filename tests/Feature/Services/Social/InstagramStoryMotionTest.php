<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status as PlatformStatus;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\PlatformUnavailableException;
use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\FacebookPublishException;
use App\Exceptions\Social\InstagramPublishException;
use App\Exceptions\TokenExpiredException;
use App\Jobs\PublishToSocialPlatform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\StoryMusicGenerator;
use App\Services\Media\StoryVideoBuilder;
use App\Services\Media\StoryVideoHost;
use App\Services\Social\FacebookPublisher;
use App\Services\Social\InstagramPublisher;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Tests\Support\StoryMotionFixtures;

/**
 * PATCH:story-motion-instagram: an Instagram photo Story of a workspace with "also for Instagram Stories"
 * on is published as the same Story video as on Facebook, and falls back to the photo Story on any problem.
 */
function sm04JpegBytes(): string
{
    $image = (new ImageManager(Driver::class))->createImage(1200, 800)->fill('888888');

    return (string) $image->encodeUsingMediaType('image/jpeg', quality: 80);
}

/** @return list<string> */
function sm04HostedVideos(): array
{
    return Storage::allFiles(StoryVideoHost::DIRECTORY);
}

/** @return list<string> */
function sm04TempMedia(): array
{
    return array_values(array_merge(glob(sys_get_temp_dir().'/*.mp4') ?: [], glob(sys_get_temp_dir().'/ig_story_*') ?: []));
}

/**
 * @param  array<string, mixed>  $settings  the workspace's story_motion_settings
 */
function sm04Prepare(object $test, array $settings = ['enabled' => true, 'instagram' => true], bool $masterSwitch = true, array $meta = []): void
{
    if (! StoryMotionFixtures::ffmpegAvailable()) {
        $test->markTestSkipped('ffmpeg or ffprobe is not installed on this machine.');
    }

    Storage::fake();
    config()->set('trypost.platforms.facebook.story_photo_duration_seconds', 3);
    config()->set('trypost.platforms.facebook.story_motion', [
        'enabled' => $masterSwitch,
        'token' => 'secret-token-123',
        'url' => 'http://story-render:3000',
        'timeout_seconds' => 255,
    ]);

    $test->workspace->update(['story_motion_settings' => $settings]);
    $test->postPlatform->update(['content_type' => ContentType::InstagramStory, 'meta' => $meta]);
    $test->post->update([
        'media' => [[
            'id' => 'test-media-story',
            'path' => 'media/2026-01/story.jpg',
            'url' => 'https://example.com/media/2026-01/story.jpg',
            'mime_type' => 'image/jpeg',
            'original_filename' => 'story.jpg',
        ]],
    ]);
}

/**
 * Fakes the media host, the render service and the Instagram Graph API. `$containers` are the answers to the
 * container creations in order, `$status` the answers to the status checks (the container id decides which
 * list), and every request is recorded by label in `$test->sm04Calls`.
 *
 * `$publish` is the answer to media_publish (default: published as story-media-1).
 *
 * @param  array<int, mixed>  $containers
 * @param  array<string, mixed>  $status
 */
function sm04Fake(object $test, mixed $render, array $containers = [], array $status = [], mixed $publish = null): void
{
    $test->sm04Calls = [];
    $test->sm04Containers = [];
    $containers = $containers ?: [Http::response(['id' => 'story-container-1'], 200)];
    $creations = 0;

    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([
        '*example.com/media/*' => function () use ($test) {
            $test->sm04Calls[] = 'download';

            return Http::response(sm04JpegBytes(), 200);
        },
        'story-render:3000/*' => function () use ($test, $render) {
            $test->sm04Calls[] = 'render';

            return $render instanceof Closure ? $render() : $render;
        },
        'https://graph.instagram.com/v25.0/ig_123456789/media' => function (Request $request) use ($test, $containers, &$creations) {
            $test->sm04Calls[] = 'container';
            $test->sm04Containers[] = $request->data();

            return $containers[min($creations++, count($containers) - 1)];
        },
        'https://graph.instagram.com/v25.0/ig_123456789/media_publish' => function () use ($test, $publish) {
            $test->sm04Calls[] = 'media_publish';

            return $publish ?? Http::response(['id' => 'story-media-1'], 200);
        },
        'https://graph.instagram.com/v25.0/story-media-1*' => Http::response(['permalink' => 'https://www.instagram.com/stories/testuser/1/'], 200),
        'https://graph.instagram.com/v25.0/story-container-*' => function (Request $request) use ($test, $status) {
            $test->sm04Calls[] = 'status';
            $id = basename(parse_url($request->url(), PHP_URL_PATH));

            return $status[$id] ?? Http::response(['status_code' => 'FINISHED'], 200);
        },
    ]);
}

/** The container creation request that carries the story (the last one). */
function sm04LastContainer(object $test): array
{
    return end($test->sm04Containers);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);

    $this->socialAccount = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'ig_123456789',
        'username' => 'testuser',
        'token_expires_at' => now()->addDays(60),
    ]);

    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => 'Hello from Instagram!',
    ]);

    $this->postPlatform = PostPlatform::factory()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->socialAccount->id,
        'platform' => Platform::Instagram,
        'content_type' => ContentType::InstagramStory,
    ]);

    $this->publisher = new InstagramPublisher;
    $this->rendered = null;
});

afterEach(function () {
    if ($this->rendered !== null) {
        @unlink($this->rendered);
    }
});

function sm04RenderedVideo(object $test): PromiseInterface
{
    $test->rendered = StoryMotionFixtures::video();

    return Http::response(file_get_contents($test->rendered), 200);
}

test('instagram story motion: a photo story with the switch on is published as a hosted video, container by container', function () {
    sm04Prepare($this);
    sm04Fake($this, sm04RenderedVideo($this));
    $before = sm04TempMedia();

    $result = $this->publisher->publish($this->postPlatform->fresh());

    $container = sm04LastContainer($this);

    expect($result['id'])->toBe('story-media-1')
        ->and($this->sm04Calls)->toBe(['download', 'render', 'container', 'status', 'media_publish'])
        ->and($container['media_type'])->toBe('STORIES')
        ->and($container)->not->toHaveKey('image_url')
        ->and($container['video_url'])->toStartWith(Storage::url(StoryVideoHost::DIRECTORY.'/'))
        ->and($container['video_url'])->toEndWith('.mp4')
        // the file is deleted right after the publish and nothing is left in the temp dir
        ->and(sm04HostedVideos())->toBe([])
        ->and(array_values(array_diff(sm04TempMedia(), $before, [$this->rendered])))->toBe([]);
});

test('instagram story motion: the hosted video is a real h264 aac mp4 of 1080x1920 while Instagram fetches it', function () {
    sm04Prepare($this);
    sm04Fake($this, sm04RenderedVideo($this), status: ['story-container-1' => Http::response(['status_code' => 'IN_PROGRESS'], 200)]);

    expect(fn () => $this->publisher->publish($this->postPlatform->fresh()))->toThrow(PlatformUnavailableException::class);

    $hosted = sm04HostedVideos();
    $path = sys_get_temp_dir().'/sm04_hosted_'.uniqid().'.mp4';
    file_put_contents($path, Storage::get($hosted[0]));
    $info = json_decode((string) shell_exec('ffprobe -v error -print_format json -show_streams '.escapeshellarg($path)), true);
    @unlink($path);
    $streams = collect($info['streams']);

    expect($hosted)->toHaveCount(1)
        ->and($streams->firstWhere('codec_type', 'video')['codec_name'])->toBe('h264')
        ->and($streams->firstWhere('codec_type', 'video')['width'])->toBe(1080)
        ->and($streams->firstWhere('codec_type', 'video')['height'])->toBe(1920)
        ->and($streams->firstWhere('codec_type', 'audio')['codec_name'])->toBe('aac');
});

test('instagram story motion: with the switch off the story stays the photo story and nothing is rendered', function (array $settings, bool $masterSwitch) {
    sm04Prepare($this, $settings, $masterSwitch);
    sm04Fake($this, Http::response('', 500));

    $this->publisher->publish($this->postPlatform->fresh());

    $container = sm04LastContainer($this);

    expect($this->sm04Calls)->not->toContain('render')
        ->and($container['media_type'])->toBe('STORIES')
        ->and($container)->not->toHaveKey('video_url')
        ->and($container['image_url'])->toContain('social-crops/')
        ->and(sm04HostedVideos())->toBe([]);
})->with([
    'instagram switch off' => [['enabled' => true, 'instagram' => false], true],
    'a row stored before the switch existed' => [['enabled' => true, 'font' => 'noto-sans'], true],
    'no settings at all' => [[], true],
    'workspace Story videos off, instagram on' => [['enabled' => false, 'instagram' => true], true],
    'installation switch off' => [['enabled' => true, 'instagram' => true], false],
]);

test('instagram story motion: a render service that fails ends in the photo story', function (mixed $render) {
    sm04Prepare($this);
    sm04Fake($this, $render);
    $before = sm04TempMedia();

    $result = $this->publisher->publish($this->postPlatform->fresh());

    $container = sm04LastContainer($this);

    expect($result['id'])->toBe('story-media-1')
        ->and($this->sm04Calls)->toContain('render')
        ->and($container)->not->toHaveKey('video_url')
        ->and($container['image_url'])->toContain('social-crops/')
        ->and(sm04HostedVideos())->toBe([])
        ->and(array_values(array_diff(sm04TempMedia(), $before)))->toBe([]);
})->with([
    'busy' => fn () => Http::response('', 503),
    'rejected' => fn () => Http::response('', 400),
    'server error' => fn () => Http::response('', 500),
    'not a video' => fn () => Http::response('not a video at all', 200),
]);

test('instagram story motion: a render service that cannot be reached ends in the photo story', function () {
    sm04Prepare($this);
    sm04Fake($this, Http::failedConnection());

    $this->publisher->publish($this->postPlatform->fresh());

    expect(sm04LastContainer($this))->toHaveKey('image_url')->not->toHaveKey('video_url')
        ->and(sm04HostedVideos())->toBe([]);
});

test('instagram story motion: a hosting failure ends in the photo story', function () {
    sm04Prepare($this);
    sm04Fake($this, sm04RenderedVideo($this));
    $host = Mockery::mock(StoryVideoHost::class);
    $host->shouldReceive('host')->once()->andThrow(new RuntimeException('disk full'));
    $host->shouldReceive('delete')->andReturnNull();
    app()->instance(StoryVideoHost::class, $host);
    $before = sm04TempMedia();

    $result = $this->publisher->publish($this->postPlatform->fresh());

    expect($result['id'])->toBe('story-media-1')
        ->and(sm04LastContainer($this))->toHaveKey('image_url')->not->toHaveKey('video_url')
        ->and(array_values(array_diff(sm04TempMedia(), $before, [$this->rendered])))->toBe([]);
});

test('instagram story motion: a render that runs out of the time budget is not started', function () {
    sm04Prepare($this);
    sm04Fake($this, Http::response('', 500));
    $this->travelTo(now());

    // The budget counts from the start of publish(), so a start 301 seconds ago leaves no render time.
    $builder = app(StoryVideoBuilder::class);
    $video = $builder->build('https://example.com/media/2026-01/story.jpg', null, [], [
        'workspace' => $this->workspace->fresh(),
        'postPlatform' => $this->postPlatform->fresh(),
        'startedAt' => now()->subSeconds(301),
        'active' => true,
    ], 'ig_story_img_', heldFrameFallback: false);

    expect($video)->toBeNull()->and($this->sm04Calls)->toBe(['download']);
});

test('instagram story motion: a pending container is resumed without a second render or download', function () {
    sm04Prepare($this);
    sm04Fake($this, sm04RenderedVideo($this), status: ['story-container-1' => Http::response(['status_code' => 'IN_PROGRESS'], 200)]);

    try {
        $this->publisher->publish($this->postPlatform->fresh());
        test()->fail('Expected the in-progress container to be rescheduled.');
    } catch (PlatformUnavailableException $exception) {
        $workflow = $exception->context['instagram_workflow'];

        expect($workflow['stage'])->toBe('final_container')
            ->and($workflow['container_id'])->toBe('story-container-1')
            ->and($workflow['story_video_path'])->toStartWith(StoryVideoHost::DIRECTORY.'/');

        $this->postPlatform->update(['error_context' => $exception->context]);
    }

    // still processing: the video stays where Instagram fetches it, and a second poll keeps the checkpoint
    expect(sm04HostedVideos())->toHaveCount(1);

    $callsBefore = $this->sm04Calls;

    try {
        (new InstagramPublisher)->publish($this->postPlatform->fresh());
        test()->fail('Expected the container to be still processing.');
    } catch (PlatformUnavailableException $exception) {
        expect($exception->context['instagram_workflow']['story_video_path'])->toBe($workflow['story_video_path']);
    }

    expect(array_slice($this->sm04Calls, count($callsBefore)))->toBe(['status']);

    // finished: published, and only now the video is deleted
    sm04Fake($this, Http::response('', 500));
    $result = (new InstagramPublisher)->publish($this->postPlatform->fresh());

    expect($result['id'])->toBe('story-media-1')
        ->and($this->sm04Calls)->toBe(['status', 'media_publish'])
        ->and($this->sm04Calls)->not->toContain('render')
        ->and($this->sm04Calls)->not->toContain('download')
        ->and(sm04HostedVideos())->toBe([]);
});

test('instagram story motion: a crash after media_publish deletes the video on the next attempt', function () {
    sm04Prepare($this);
    $path = StoryVideoHost::DIRECTORY.'/'.Str::uuid().'.mp4';
    Storage::put($path, 'video');
    $this->postPlatform->update(['error_context' => ['instagram_workflow' => [
        'stage' => 'final_container',
        'container_id' => 'story-container-1',
        'media_id' => 'story-media-1',
        'story_video_path' => $path,
    ]]]);
    sm04Fake($this, Http::response('', 500));

    $result = (new InstagramPublisher)->publish($this->postPlatform->fresh());

    expect($result['id'])->toBe('story-media-1')
        ->and($this->sm04Calls)->toBe([])
        ->and(sm04HostedVideos())->toBe([]);
});

test('instagram story motion: Instagram refusing the video at container creation ends in the photo story', function () {
    sm04Prepare($this);
    sm04Fake($this, sm04RenderedVideo($this), containers: [
        Http::response(['error' => ['message' => 'Unsupported video', 'code' => 9004, 'error_subcode' => 2207026]], 400),
        Http::response(['id' => 'story-container-1'], 200),
    ]);

    $result = $this->publisher->publish($this->postPlatform->fresh());

    expect($result['id'])->toBe('story-media-1')
        ->and($this->sm04Calls)->toBe(['download', 'render', 'container', 'download', 'container', 'status', 'media_publish'])
        ->and($this->sm04Containers[0])->toHaveKey('video_url')
        ->and(sm04LastContainer($this))->toHaveKey('image_url')->not->toHaveKey('video_url')
        ->and(sm04HostedVideos())->toBe([]);
});

test('instagram story motion: a video that fails to process on a later attempt is replaced by the photo story', function () {
    sm04Prepare($this);
    sm04Fake($this, sm04RenderedVideo($this), status: ['story-container-1' => Http::response(['status_code' => 'IN_PROGRESS'], 200)]);

    try {
        $this->publisher->publish($this->postPlatform->fresh());
        test()->fail('Expected the in-progress container to be rescheduled.');
    } catch (PlatformUnavailableException $exception) {
        $this->postPlatform->update(['error_context' => [...$exception->context, 'error' => 'kept']]);
    }

    sm04Fake($this, Http::response('', 500), containers: [Http::response(['id' => 'story-container-2'], 200)], status: [
        'story-container-1' => Http::response(['status_code' => 'ERROR', 'status' => 'Video format not supported'], 200),
    ]);

    $result = (new InstagramPublisher)->publish($this->postPlatform->fresh());

    $stored = $this->postPlatform->fresh()->error_context;

    expect($result['id'])->toBe('story-media-1')
        ->and($this->sm04Calls)->toBe(['status', 'download', 'container', 'status', 'media_publish'])
        ->and(sm04LastContainer($this))->toHaveKey('image_url')->not->toHaveKey('video_url')
        // the checkpoint of the refused video is gone, the one of the photo story took its place
        ->and($stored['error'])->toBe('kept')
        ->and($stored['instagram_workflow']['container_id'])->toBe('story-container-2')
        ->and($stored['instagram_workflow'])->not->toHaveKey('story_video_path')
        ->and(sm04HostedVideos())->toBe([]);
});

test('instagram story motion: when the photo story fails too, the refused video container is not resumed again', function () {
    sm04Prepare($this);
    sm04Fake($this, sm04RenderedVideo($this), status: ['story-container-1' => Http::response(['status_code' => 'IN_PROGRESS'], 200)]);

    try {
        $this->publisher->publish($this->postPlatform->fresh());
        test()->fail('Expected the in-progress container to be rescheduled.');
    } catch (PlatformUnavailableException $exception) {
        $this->postPlatform->update(['error_context' => [...$exception->context, 'error' => 'kept']]);
    }

    sm04Fake($this, Http::response('', 500), containers: [
        Http::response(['error' => ['message' => 'Bad photo', 'code' => 100, 'error_subcode' => 2207005]], 400),
    ], status: [
        'story-container-1' => Http::response(['status_code' => 'ERROR'], 200),
    ]);

    expect(fn () => (new InstagramPublisher)->publish($this->postPlatform->fresh()))->toThrow(InstagramPublishException::class);

    expect($this->postPlatform->fresh()->error_context)->toBe(['error' => 'kept'])
        ->and(sm04HostedVideos())->toBe([]);
});

test('instagram story motion: a rate limit on the video is not retried as a photo story and the video is deleted', function () {
    sm04Prepare($this);
    sm04Fake($this, sm04RenderedVideo($this), containers: [
        Http::response(['error' => ['message' => 'Daily limit', 'code' => 9, 'error_subcode' => 2207042]], 400),
    ]);

    expect(fn () => $this->publisher->publish($this->postPlatform->fresh()))->toThrow(InstagramPublishException::class);

    expect($this->sm04Calls)->toBe(['download', 'render', 'container'])
        ->and(sm04HostedVideos())->toBe([]);
});

test('instagram story motion: a transient failure before a container exists deletes the video', function () {
    sm04Prepare($this);
    sm04Fake($this, sm04RenderedVideo($this), containers: [Http::response('', 503)]);

    expect(fn () => $this->publisher->publish($this->postPlatform->fresh()))->toThrow(PlatformUnavailableException::class);

    expect(sm04HostedVideos())->toBe([]);
});

/**
 * Runs one publish attempt the way PublishToSocialPlatform does: on a pending failure its exception context is merged into the
 * stored one (the publisher has already stored the attempt marker itself). Returns the thrown exception or the result.
 */
function sm04Attempt(object $test, ?InstagramPublisher $publisher = null): mixed
{
    try {
        return ($publisher ?? new InstagramPublisher)->publish($test->postPlatform->fresh());
    } catch (PlatformUnavailableException $exception) {
        $test->postPlatform->update(['error_context' => [...($test->postPlatform->fresh()->error_context ?? []), ...$exception->context]]);

        return $exception;
    }
}

/** Fails the test when the render service, the music generator or the media host is asked again. */
function sm04ExpectNoRender(object $test, array $containers = [], array $status = []): void
{
    sm04Fake($test, Http::response('', 500), containers: $containers, status: $status);
}

test('instagram story motion: a transient failure before a container renders only once, the retry posts the photo story', function () {
    sm04Prepare($this);
    $music = Mockery::mock(StoryMusicGenerator::class);
    $music->shouldReceive('generate')->once()->andReturnNull();
    app()->instance(StoryMusicGenerator::class, $music);
    sm04Fake($this, sm04RenderedVideo($this), containers: [Http::response('', 503)]);

    $first = sm04Attempt($this);

    // no container, so no workflow: the attempt marker is all that is left, and the video is gone
    expect($first)->toBeInstanceOf(PlatformUnavailableException::class)
        ->and($first->context)->toBe([])
        ->and($this->postPlatform->fresh()->error_context)->toBe(['story_video_attempted' => true])
        ->and(sm04HostedVideos())->toBe([])
        ->and($this->sm04Calls)->toBe(['download', 'render', 'container']);

    // the next attempts (the job retries up to 90 times) never render or generate music again
    sm04ExpectNoRender($this, containers: [Http::response('', 503)]);

    expect(sm04Attempt($this))->toBeInstanceOf(PlatformUnavailableException::class)
        ->and($this->sm04Calls)->toBe(['download', 'container']);

    sm04ExpectNoRender($this);
    $result = sm04Attempt($this);

    expect($result['id'])->toBe('story-media-1')
        ->and($this->sm04Calls)->toBe(['download', 'container', 'status', 'media_publish'])
        ->and(sm04LastContainer($this))->toHaveKey('image_url')->not->toHaveKey('video_url')
        ->and(sm04HostedVideos())->toBe([]);
});

test('instagram story motion: through the real job a transient failure renders once and the later attempts post the photo story', function () {
    Bus::fake([PublishToSocialPlatform::class]);
    Event::fake();
    Mail::fake();
    sm04Prepare($this);
    $music = Mockery::mock(StoryMusicGenerator::class);
    $music->shouldReceive('generate')->once()->andReturnNull();
    app()->instance(StoryMusicGenerator::class, $music);

    sm04Fake($this, sm04RenderedVideo($this), containers: [Http::response('', 503)]);
    (new PublishToSocialPlatform($this->postPlatform->fresh()))->handle();

    $context = $this->postPlatform->fresh()->error_context;

    expect($this->postPlatform->fresh()->status)->toBe(PlatformStatus::Retrying)
        ->and($context['story_video_attempted'])->toBeTrue()
        ->and($this->sm04Calls)->toBe(['download', 'render', 'container']);

    sm04ExpectNoRender($this, containers: [Http::response('', 503)]);
    (new PublishToSocialPlatform($this->postPlatform->fresh()))->handle();

    expect($this->postPlatform->fresh()->error_context['story_video_attempted'])->toBeTrue()
        ->and($this->sm04Calls)->toBe(['download', 'container']);

    sm04ExpectNoRender($this);
    (new PublishToSocialPlatform($this->postPlatform->fresh()))->handle();

    expect($this->postPlatform->fresh()->status)->toBe(PlatformStatus::Published)
        ->and($this->sm04Calls)->toBe(['download', 'container', 'status', 'media_publish'])
        ->and(sm04LastContainer($this))->toHaveKey('image_url')->not->toHaveKey('video_url')
        ->and(sm04HostedVideos())->toBe([]);
});

test('instagram story motion: the attempt marker is stored before the render starts', function () {
    sm04Prepare($this);
    $seen = null;
    sm04Fake($this, function () use (&$seen) {
        $seen = $this->postPlatform->fresh()->error_context;

        return sm04RenderedVideo($this);
    });

    $this->publisher->publish($this->postPlatform->fresh());

    expect($seen)->toBe(['story_video_attempted' => true]);
});

test('instagram story motion: a render that failed is not tried again when the photo story then fails transiently', function () {
    sm04Prepare($this);
    sm04Fake($this, Http::response('', 503), containers: [Http::response('', 503)]);

    expect(sm04Attempt($this))->toBeInstanceOf(PlatformUnavailableException::class)
        ->and($this->sm04Calls)->toBe(['download', 'render', 'download', 'container']);

    sm04ExpectNoRender($this);
    $result = sm04Attempt($this);

    expect($result['id'])->toBe('story-media-1')
        ->and($this->sm04Calls)->not->toContain('render')
        ->and(sm04LastContainer($this))->toHaveKey('image_url');
});

test('instagram story motion: a refused video followed by a transient photo failure does not render again on the retry', function () {
    sm04Prepare($this);
    sm04Fake($this, sm04RenderedVideo($this), containers: [
        Http::response(['error' => ['message' => 'Unsupported video', 'code' => 9004, 'error_subcode' => 2207026]], 400),
        Http::response('', 503),
    ]);

    expect(sm04Attempt($this))->toBeInstanceOf(PlatformUnavailableException::class)
        ->and($this->sm04Calls)->toBe(['download', 'render', 'container', 'download', 'container'])
        ->and(sm04HostedVideos())->toBe([]);

    sm04ExpectNoRender($this);
    $result = sm04Attempt($this);

    expect($result['id'])->toBe('story-media-1')
        ->and($this->sm04Calls)->toBe(['download', 'container', 'status', 'media_publish'])
        ->and(sm04LastContainer($this))->toHaveKey('image_url')->not->toHaveKey('video_url');
});

test('instagram story motion: a video that fails on a resume, followed by a transient photo failure, does not render again', function () {
    sm04Prepare($this);
    sm04Fake($this, sm04RenderedVideo($this), status: ['story-container-1' => Http::response(['status_code' => 'IN_PROGRESS'], 200)]);

    expect(sm04Attempt($this))->toBeInstanceOf(PlatformUnavailableException::class);

    // the resume finds the video container in ERROR, the photo container then fails transiently
    sm04ExpectNoRender($this, containers: [Http::response('', 503)], status: ['story-container-1' => Http::response(['status_code' => 'ERROR'], 200)]);

    expect(sm04Attempt($this))->toBeInstanceOf(PlatformUnavailableException::class)
        ->and($this->sm04Calls)->toBe(['status', 'download', 'container'])
        ->and($this->postPlatform->fresh()->error_context)->not->toHaveKey('instagram_workflow')
        ->and($this->postPlatform->fresh()->error_context['story_video_attempted'])->toBeTrue();

    sm04ExpectNoRender($this);
    $result = sm04Attempt($this);

    expect($result['id'])->toBe('story-media-1')
        ->and($this->sm04Calls)->toBe(['download', 'container', 'status', 'media_publish'])
        ->and(sm04LastContainer($this))->toHaveKey('image_url')->not->toHaveKey('video_url');
});

test('instagram story motion: an expired token before the first container does not render a second time', function () {
    sm04Prepare($this);
    sm04Fake($this, sm04RenderedVideo($this), containers: [Http::response(['error' => ['message' => 'Token expired', 'code' => 190]], 401)]);

    expect(fn () => $this->publisher->publish($this->postPlatform->fresh()))->toThrow(TokenExpiredException::class);

    expect($this->sm04Calls)->toBe(['download', 'render', 'container'])
        ->and(sm04HostedVideos())->toBe([]);

    // the job refreshes the token and publishes again with the stored context
    sm04ExpectNoRender($this);
    $result = (new InstagramPublisher)->publish($this->postPlatform->fresh());

    expect($result['id'])->toBe('story-media-1')
        ->and($this->sm04Calls)->toBe(['download', 'container', 'status', 'media_publish'])
        ->and(sm04LastContainer($this))->toHaveKey('image_url');
});

test('instagram story motion: a lost answer to media_publish keeps the fact that it was sent', function () {
    sm04Prepare($this);
    sm04Fake($this, sm04RenderedVideo($this), publish: Http::failedConnection());

    $exception = sm04Attempt($this);

    expect($exception)->toBeInstanceOf(PlatformUnavailableException::class)
        ->and($exception->context['instagram_workflow']['media_publish_started'])->toBeTrue()
        ->and($exception->context['instagram_workflow']['container_id'])->toBe('story-container-1')
        ->and(sm04HostedVideos())->toHaveCount(1);
});

test('instagram story motion: nothing is stored when the story is not rendered', function (array $settings, bool $masterSwitch) {
    sm04Prepare($this, $settings, $masterSwitch);
    sm04Fake($this, Http::response('', 500));

    $this->publisher->publish($this->postPlatform->fresh());

    expect(data_get($this->postPlatform->fresh()->error_context, 'story_video_attempted'))->toBeNull();
})->with([
    'instagram switch off' => [['enabled' => true, 'instagram' => false], true],
    'workspace Story videos off' => [['enabled' => false, 'instagram' => true], true],
    'installation switch off' => [['enabled' => true, 'instagram' => true], false],
]);

test('instagram story motion: a refusal at media_publish is not turned into a second, photo story', function () {
    sm04Prepare($this);
    sm04Fake($this, sm04RenderedVideo($this), publish: Http::response(['error' => ['message' => 'Failed to create media', 'code' => 100, 'error_subcode' => 2207032]], 400));

    expect(fn () => $this->publisher->publish($this->postPlatform->fresh()))->toThrow(InstagramPublishException::class);

    expect($this->sm04Calls)->toBe(['download', 'render', 'container', 'status', 'media_publish'])
        ->and($this->sm04Containers)->toHaveCount(1)
        ->and(sm04HostedVideos())->toBe([]);
});

test('instagram story motion: a resume after media_publish was sent never falls back to a photo story', function () {
    sm04Prepare($this);
    $path = StoryVideoHost::DIRECTORY.'/'.Str::uuid().'.mp4';
    Storage::put($path, 'video');
    $this->postPlatform->update(['error_context' => ['instagram_workflow' => [
        'stage' => 'final_container',
        'container_id' => 'story-container-1',
        'story_video_path' => $path,
        'media_publish_started' => true,
    ]]]);
    sm04Fake($this, Http::response('', 500), status: ['story-container-1' => Http::response(['status_code' => 'ERROR'], 200)]);

    expect(fn () => (new InstagramPublisher)->publish($this->postPlatform->fresh()))->toThrow(InstagramPublishException::class);

    expect($this->sm04Calls)->toBe(['status'])
        ->and(sm04HostedVideos())->toBe([]);
});

test('instagram story motion: a media_publish that is still pending keeps the fact that it was sent in the checkpoint', function () {
    sm04Prepare($this);
    sm04Fake($this, sm04RenderedVideo($this), publish: Http::response('', 503));

    try {
        $this->publisher->publish($this->postPlatform->fresh());
        test()->fail('Expected the pending publish.');
    } catch (PlatformUnavailableException $exception) {
        $workflow = $exception->context['instagram_workflow'];

        expect($workflow['container_id'])->toBe('story-container-1')
            ->and($workflow['media_publish_started'])->toBeTrue()
            ->and($workflow['story_video_path'])->toStartWith(StoryVideoHost::DIRECTORY.'/');
    }

    expect(sm04HostedVideos())->toHaveCount(1);
});

test('instagram story motion: a video story is posted as before and never touches the builder', function () {
    sm04Prepare($this);
    $this->post->update(['media' => [[
        'id' => 'test-media-video',
        'path' => 'media/2026-01/story.mp4',
        'url' => 'https://example.com/media/2026-01/story.mp4',
        'mime_type' => 'video/mp4',
        'original_filename' => 'story.mp4',
    ]]]);
    sm04Fake($this, Http::response('', 500));

    $this->publisher->publish($this->postPlatform->fresh());

    expect(sm04LastContainer($this)['video_url'])->toBe('https://example.com/media/2026-01/story.mp4')
        ->and($this->sm04Calls)->toBe(['container', 'status', 'media_publish']);
});

test('instagram story motion: the Instagram switch changes nothing for a Facebook story', function (bool $instagram) {
    sm04Prepare($this, ['enabled' => true, 'instagram' => $instagram]);

    $facebook = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'page_123',
        'token_expires_at' => null,
    ]);
    $facebookPlatform = PostPlatform::factory()->facebook()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $facebook->id,
        'platform' => Platform::Facebook,
        'content_type' => ContentType::FacebookStory,
    ]);

    $calls = [];
    $upload = null;
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([
        '*/page_123/video_stories' => Http::sequence()
            ->push(['video_id' => 'v1', 'upload_url' => 'https://rupload.facebook.com/video-upload/v25.0/v1'], 200)
            ->push(['success' => true, 'post_id' => 'fb_story_1'], 200),
        '*example.com/media/*' => function () use (&$calls) {
            $calls[] = 'download';

            return Http::response(sm04JpegBytes(), 200);
        },
        '*rupload.facebook.com/*' => function (Request $request) use (&$upload) {
            $upload = strlen($request->body());

            return Http::response(['success' => true], 200);
        },
        'story-render:3000/*' => function () use (&$calls) {
            $calls[] = 'render';

            return sm04RenderedVideo($this);
        },
    ]);

    $result = (new FacebookPublisher)->publish($facebookPlatform->fresh());

    expect($result['id'])->toBe('fb_story_1')
        ->and($calls)->toBe(['download', 'render'])
        ->and($upload)->toBeGreaterThan(1000)
        ->and(sm04HostedVideos())->toBe([]);
})->with([[true], [false]]);

test('instagram story motion: a Facebook photo story whose photo cannot be downloaded fails as before', function () {
    sm04Prepare($this);
    $facebook = SocialAccount::factory()->facebook()->create([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'page_123',
        'token_expires_at' => null,
    ]);
    $facebookPlatform = PostPlatform::factory()->facebook()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $facebook->id,
        'platform' => Platform::Facebook,
        'content_type' => ContentType::FacebookStory,
    ]);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake([
        '*/page_123/video_stories' => Http::response(['video_id' => 'v1', 'upload_url' => 'https://rupload.facebook.com/video-upload/v25.0/v1'], 200),
        '*example.com/media/*' => Http::response('', 404),
    ]);
    $before = glob(sys_get_temp_dir().'/fb_story_img_*') ?: [];

    try {
        (new FacebookPublisher)->publish($facebookPlatform->fresh());
        test()->fail('Expected the download to fail.');
    } catch (FacebookPublishException $e) {
        expect($e->getMessage())->toContain('Could not download media for Facebook story.')
            ->and($e->platformErrorCode)->toBe('404')
            ->and($e->category)->toBe(ErrorCategory::ServerError);
    }

    expect(glob(sys_get_temp_dir().'/fb_story_img_*') ?: [])->toBe($before);
});

test('story video host: prune deletes only old managed videos', function () {
    Storage::fake();
    $host = app(StoryVideoHost::class);

    $old = StoryVideoHost::DIRECTORY.'/'.Str::uuid().'.mp4';
    $fresh = StoryVideoHost::DIRECTORY.'/'.Str::uuid().'.mp4';
    $stranger = StoryVideoHost::DIRECTORY.'/notes.txt';

    foreach ([$old, $fresh, $stranger] as $path) {
        Storage::put($path, 'x');
    }

    // The fake disk is a real directory, so the age is the file's modification time.
    touch(Storage::path($old), time() - 30 * 3600);
    touch(Storage::path($stranger), time() - 30 * 3600);

    expect($host->prune())->toBe(1);

    Storage::assertMissing($old);
    Storage::assertExists($fresh);
    Storage::assertExists($stranger);
});

test('story video host: delete refuses paths outside its directory', function (mixed $path) {
    Storage::fake();
    Storage::put('media/2026-01/photo.jpg', 'x');
    Storage::put('social-crops/00000000-0000-4000-8000-000000000000.mp4', 'x');

    app(StoryVideoHost::class)->delete($path);

    Storage::assertExists('media/2026-01/photo.jpg');
    Storage::assertExists('social-crops/00000000-0000-4000-8000-000000000000.mp4');
})->with([
    'a media file' => ['media/2026-01/photo.jpg'],
    'a traversal' => [StoryVideoHost::DIRECTORY.'/../media/2026-01/photo.jpg'],
    'another directory with a uuid' => ['social-crops/00000000-0000-4000-8000-000000000000.mp4'],
    'null' => [null],
    'an array' => [['media/2026-01/photo.jpg']],
]);

test('story video host: the prune command runs and reports', function () {
    Storage::fake();
    $path = StoryVideoHost::DIRECTORY.'/'.Str::uuid().'.mp4';
    Storage::put($path, 'x');
    touch(Storage::path($path), time() - 25 * 3600);

    $this->artisan('app:prune-story-videos')->expectsOutput('Deleted 1 hosted story video(s).')->assertSuccessful();

    expect(sm04HostedVideos())->toBe([]);
});

test('story video host: the prune command is scheduled hourly', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'app:prune-story-videos'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('0 * * * *');
});
