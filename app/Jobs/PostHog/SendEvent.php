<?php

declare(strict_types=1);

namespace App\Jobs\PostHog;

use App\Services\PostHogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PostHog\PostHog;
use RuntimeException;

class SendEvent implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 15;

    public int $uniqueFor = 86400;

    public string $uniqueJobId;

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
            Cache::put(
                self::deliveredKey($this->dedupeKey),
                true,
                now()->addYears(100),
            );
        }
    }

    public static function deliveredKey(string $dedupeKey): string
    {
        return "posthog_delivered:{$dedupeKey}";
    }
}
