<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\SocialAccount\ListPinterestBoards;
use App\Actions\SocialAccount\ToggleSocialAccount;
use App\Enums\SocialAccount\Platform;
use App\Http\Resources\Api\SocialAccountResource;
use App\Models\SocialAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class SocialAccountController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $accounts = $request->user()->currentWorkspace->socialAccounts()->orderBy('platform')->get();

        return SocialAccountResource::collection($accounts);
    }

    public function toggle(Request $request, SocialAccount $account): SocialAccountResource|JsonResponse
    {
        if ($account->workspace_id !== $request->user()->currentWorkspace->id) {
            return response()->json(
                ['message' => 'Account not found.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        ToggleSocialAccount::execute($account);

        return new SocialAccountResource($account);
    }

    public function boards(Request $request, SocialAccount $account): JsonResponse
    {
        if ($account->workspace_id !== $request->user()->currentWorkspace->id) {
            return response()->json(
                ['message' => 'Account not found.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        if ($account->platform !== Platform::Pinterest) {
            return response()->json(
                ['message' => 'Boards are only available for Pinterest accounts.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        return response()->json([
            'boards' => ListPinterestBoards::execute($account),
        ]);
    }
}
