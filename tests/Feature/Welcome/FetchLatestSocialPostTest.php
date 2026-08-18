<?php

declare(strict_types=1);

use App\Actions\Welcome\FetchLatestSocialPost;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use Illuminate\Support\Facades\Http;

test('it skips networks that do not expose impression analytics', function () {
    Http::preventStrayRequests();

    $account = SocialAccount::factory()->discord()->create();

    expect(app(FetchLatestSocialPost::class)->handle($account))->toBeNull();

    Http::assertNothingSent();
});

test('it skips disconnected accounts', function () {
    Http::preventStrayRequests();

    $account = SocialAccount::factory()->instagram()->disconnected()->create();

    expect(app(FetchLatestSocialPost::class)->handle($account))->toBeNull();

    Http::assertNothingSent();
});

test('it maps the latest instagram post and its views', function () {
    $account = SocialAccount::factory()->instagram()->create([
        'platform_user_id' => '178414000',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        config('trypost.platforms.instagram.graph_api').'/178414000/media*' => Http::response([
            'data' => [[
                'id' => '1789',
                'caption' => 'Hello from IG',
                'media_type' => 'IMAGE',
                'media_url' => 'https://cdn.example/photo.jpg',
                'permalink' => 'https://www.instagram.com/p/abc',
                'timestamp' => '2026-08-01T12:00:00+0000',
            ]],
        ]),
        config('trypost.platforms.instagram.graph_api').'/1789/insights*' => Http::response([
            'data' => [['name' => 'views', 'values' => [['value' => 1]]]],
        ]),
    ]);

    expect(app(FetchLatestSocialPost::class)->handle($account))->toBe([
        'id' => '1789',
        'caption' => 'Hello from IG',
        'media_url' => 'https://cdn.example/photo.jpg',
        'permalink' => 'https://www.instagram.com/p/abc',
        'published_at' => '2026-08-01T12:00:00+0000',
        'impressions' => 1,
        'reach' => reachPitch('Instagram', 'instagram'),
    ]);
});

test('it uses the instagram video thumbnail', function () {
    $account = SocialAccount::factory()->instagram()->create([
        'platform_user_id' => '178414000',
    ]);

    Http::fake([
        config('trypost.platforms.instagram.graph_api').'/178414000/media*' => Http::response([
            'data' => [[
                'id' => '1790',
                'caption' => 'Reel',
                'media_type' => 'VIDEO',
                'media_url' => 'https://cdn.example/video.mp4',
                'thumbnail_url' => 'https://cdn.example/thumb.jpg',
                'permalink' => 'https://www.instagram.com/reel/xyz',
                'timestamp' => '2026-08-02T12:00:00+0000',
            ]],
        ]),
        config('trypost.platforms.instagram.graph_api').'/1790/insights*' => Http::response([
            'data' => [['name' => 'views', 'values' => [['value' => 12]]]],
        ]),
    ]);

    expect(app(FetchLatestSocialPost::class)->handle($account))->toMatchArray([
        'id' => '1790',
        'media_url' => 'https://cdn.example/thumb.jpg',
        'impressions' => 12,
    ]);
});

test('it still returns the instagram post when insights are unavailable', function () {
    $account = SocialAccount::factory()->instagram()->create([
        'platform_user_id' => '178414000',
    ]);

    Http::fake([
        config('trypost.platforms.instagram.graph_api').'/178414000/media*' => Http::response([
            'data' => [[
                'id' => '1789',
                'caption' => 'Hello from IG',
                'media_type' => 'IMAGE',
                'media_url' => 'https://cdn.example/photo.jpg',
                'permalink' => 'https://www.instagram.com/p/abc',
                'timestamp' => '2026-08-01T12:00:00+0000',
            ]],
        ]),
        config('trypost.platforms.instagram.graph_api').'/1789/insights*' => Http::response(['error' => 'nope'], 400),
    ]);

    expect(app(FetchLatestSocialPost::class)->handle($account))->toMatchArray([
        'id' => '1789',
        'impressions' => null,
        'reach' => reachPitch('Instagram', 'instagram'),
    ]);
});

test('it maps the latest x post including media and impressions', function () {
    $account = SocialAccount::factory()->x()->create([
        'platform_user_id' => '2244994945',
    ]);

    Http::preventStrayRequests();
    Http::fake([
        config('trypost.platforms.x.api').'/users/2244994945/tweets*' => Http::response([
            'data' => [[
                'id' => '123',
                'text' => 'Hello from X',
                'created_at' => '2026-08-01T12:00:00.000Z',
                'attachments' => ['media_keys' => ['3_456']],
                'public_metrics' => ['impression_count' => 8],
            ]],
            'includes' => [
                'media' => [[
                    'media_key' => '3_456',
                    'url' => 'https://cdn.example/tweet.jpg',
                ]],
            ],
        ]),
    ]);

    expect(app(FetchLatestSocialPost::class)->handle($account))->toBe([
        'id' => '123',
        'caption' => 'Hello from X',
        'media_url' => 'https://cdn.example/tweet.jpg',
        'permalink' => 'https://x.com/i/web/status/123',
        'published_at' => '2026-08-01T12:00:00.000Z',
        'impressions' => 8,
        'reach' => reachPitch('X', 'x'),
    ]);
});

test('it pitches instagram and youtube when tiktok is connected', function () {
    $account = SocialAccount::factory()->tiktok()->create([
        'platform_user_id' => 'tt-user',
    ]);

    Http::fake([
        config('trypost.platforms.tiktok.api').'/video/list/*' => Http::response([
            'data' => [
                'videos' => [[
                    'id' => 'vid-1',
                    'title' => 'A clip',
                    'cover_image_url' => 'https://cdn.example/cover.jpg',
                    'share_url' => 'https://www.tiktok.com/@x/video/1',
                    'create_time' => now()->timestamp,
                ]],
            ],
        ]),
        config('trypost.platforms.tiktok.api').'/video/query/*' => Http::response([
            'data' => [
                'videos' => [[
                    'id' => 'vid-1',
                    'view_count' => 40,
                ]],
            ],
        ]),
    ]);

    expect(app(FetchLatestSocialPost::class)->handle($account))->toMatchArray([
        'id' => 'vid-1',
        'impressions' => 40,
        'reach' => [
            'network' => 'TikTok',
            'network_value' => 'tiktok',
            'others' => [
                ['value' => 'youtube', 'label' => 'YouTube', 'views' => 1000],
                ['value' => 'instagram', 'label' => 'Instagram', 'views' => 1000],
            ],
            'each_views' => 1000,
            'extra_views' => 2000,
        ],
    ]);
});

test('it still returns the tiktok post when video query fails', function () {
    $account = SocialAccount::factory()->tiktok()->create();

    Http::fake([
        config('trypost.platforms.tiktok.api').'/video/list/*' => Http::response([
            'data' => [
                'videos' => [[
                    'id' => 'vid-1',
                    'title' => 'A clip',
                    'cover_image_url' => 'https://cdn.example/cover.jpg',
                    'share_url' => 'https://www.tiktok.com/@x/video/1',
                    'create_time' => now()->timestamp,
                ]],
            ],
        ]),
        config('trypost.platforms.tiktok.api').'/video/query/*' => Http::response(['error' => 'nope'], 400),
    ]);

    expect(app(FetchLatestSocialPost::class)->handle($account))->toMatchArray([
        'id' => 'vid-1',
        'impressions' => null,
    ]);
});

test('it maps the latest facebook post and its impressions', function () {
    $account = SocialAccount::factory()->facebook()->create([
        'platform_user_id' => 'page-1',
    ]);

    Http::fake([
        config('trypost.platforms.facebook.graph_api').'/page-1/posts*' => Http::response([
            'data' => [[
                'id' => 'post-1',
                'message' => 'Hello from FB',
                'full_picture' => 'https://cdn.example/fb.jpg',
                'permalink_url' => 'https://www.facebook.com/post-1',
                'created_time' => '2026-08-01T12:00:00+0000',
            ]],
        ]),
        config('trypost.platforms.facebook.graph_api').'/post-1/insights*' => Http::response([
            'data' => [['name' => 'post_impressions', 'values' => [['value' => 22]]]],
        ]),
    ]);

    expect(app(FetchLatestSocialPost::class)->handle($account))->toMatchArray([
        'id' => 'post-1',
        'caption' => 'Hello from FB',
        'media_url' => 'https://cdn.example/fb.jpg',
        'permalink' => 'https://www.facebook.com/post-1',
        'impressions' => 22,
        'reach' => reachPitch('Facebook', 'facebook'),
    ]);
});

test('it maps the latest threads post and its views', function () {
    $account = SocialAccount::factory()->threads()->create([
        'platform_user_id' => 'th-1',
    ]);

    Http::fake([
        config('trypost.platforms.threads.graph_api').'/th-1/threads*' => Http::response([
            'data' => [[
                'id' => 'thread-1',
                'text' => 'Hello from Threads',
                'permalink' => 'https://www.threads.net/t/1',
                'timestamp' => '2026-08-01T12:00:00+0000',
                'media_url' => 'https://cdn.example/th.jpg',
            ]],
        ]),
        config('trypost.platforms.threads.graph_api').'/thread-1/insights*' => Http::response([
            'data' => [['name' => 'views', 'values' => [['value' => 9]]]],
        ]),
    ]);

    expect(app(FetchLatestSocialPost::class)->handle($account))->toMatchArray([
        'id' => 'thread-1',
        'caption' => 'Hello from Threads',
        'media_url' => 'https://cdn.example/th.jpg',
        'permalink' => 'https://www.threads.net/t/1',
        'impressions' => 9,
        'reach' => reachPitch('Threads', 'threads'),
    ]);
});

test('it maps the latest youtube video and its views', function () {
    $account = SocialAccount::factory()->youtube()->create();

    Http::fake([
        config('trypost.platforms.youtube.data_api').'/search*' => Http::response([
            'items' => [[
                'id' => ['videoId' => 'yt-1'],
                'snippet' => [
                    'title' => 'A video',
                    'publishedAt' => '2026-08-01T12:00:00Z',
                    'thumbnails' => ['high' => ['url' => 'https://cdn.example/yt.jpg']],
                ],
            ]],
        ]),
        config('trypost.platforms.youtube.data_api').'/videos*' => Http::response([
            'items' => [['statistics' => ['viewCount' => '77']]],
        ]),
    ]);

    expect(app(FetchLatestSocialPost::class)->handle($account))->toMatchArray([
        'id' => 'yt-1',
        'caption' => 'A video',
        'media_url' => 'https://cdn.example/yt.jpg',
        'permalink' => 'https://www.youtube.com/watch?v=yt-1',
        'impressions' => 77,
        'reach' => [
            'network' => 'YouTube',
            'network_value' => 'youtube',
            'others' => [
                ['value' => 'tiktok', 'label' => 'TikTok', 'views' => 1000],
                ['value' => 'instagram', 'label' => 'Instagram', 'views' => 1000],
            ],
            'each_views' => 1000,
            'extra_views' => 2000,
        ],
    ]);
});

test('it maps the latest pinterest pin without using the destination link as permalink', function () {
    $account = SocialAccount::factory()->pinterest()->create();

    Http::fake([
        config('trypost.platforms.pinterest.api').'/pins/pin-1/analytics*' => Http::response([
            'all' => ['summary_metrics' => ['IMPRESSION' => 15]],
        ]),
        config('trypost.platforms.pinterest.api').'/pins*' => Http::response([
            'items' => [[
                'id' => 'pin-1',
                'description' => 'A pin',
                'link' => 'https://shop.example/product',
                'created_at' => '2026-08-01T12:00:00',
                'media' => ['images' => ['400x300' => ['url' => 'https://cdn.example/pin.jpg']]],
            ]],
        ]),
    ]);

    expect(app(FetchLatestSocialPost::class)->handle($account))->toMatchArray([
        'id' => 'pin-1',
        'caption' => 'A pin',
        'media_url' => 'https://cdn.example/pin.jpg',
        'permalink' => null,
        'impressions' => 15,
        'reach' => reachPitch('Pinterest', 'pinterest'),
    ]);
});

test('it maps the latest linkedin page post', function () {
    $account = SocialAccount::factory()->linkedinPage()->create([
        'platform_user_id' => '12345',
    ]);

    Http::fake([
        config('trypost.platforms.linkedin-page.api').'/rest/posts*' => Http::response([
            'elements' => [[
                'id' => 'urn:li:share:99',
                'commentary' => 'Hello from the page',
                'createdAt' => 1754049600000,
            ]],
        ]),
    ]);

    expect(app(FetchLatestSocialPost::class)->handle($account))->toMatchArray([
        'id' => 'urn:li:share:99',
        'caption' => 'Hello from the page',
        'permalink' => 'https://www.linkedin.com/feed/update/urn:li:share:99',
        'impressions' => null,
        'reach' => reachPitch('LinkedIn Page', 'linkedin'),
    ]);
});

test('it does not refresh tokens while fetching the latest post', function () {
    $account = SocialAccount::factory()->instagram()->create([
        'platform_user_id' => '178414000',
        'token_expires_at' => now()->addMinutes(5),
    ]);

    expect($account->needsProactiveTokenRefresh())->toBeTrue();

    Http::preventStrayRequests();
    Http::fake([
        config('trypost.platforms.instagram.graph_api').'/178414000/media*' => Http::response([
            'data' => [[
                'id' => '1789',
                'caption' => 'Hello from IG',
                'media_type' => 'IMAGE',
                'media_url' => 'https://cdn.example/photo.jpg',
                'permalink' => 'https://www.instagram.com/p/abc',
                'timestamp' => '2026-08-01T12:00:00+0000',
            ]],
        ]),
        config('trypost.platforms.instagram.graph_api').'/1789/insights*' => Http::response([
            'data' => [['name' => 'views', 'values' => [['value' => 1]]]],
        ]),
    ]);

    expect(app(FetchLatestSocialPost::class)->handle($account))->not->toBeNull();

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'refresh_access_token'));
});

test('it omits disabled networks from the reach pitch', function () {
    config(['trypost.platforms.tiktok.enabled' => false]);

    $account = SocialAccount::factory()->instagram()->create([
        'platform_user_id' => '178414000',
    ]);

    Http::fake([
        config('trypost.platforms.instagram.graph_api').'/178414000/media*' => Http::response([
            'data' => [[
                'id' => '1789',
                'caption' => 'Hello from IG',
                'media_type' => 'IMAGE',
                'media_url' => 'https://cdn.example/photo.jpg',
                'permalink' => 'https://www.instagram.com/p/abc',
                'timestamp' => '2026-08-01T12:00:00+0000',
            ]],
        ]),
        config('trypost.platforms.instagram.graph_api').'/1789/insights*' => Http::response([
            'data' => [['name' => 'views', 'values' => [['value' => 1]]]],
        ]),
    ]);

    expect(app(FetchLatestSocialPost::class)->handle($account))->toMatchArray([
        'reach' => [
            'network' => 'Instagram',
            'network_value' => 'instagram',
            'others' => [
                ['value' => 'youtube', 'label' => 'YouTube', 'views' => 1000],
                ['value' => 'facebook', 'label' => 'Facebook', 'views' => 1000],
            ],
            'each_views' => 1000,
            'extra_views' => 2000,
        ],
    ]);
});

test('it pitches a single remaining network when only one other is enabled', function () {
    config([
        'trypost.platforms.tiktok.enabled' => false,
        'trypost.platforms.youtube.enabled' => false,
        'trypost.platforms.facebook.enabled' => false,
    ]);

    $account = SocialAccount::factory()->instagram()->create([
        'platform_user_id' => '178414000',
    ]);

    Http::fake([
        config('trypost.platforms.instagram.graph_api').'/178414000/media*' => Http::response([
            'data' => [[
                'id' => '1789',
                'caption' => 'Hello from IG',
                'media_type' => 'IMAGE',
                'media_url' => 'https://cdn.example/photo.jpg',
                'permalink' => 'https://www.instagram.com/p/abc',
                'timestamp' => '2026-08-01T12:00:00+0000',
            ]],
        ]),
        config('trypost.platforms.instagram.graph_api').'/1789/insights*' => Http::response([
            'data' => [['name' => 'views', 'values' => [['value' => 1]]]],
        ]),
    ]);

    expect(app(FetchLatestSocialPost::class)->handle($account))->toMatchArray([
        'reach' => [
            'network' => 'Instagram',
            'network_value' => 'instagram',
            'others' => [
                ['value' => 'x', 'label' => 'X', 'views' => 1000],
            ],
            'each_views' => 1000,
            'extra_views' => 1000,
        ],
    ]);
});

test('it pitches no other networks when every alternative is disabled', function () {
    config([
        'trypost.platforms.tiktok.enabled' => false,
        'trypost.platforms.youtube.enabled' => false,
        'trypost.platforms.facebook.enabled' => false,
        'trypost.platforms.x.enabled' => false,
    ]);

    $account = SocialAccount::factory()->instagram()->create([
        'platform_user_id' => '178414000',
    ]);

    Http::fake([
        config('trypost.platforms.instagram.graph_api').'/178414000/media*' => Http::response([
            'data' => [[
                'id' => '1789',
                'caption' => 'Hello from IG',
                'media_type' => 'IMAGE',
                'media_url' => 'https://cdn.example/photo.jpg',
                'permalink' => 'https://www.instagram.com/p/abc',
                'timestamp' => '2026-08-01T12:00:00+0000',
            ]],
        ]),
        config('trypost.platforms.instagram.graph_api').'/1789/insights*' => Http::response([
            'data' => [['name' => 'views', 'values' => [['value' => 1]]]],
        ]),
    ]);

    expect(app(FetchLatestSocialPost::class)->handle($account))->toMatchArray([
        'reach' => [
            'network' => 'Instagram',
            'network_value' => 'instagram',
            'others' => [],
            'each_views' => 1000,
            'extra_views' => 0,
        ],
    ]);
});

test('it never pitches fewer views than the real post already got', function () {
    $account = SocialAccount::factory()->instagram()->create([
        'platform_user_id' => '178414000',
    ]);

    Http::fake([
        config('trypost.platforms.instagram.graph_api').'/178414000/media*' => Http::response([
            'data' => [[
                'id' => '1789',
                'caption' => 'Hello from IG',
                'media_type' => 'IMAGE',
                'media_url' => 'https://cdn.example/photo.jpg',
                'permalink' => 'https://www.instagram.com/p/abc',
                'timestamp' => '2026-08-01T12:00:00+0000',
            ]],
        ]),
        config('trypost.platforms.instagram.graph_api').'/1789/insights*' => Http::response([
            'data' => [['name' => 'views', 'values' => [['value' => 12000]]]],
        ]),
    ]);

    expect(app(FetchLatestSocialPost::class)->handle($account))->toMatchArray([
        'impressions' => 12000,
        'reach' => reachPitch('Instagram', 'instagram', 12000),
    ]);
});

test('it returns null when the platform request fails', function () {
    $account = SocialAccount::factory()->instagram()->create([
        'platform_user_id' => '178414000',
    ]);

    Http::fake([
        config('trypost.platforms.instagram.graph_api').'/178414000/media*' => Http::response(['error' => 'nope'], 500),
    ]);

    expect(app(FetchLatestSocialPost::class)->handle($account))->toBeNull();
});

test('it returns null when the account has no posts', function () {
    $account = SocialAccount::factory()->instagram()->create([
        'platform_user_id' => '178414000',
    ]);

    Http::fake([
        config('trypost.platforms.instagram.graph_api').'/178414000/media*' => Http::response([
            'data' => [],
        ]),
    ]);

    expect(app(FetchLatestSocialPost::class)->handle($account))->toBeNull();
});

test('it skips linkedin personal accounts', function () {
    Http::preventStrayRequests();

    $account = SocialAccount::factory()->linkedin()->create();

    expect($account->platform)->toBe(Platform::LinkedIn)
        ->and(app(FetchLatestSocialPost::class)->handle($account))->toBeNull();

    Http::assertNothingSent();
});

/**
 * @return array{network: string, network_value: string, others: list<array{value: string, label: string, views: int}>, each_views: int, extra_views: int}
 */
function reachPitch(string $network, string $networkValue, int $eachViews = 1000): array
{
    return [
        'network' => $network,
        'network_value' => $networkValue,
        'others' => [
            ['value' => 'tiktok', 'label' => 'TikTok', 'views' => $eachViews],
            ['value' => 'youtube', 'label' => 'YouTube', 'views' => $eachViews],
        ],
        'each_views' => $eachViews,
        'extra_views' => $eachViews * 2,
    ];
}
