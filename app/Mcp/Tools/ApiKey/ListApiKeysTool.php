<?php

declare(strict_types=1);

namespace App\Mcp\Tools\ApiKey;

use App\Http\Resources\Api\ApiKeyResource;
use App\Models\AccessToken;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List all Personal Access Tokens (API keys) for the current workspace. Returns metadata only — the secret token value is shown only once at creation. OAuth tokens (e.g. ChatGPT MCP sessions) are excluded.')]
class ListApiKeysTool extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        // personalAccess() excludes MCP OAuth grants (which are also
        // workspace-bound after issue #222 — workspace_id alone is no longer
        // a reliable discriminator).
        $tokens = AccessToken::query()
            ->where('user_id', $request->user()->id)
            ->where('workspace_id', $request->user()->current_workspace_id)
            ->where('revoked', false)
            ->personalAccess()
            ->latest()
            ->get();

        return Response::structured([
            'api_keys' => ApiKeyResource::collection($tokens)->resolve(),
        ]);
    }
}
