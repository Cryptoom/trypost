<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Enums\Media\Type as MediaType;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Issue a one-shot signed POST URL that lets the user upload a local file (image, video, or PDF document) directly to this workspace. Size caps match web/API media limits (video up to the configured video max; images and PDFs use their own smaller caps). Returns an upload_token, upload_url, and max_bytes. Hand the URL to the user (e.g. as a curl command with `-F media=@path/to/file`) or to the MCP client. After upload, call AttachMediaFromUploadTool(post_id, upload_token) to attach the result to a post.')]
class RequestMediaUploadTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $user = $request->user();
        $workspaceId = $user->current_workspace_id;

        // Same ceiling the web/API FormRequests use (largest per-type cap).
        $maxBytes = MediaType::Video->maxSizeInBytes();
        $ttlMinutes = (int) config('trypost.media.signed_upload_url_ttl_minutes');

        $token = (string) Str::uuid();
        $expiresAt = CarbonImmutable::now()->addMinutes($ttlMinutes);

        $uploadUrl = URL::temporarySignedRoute(
            'api.uploads.store',
            $expiresAt,
            ['token' => $token, 'workspace_id' => $workspaceId],
        );

        return Response::structured([
            'upload_token' => $token,
            'upload_url' => $uploadUrl,
            'expires_at' => $expiresAt->toIso8601String(),
            'max_bytes' => $maxBytes,
            'field_name' => 'media',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
