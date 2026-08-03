<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Events\OnboardingStatusUpdated;
use App\Models\AccessToken;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class McpSettingsController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('createPost', $workspace);

        return Inertia::render('settings/workspace/Mcp', [
            'mcpUrl' => route('mcp.trypost'),
            'connectedClients' => $this->connectedClients($workspace->account_id, $request->user()),
        ]);
    }

    public function disconnect(Request $request, string $client): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('createPost', $workspace);

        $user = $request->user();

        $tokens = AccessToken::query()
            ->where('user_id', $user->id)
            ->where('client_id', $client)
            ->activeMcpOAuth()
            ->get();

        if ($tokens->isEmpty()) {
            return back();
        }

        DB::transaction(function () use ($tokens): void {
            $tokenIds = $tokens->pluck('id');

            DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);

            $tokens->each(function (AccessToken $token): void {
                $token->forceFill(['revoked' => true])->saveQuietly();
            });
        });

        OnboardingStatusUpdated::dispatchForAccount($user->account, $user);

        return back()->with('flash.success', __('mcp.disconnected'));
    }

    /**
     * Active OAuth MCP grants across the account (any teammate), excluding
     * personal access API tokens.
     *
     * @return array<int, array{client_id: string, name: string, user_id: string, user_name: string, can_disconnect: bool, last_used_at: mixed}>
     */
    private function connectedClients(string $accountId, User $currentUser): array
    {
        $tokens = AccessToken::query()
            ->whereIn('user_id', User::query()->select('id')->where('account_id', $accountId))
            ->activeMcpOAuth()
            ->with('client')
            ->get();

        $users = User::query()
            ->whereIn('id', $tokens->pluck('user_id')->unique()->filter()->all())
            ->get()
            ->keyBy('id');

        return $tokens
            ->groupBy(fn (AccessToken $token): string => "{$token->client_id}:{$token->user_id}")
            ->map(function ($grouped) use ($currentUser, $users): array {
                $first = $grouped->first();
                $owner = $users->get($first->user_id);

                return [
                    'client_id' => $first->client_id,
                    'name' => $first->client->name,
                    'user_id' => (string) $first->user_id,
                    'user_name' => (string) ($owner?->name ?? ''),
                    'can_disconnect' => $first->user_id === $currentUser->id,
                    'last_used_at' => $grouped->max('last_used_at'),
                ];
            })
            ->values()
            ->all();
    }
}
