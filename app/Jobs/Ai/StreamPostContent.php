<?php

declare(strict_types=1);

namespace App\Jobs\Ai;

use App\Ai\Agents\PostContentStreamer;
use App\Models\Workspace;
use App\Services\Ai\RecordAiUsage;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\StreamedAgentResponse;
use Laravel\Ai\Streaming\Events\Error;
use Laravel\Ai\Streaming\Events\StreamEnd;
use Laravel\Ai\Streaming\Events\TextDelta;
use Throwable;

class StreamPostContent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $workspaceId,
        public string $userId,
        public string $generationId,
        public string $prompt,
        public ?string $currentContent,
    ) {
        $this->onQueue('ai');
    }

    public function handle(): void
    {
        $workspace = Workspace::findOrFail($this->workspaceId);

        $agent = new PostContentStreamer(
            workspace: $workspace,
            currentContent: $this->currentContent,
        );

        $channel = $this->channel();

        try {
            /** @var Meta|null $meta */
            $meta = null;

            $response = $agent->broadcast($this->prompt, $channel, now: true)
                ->then(function (StreamedAgentResponse $streamed) use (&$meta): void {
                    $meta = $streamed->meta;
                });

            $this->reportStream($response->events, $channel);

            RecordAiUsage::recordText(
                workspace: $workspace,
                promptTokens: $response->usage?->promptTokens ?? 0,
                completionTokens: $response->usage?->completionTokens ?? 0,
                provider: (string) $meta?->provider,
                model: (string) $meta?->model,
                userId: $this->userId,
                metadata: ['agent' => 'post_streamer'],
            );
        } catch (Throwable $e) {
            Log::error('PostContentGenerator stream failed', [
                'generation_id' => $this->generationId,
                'error' => $e->getMessage(),
            ]);
            $this->broadcastError($channel);
            throw $e;
        }
    }

    /**
     * PATCH:aig-01 Queue failure hook (e.g. before the stream starts).
     */
    public function failed(Throwable $exception): void
    {
        $this->broadcastError($this->channel());
    }

    /**
     * PATCH:aig-01 The library broadcasts a provider Error event under the
     * provider's error code, which the frontend never listens for. Re-broadcast
     * it under the stable name `error`, and log lengths only (never content)
     * at stream end so the live cause can be confirmed.
     *
     * @param  Collection<int, mixed>  $events
     */
    public function reportStream(Collection $events, PrivateChannel $channel): void
    {
        if ($events->contains(fn ($event) => $event instanceof Error)) {
            $this->broadcastError($channel);
        }

        if (! $events->contains(fn ($event) => $event instanceof StreamEnd)) {
            return;
        }

        $deltas = $events->whereInstanceOf(TextDelta::class);
        $text = TextDelta::combine($events);

        Log::info('PostContentGenerator stream ended', [
            'generation_id' => $this->generationId,
            'delta_count' => $deltas->count(),
            'char_count' => mb_strlen($text),
            'starts_with_fence' => str_starts_with(ltrim($text), '```'),
            'starts_with_brace' => str_starts_with(ltrim($text), '{'),
        ]);
    }

    private function channel(): PrivateChannel
    {
        return new PrivateChannel("user.{$this->userId}.ai-gen.{$this->generationId}");
    }

    /**
     * Broadcast a stable `error` event; the message is left to the frontend's
     * translated default so provider text never reaches the user.
     */
    private function broadcastError(PrivateChannel $channel): void
    {
        try {
            Broadcast::on($channel)
                ->as('error')
                ->with(['recoverable' => false])
                ->sendNow();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
