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
 * Base for every chat tool. Three guarantees:
 *
 * 1. Scope. A tool never accepts a workspace id as an argument; every query
 *    starts from $this->workspace, so a prompt injection has nowhere to write
 *    one.
 * 2. Role. A tool that mutates workspace data extends
 *    {@see WorkspaceWriteTool} and is refused for a member the workspace
 *    policy would refuse anywhere else. Reads stay open to every member,
 *    Viewers included.
 * 3. Containment. A thrown exception becomes an error string the model can
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
        if ($this->writeDenied()) {
            return $this->error(__('chat.tools.forbidden'));
        }

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

    /**
     * Whether this tool mutates workspace data. Only {@see WorkspaceWriteTool}
     * answers true, so the role gate is declared once by inheritance instead
     * of being re-implemented — and forgotten — per tool.
     */
    protected function authorizesWrites(): bool
    {
        return false;
    }

    /**
     * The single authorization predicate for every write tool.
     *
     * `createPost` is the same workspace ability the web controller
     * (App\Http\Controllers\App\PostController) and the MCP post tools
     * enforce: owner, Admin and Member may write, Viewer may not. The chat
     * path itself only checks `view` on the workspace and `useAi` on the
     * account, neither of which is role-aware, so without this a Viewer
     * could create, edit, schedule, publish and delete posts by asking.
     *
     * A denial must never throw: an AuthorizationException escaping a tool
     * would kill the HTTP stream mid-turn. It resolves to an ordinary
     * {@see error()} string so the model can tell the user it lacks
     * permission.
     */
    protected function writeDenied(): bool
    {
        return $this->authorizesWrites() && $this->user->cannot('createPost', $this->workspace);
    }

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
