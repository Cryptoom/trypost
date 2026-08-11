<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Social\GoogleBusinessAnalytics;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->socialAccount = SocialAccount::factory()->googleBusiness()->create([
        'workspace_id' => $this->workspace->id,
        'token_expires_at' => now()->addHour(),
    ]);
    $this->analytics = new GoogleBusinessAnalytics;
});

test('fetches the five performance metrics for the account location', function () {
    Http::fake([
        config('trypost.platforms.google_business.performance_api').'/*' => Http::response([
            'multiDailyMetricTimeSeries' => [
                [
                    'dailyMetricTimeSeries' => [
                        [
                            'dailyMetric' => 'WEBSITE_CLICKS',
                            'timeSeries' => ['datedValues' => [['value' => '10'], ['value' => '5']]],
                        ],
                        [
                            'dailyMetric' => 'CALL_CLICKS',
                            'timeSeries' => ['datedValues' => [['value' => '3']]],
                        ],
                    ],
                ],
            ],
        ], 200),
    ]);

    $metrics = $this->analytics->getMetrics($this->socialAccount);

    $labels = array_column($metrics, 'label');
    expect($labels)->toHaveCount(5);

    $websiteClicks = collect($metrics)->firstWhere('label', __('analytics.metrics.website_clicks'));
    expect($websiteClicks['value'])->toBe(15);
});

test('returns empty array on api failure', function () {
    Http::fake([
        config('trypost.platforms.google_business.performance_api').'/*' => Http::response([], 500),
    ]);

    expect($this->analytics->getMetrics($this->socialAccount))->toBe([]);
});
