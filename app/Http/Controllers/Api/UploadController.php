<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreUploadRequest;
use App\Http\Resources\Api\MediaUploadResource;
use App\Models\Media;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class UploadController extends Controller
{
    private const CACHE_TTL_BUFFER_SECONDS = 60;

    /**
     * One-shot claim for a signed upload token (api.uploads.store).
     * Survives across MCP and any other client that POSTs the signed URL.
     */
    private const CLAIM_CACHE_PREFIX = 'media:signed-upload:';

    public function store(StoreUploadRequest $request, string $token): JsonResponse
    {
        $expiresAt = (int) $request->query('expires');
        $ttl = max(
            self::CACHE_TTL_BUFFER_SECONDS,
            $expiresAt - now()->timestamp + self::CACHE_TTL_BUFFER_SECONDS,
        );

        $cacheKey = self::CLAIM_CACHE_PREFIX.$token;

        if (! Cache::add($cacheKey, true, $ttl)) {
            abort(Response::HTTP_CONFLICT);
        }

        if (Media::where('upload_token', $token)->exists()) {
            abort(Response::HTTP_CONFLICT);
        }

        try {
            $workspace = Workspace::findOrFail((string) $request->query('workspace_id'));
            $file = $request->file('media');
            $path = $file->getRealPath();

            // Stream from PHP's temp upload path — do not load the whole file into
            // memory (addMedia() uses file_get_contents; videos can be up to 1GB).
            if ($path === false) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, 'Unable to read uploaded file.');
            }

            $media = DB::transaction(function () use ($workspace, $file, $path, $token): Media {
                $media = $workspace->addMediaFromPath(
                    $path,
                    $file->getClientOriginalName(),
                    'assets',
                    mimeType: (string) $file->getMimeType(),
                );
                $media->upload_token = $token;
                $media->save();

                return $media;
            });
        } catch (Throwable $e) {
            // Claim is only permanent after Media is stored — release so the
            // signed URL can be retried after a transient disk/storage failure.
            Cache::forget($cacheKey);

            throw $e;
        }

        return MediaUploadResource::make($media)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
