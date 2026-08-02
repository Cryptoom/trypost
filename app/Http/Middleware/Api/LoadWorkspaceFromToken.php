<?php

declare(strict_types=1);

namespace App\Http\Middleware\Api;

use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LoadWorkspaceFromToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->token();

        if (! $token) {
            return response()->json(['message' => 'Token not found.'], Response::HTTP_UNAUTHORIZED);
        }

        // Personal API keys and MCP OAuth grants both bind to a workspace at
        // issue time. Resolve from the token — never from the user's current
        // workspace switcher (that would let a multi-workspace agent silently
        // act on the wrong tenant).
        $workspace = $token->workspace_id
            ? Workspace::query()->find($token->workspace_id)
            : null;

        if (! $workspace) {
            return response()->json(['message' => 'No workspace selected.'], Response::HTTP_UNAUTHORIZED);
        }

        if (! $user->belongsToWorkspace($workspace)) {
            return response()->json(['message' => 'No workspace selected.'], Response::HTTP_UNAUTHORIZED);
        }

        if (! config('trypost.self_hosted') && ! $workspace->account?->hasActiveSubscription()) {
            return response()->json(['message' => 'Active subscription required.'], Response::HTTP_PAYMENT_REQUIRED);
        }

        $user->setRelation('currentWorkspace', $workspace);
        $user->current_workspace_id = $workspace->id;

        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        return $next($request);
    }
}
