<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Services\PostHogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\UniqueFor;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PostHog\PostHog;
use RuntimeException;
use Throwable;

#[UniqueFor(86400)]
class SendEvent implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 15;

    private string $uniqueJobId;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $method,
        public array $payload,
        public ?string $dedupeKey = null,
    ) {
        $this->uniqueJobId = $dedupeKey ?? (string) Str::uuid();
        $this->onQueue('posthog');
    }

    public function uniqueId(): string
    {
        return $this->uniqueJobId;
    }

    public function handle(): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        if ($this->dedupeKey !== null && self::wasDelivered($this->dedupeKey)) {
            return;
        }

        match ($this->method) {
            'capture' => PostHog::capture($this->payload),
            'identify' => PostHog::identify($this->payload),
            'groupIdentify' => PostHog::groupIdentify($this->payload),
            default => Log::warning('PostHog SendEvent: unknown method', ['method' => $this->method]),
        };

        if (! PostHog::flush()) {
            throw new RuntimeException('PostHog event flush failed.');
        }

        if ($this->dedupeKey !== null) {
            self::markDelivered($this->dedupeKey);
        }
    }

    public static function wasDelivered(string $dedupeKey): bool
    {
        try {
            return Cache::has(self::deliveredKey($dedupeKey));
        } catch (Throwable $exception) {
            Log::warning('PostHog delivery dedupe lookup failed; treating as undelivered.', [
                'dedupe_key' => $dedupeKey,
                'exception' => $exception,
            ]);

            return false;
        }
    }

    public static function markDelivered(string $dedupeKey): void
    {
        try {
            Cache::forever(self::deliveredKey($dedupeKey), true);
        } catch (Throwable $exception) {
            Log::warning('PostHog delivery dedupe write failed.', [
                'dedupe_key' => $dedupeKey,
                'exception' => $exception,
            ]);
        }
    }

    public static function deliveredKey(string $dedupeKey): string
    {
        return "posthog_delivered:{$dedupeKey}";
    }
}
