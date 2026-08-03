<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\Api\ApiKey\StoreApiKeyRequest;
use App\Http\Resources\Api\ApiKeyResource;
use App\Models\AccessToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class ApiKeyController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('manageTeam', $request->user()->currentWorkspace);

        $tokens = AccessToken::where('user_id', $request->user()->id)
            ->where('workspace_id', $request->user()->currentWorkspace->id)
            ->where('revoked', false)
            ->latest()
            ->get();

        return ApiKeyResource::collection($tokens);
    }

    public function store(StoreApiKeyRequest $request): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;
        $this->authorize('manageTeam', $workspace);
        $validated = $request->validated();

        $result = $request->user()->createToken($validated['name']);

        $token = AccessToken::find($result->token->id);
        $attributes = [
            'workspace_id' => $workspace->id,
        ];

        if ($expiresAt = data_get($validated, 'expires_at')) {
            $attributes['expires_at'] = $expiresAt;
        }

        $token->forceFill($attributes)->saveQuietly();

        return response()->json([
            'token' => new ApiKeyResource($token->refresh()),
            'plain_token' => $result->accessToken,
        ], Response::HTTP_CREATED);
    }

    public function destroy(Request $request, string $tokenId): JsonResponse
    {
        $this->authorize('manageTeam', $request->user()->currentWorkspace);

        $token = AccessToken::where('id', $tokenId)
            ->where('user_id', $request->user()->id)
            ->where('workspace_id', $request->user()->currentWorkspace->id)
            ->first();

        if (! $token) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $token->forceFill(['revoked' => true])->saveQuietly();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }
}
