<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

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
            'mcpUrl' => url('/mcp/trypost'),
            'docsUrl' => 'https://docs.trypost.it',
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

        $tokenIds = AccessToken::query()
            ->where('user_id', $request->user()->id)
            ->where('client_id', $client)
            ->pluck('id');

        if ($tokenIds->isNotEmpty()) {
            DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
            AccessToken::query()->whereIn('id', $tokenIds)->update(['revoked' => true]);
        }

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
            ->where('revoked', false)
            ->with('client')
            ->get()
            ->filter(fn (AccessToken $token): bool => $token->client !== null && ! $token->client->hasGrantType('personal_access'))
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
