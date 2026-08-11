<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Services\Social\Concerns\HasSocialHttpClient;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

class GoogleBusinessAnalytics
{
    use HasSocialHttpClient;

    /** @var array<string, string> Google metric enum => translation key. */
    private const METRICS = [
        'WEBSITE_CLICKS' => 'analytics.metrics.website_clicks',
        'CALL_CLICKS' => 'analytics.metrics.call_clicks',
        'BUSINESS_DIRECTION_REQUESTS' => 'analytics.metrics.direction_requests',
        'BUSINESS_IMPRESSIONS_DESKTOP_MAPS' => 'analytics.metrics.desktop_map_impressions',
        'BUSINESS_IMPRESSIONS_MOBILE_MAPS' => 'analytics.metrics.mobile_map_impressions',
    ];

    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = config('trypost.platforms.google_business.performance_api');
    }

    public function getMetrics(SocialAccount $account, ?CarbonInterface $since = null, ?CarbonInterface $until = null): array
    {
        $since ??= now()->subDays(7);
        $until ??= now();

        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        $locationName = (string) data_get($account->meta, 'location_name');

        if (blank($locationName)) {
            return [];
        }

        $response = $this->socialHttp()->withToken($account->access_token)
            ->get("{$this->baseUrl}/{$locationName}:fetchMultiDailyMetricsTimeSeries?{$this->buildQuery($since, $until)}");

        if ($response->failed()) {
            Log::warning('Google Business Profile analytics fetch failed', [
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return [];
        }

        $series = data_get($response->json(), 'multiDailyMetricTimeSeries.0.dailyMetricTimeSeries', []);

        $totals = collect(self::METRICS)->mapWithKeys(fn ($labelKey, $metric) => [$metric => 0])->all();

        foreach ($series as $entry) {
            $metric = data_get($entry, 'dailyMetric');

            if (! array_key_exists($metric, $totals)) {
                continue;
            }

            $values = collect(data_get($entry, 'timeSeries.datedValues', []))
                ->sum(fn ($value) => (int) data_get($value, 'value', 0));

            $totals[$metric] = $values;
        }

        return collect(self::METRICS)
            ->map(fn (string $labelKey, string $metric) => ['label' => __($labelKey), 'value' => $totals[$metric]])
            ->values()
            ->all();
    }

    /**
     * Google expects `dailyMetrics` as repeated scalar params, which
     * `http_build_query` (and therefore the HTTP client's array query support)
     * would encode as `dailyMetrics[0]=...` instead.
     */
    private function buildQuery(CarbonInterface $since, CarbonInterface $until): string
    {
        $metrics = implode('&', array_map(
            fn (string $metric): string => 'dailyMetrics='.urlencode($metric),
            array_keys(self::METRICS),
        ));

        $range = http_build_query([
            'dailyRange.start_date.year' => $since->format('Y'),
            'dailyRange.start_date.month' => $since->format('n'),
            'dailyRange.start_date.day' => $since->format('j'),
            'dailyRange.end_date.year' => $until->format('Y'),
            'dailyRange.end_date.month' => $until->format('n'),
            'dailyRange.end_date.day' => $until->format('j'),
        ]);

        return "{$metrics}&{$range}";
    }
}
