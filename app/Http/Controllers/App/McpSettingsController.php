<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Events\OnboardingStatusUpdated;
use App\Models\AccessToken;
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

        $this->authorize('view', $workspace);

        return Inertia::render('settings/workspace/Mcp', [
            'workspace' => $workspace,
            'mcpUrl' => route('mcp.trypost'),
            'mcpClients' => collect(config('trypost.mcp.clients', []))
                ->map(fn (array $client, string $id): array => [
                    'id' => $id,
                    'label' => (string) data_get($client, 'label'),
                    'logo' => (string) data_get($client, 'logo'),
                    'settings_url' => (string) data_get($client, 'settings_url'),
                ])
                ->values()
                ->all(),
            'connectedClients' => $this->connectedClients($request),
        ]);
    }

    public function disconnect(Request $request, string $client): RedirectResponse
    {
        $workspace = $request->user()->currentWorkspace;

        if (! $workspace) {
            return redirect()->route('app.workspaces.create');
        }

        $this->authorize('view', $workspace);

        $tokens = AccessToken::query()
            ->where('user_id', $request->user()->id)
            ->where('client_id', $client)
            ->activeMcpOAuth()
            ->get();

        if ($tokens->isEmpty()) {
            return back();
        }

        $tokenIds = $tokens->pluck('id');

        DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);

        $tokens->each(function (AccessToken $token): void {
            $token->forceFill(['revoked' => true])->saveQuietly();
        });

        OnboardingStatusUpdated::dispatchForWorkspace($request->user()->current_workspace_id);

        return back()->with('flash.success', __('mcp.disconnected'));
    }

    /**
     * The user's active OAuth grants (MCP connections), excluding the personal
     * access client used to mint API tokens.
     *
     * @return array<int, array{client_id: string, name: string, last_used_at: mixed}>
     */
    private function connectedClients(Request $request): array
    {
        return AccessToken::query()
            ->where('user_id', $request->user()->id)
            ->activeMcpOAuth()
            ->with('client')
            ->get()
            ->groupBy('client_id')
            ->map(fn ($tokens): array => [
                'client_id' => $tokens->first()->client_id,
                'name' => $tokens->first()->client->name,
                'last_used_at' => $tokens->max('last_used_at'),
            ])
            ->values()
            ->all();
    }
}
