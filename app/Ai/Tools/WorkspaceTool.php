<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Throwable;

/**
 * Base for every chat tool. Two guarantees:
 *
 * 1. Scope. A tool never accepts a workspace id as an argument; every query
 *    starts from $this->workspace, so a prompt injection has nowhere to write
 *    one.
 * 2. Containment. A thrown exception becomes an error string the model can
 *    recover from, rather than a 500 that kills the stream. The real
 *    exception message is only ever logged — a caught Throwable can carry
 *    database internals (table/column names, host, the substituted SQL, in
 *    the case of a QueryException), so the model only ever sees a generic,
 *    translated message for that path. Errors raised deliberately inside
 *    run() (e.g. "post not found") are untouched and still reach the model.
 */
abstract class WorkspaceTool implements Tool
{
    public function __construct(
        protected Workspace $workspace,
        protected User $user,
    ) {}

    /**
     * The tool's snake_case name, used as the cross-boundary contract key by
     * the SDK, the agent, and the frontend component registry. Never rely on
     * ToolNameResolver's class_basename() fallback.
     */
    abstract public function name(): string;

    public function handle(Request $request): string
    {
        try {
            return $this->run($request);
        } catch (Throwable $e) {
            Log::warning('Chat tool failed', [
                'tool' => static::class,
                'arguments' => $request->toArray(),
                'error' => $e->getMessage(),
            ]);

            return $this->error(__('chat.tools.error'));
        }
    }

    abstract protected function run(Request $request): string;

    protected function json(mixed $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    protected function error(string $message): string
    {
        return $this->json(['error' => $message]);
    }

    /**
     * Resolve a post inside this tool's workspace. Returns null for a missing
     * id (including an empty or whitespace-only string, which is what
     * `$request->string('post_id')->value()` yields when the argument is
     * absent), a malformed id, or a post belonging to another workspace — the
     * four are indistinguishable to the model on purpose.
     */
    protected function resolvePost(?string $postId): ?Post
    {
        if (blank($postId)) {
            return null;
        }

        return $this->workspace->posts()->with(['postPlatforms.socialAccount'])->find($postId);
    }
}
