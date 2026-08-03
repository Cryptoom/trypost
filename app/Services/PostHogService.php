<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\PostHog\SendEvent;
use App\Models\Account;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PostHogService
{
    public static function isEnabled(): bool
    {
        return (bool) config('services.posthog.enabled')
            && (bool) config('services.posthog.api_key');
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function capture(
        string $distinctId,
        string $event,
        array $properties = [],
        ?Account $account = null,
        ?string $dedupeKey = null,
    ): void {
        if (
            ! self::isEnabled()
            || ($dedupeKey !== null && Cache::has(SendEvent::deliveredKey($dedupeKey)))
        ) {
            return;
        }

        $payload = [
            'distinctId' => $distinctId,
            'event' => $event,
            'properties' => $properties,
            'uuid' => (string) Str::uuid(),
            'timestamp' => now()->toIso8601String(),
        ];

        if ($account) {
            $payload['properties']['$groups'] = ['account' => (string) $account->id];
            $payload['properties']['account_id'] = (string) $account->id;
            $payload['properties']['plan'] = $account->plan?->name;
        }

        $this->dispatch('capture', $payload, $dedupeKey);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function identify(string $distinctId, array $properties = []): void
    {
        if (! self::isEnabled()) {
            return;
        }

        $this->dispatch('identify', [
            'distinctId' => $distinctId,
            'properties' => $properties,
        ]);
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function groupIdentify(string $groupType, string $groupKey, array $properties = []): void
    {
        if (! self::isEnabled()) {
            return;
        }

        $this->dispatch('groupIdentify', [
            'groupType' => $groupType,
            'groupKey' => $groupKey,
            'properties' => $properties,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dispatch(string $method, array $payload, ?string $dedupeKey = null): void
    {
        try {
            SendEvent::dispatch($method, $payload, $dedupeKey);
        } catch (Throwable $e) {
            Log::warning('PostHogService: failed to dispatch event', ['method' => $method, 'error' => $e->getMessage()]);
        }
    }
}
