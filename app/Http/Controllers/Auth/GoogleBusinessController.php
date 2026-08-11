<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\SocialAccount\Platform as SocialPlatform;
use App\Enums\SocialAccount\Status;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Models\Workspace;
use App\Services\Social\GoogleBusinessPublisher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;

class GoogleBusinessController extends SocialController
{
    protected string $driver = 'google-business';

    protected SocialPlatform $platform = SocialPlatform::GoogleBusiness;

    protected array $scopes = [
        'https://www.googleapis.com/auth/userinfo.profile',
        'https://www.googleapis.com/auth/userinfo.email',
        'https://www.googleapis.com/auth/business.manage',
    ];

    public function __construct(private readonly GoogleBusinessPublisher $publisher) {}

    public function connect(Request $request): Response
    {
        $this->ensurePlatformEnabled();

        $workspace = $request->user()->currentWorkspace;

        $this->authorize('manageAccounts', $workspace);

        session([
            'social_connect_workspace' => $workspace->id,
            'social_reconnect_id' => null,
        ]);

        return $this->redirectToGoogle();
    }

    public function callback(Request $request): InertiaResponse|RedirectResponse
    {
        $workspaceId = session('social_connect_workspace');

        if (! $workspaceId) {
            return $this->popupCallback(false, __('accounts.popup_callback.session_expired'), $this->platform->value);
        }

        $workspace = Workspace::find($workspaceId);

        if (! $workspace || ! $request->user()->can('manageAccounts', $workspace)) {
            return $this->popupCallback(false, __('accounts.popup_callback.workspace_not_found'), $this->platform->value);
        }

        $reconnectId = session('social_reconnect_id');

        try {
            $socialUser = Socialite::driver($this->driver)->user();

            $locations = $this->publisher->fetchLocations($socialUser->token);

            if (empty($locations)) {
                return $this->popupCallback(false, __('accounts.popup_callback.no_google_business_locations'), $this->platform->value);
            }

            if (count($locations) === 1) {
                $this->connectLocation($workspace, $locations[0], $socialUser->token, $socialUser->refreshToken, $socialUser->expiresIn, $socialUser->getId());

                return $this->popupCallback(true, __('accounts.popup_callback.connected'), $this->platform->value);
            }

            session([
                'google_business_oauth' => [
                    'access_token' => $socialUser->token,
                    'refresh_token' => $socialUser->refreshToken,
                    'expires_in' => $socialUser->expiresIn,
                    'user_id' => $socialUser->getId(),
                    'reconnect_id' => $reconnectId,
                ],
            ]);

            return redirect()->route('app.social.google-business.select-location');
        } catch (NetworkAlreadyConnectedException) {
            return $this->popupCallback(false, __('accounts.popup_callback.network_taken'), $this->platform->value);
        } catch (\Exception $e) {
            Log::error('Google Business Profile OAuth Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting'), $this->platform->value);
        }
    }

    public function selectLocation(Request $request): InertiaResponse
    {
        $oauthData = session('google_business_oauth');
        $workspaceId = session('social_connect_workspace');

        if (! $oauthData || ! $workspaceId) {
            return $this->popupCallback(false, __('accounts.popup_callback.session_expired'), $this->platform->value);
        }

        $workspace = Workspace::find($workspaceId);

        if (! $workspace) {
            return $this->popupCallback(false, __('accounts.popup_callback.workspace_not_found'), $this->platform->value);
        }

        $locations = $this->publisher->fetchLocations(data_get($oauthData, 'access_token'));

        if (empty($locations)) {
            $this->forgetSocialConnectSession();
            session()->forget('google_business_oauth');

            return $this->popupCallback(false, __('accounts.popup_callback.no_google_business_locations'), $this->platform->value);
        }

        return Inertia::render('accounts/GoogleBusinessLocationSelect', [
            'workspace' => $workspace,
            'locations' => $locations,
        ]);
    }

    public function select(Request $request): InertiaResponse
    {
        $request->validate([
            'location_id' => 'required|string',
        ]);

        $oauthData = session('google_business_oauth');
        $workspaceId = session('social_connect_workspace');

        if (! $oauthData || ! $workspaceId) {
            return $this->popupCallback(false, __('accounts.popup_callback.session_expired'), $this->platform->value);
        }

        $workspace = Workspace::find($workspaceId);

        if (! $workspace || ! $request->user()->can('manageAccounts', $workspace)) {
            return $this->popupCallback(false, __('accounts.popup_callback.workspace_not_found'), $this->platform->value);
        }

        try {
            $locations = $this->publisher->fetchLocations(data_get($oauthData, 'access_token'));
            $selectedLocation = collect($locations)->firstWhere('id', $request->location_id);

            if (! $selectedLocation) {
                return $this->popupCallback(false, __('accounts.popup_callback.location_not_found'), $this->platform->value);
            }

            $reconnectId = data_get($oauthData, 'reconnect_id');

            if ($reconnectId) {
                $existingAccount = $workspace->socialAccounts()->find($reconnectId);

                if ($existingAccount) {
                    $existingAccount->update([
                        'platform_user_id' => data_get($selectedLocation, 'id'),
                        'username' => data_get($selectedLocation, 'title'),
                        'display_name' => data_get($selectedLocation, 'title'),
                        'access_token' => data_get($oauthData, 'access_token'),
                        'refresh_token' => data_get($oauthData, 'refresh_token'),
                        'token_expires_at' => data_get($oauthData, 'expires_in') ? now()->addSeconds(data_get($oauthData, 'expires_in')) : null,
                        'scopes' => $this->scopes,
                        'meta' => [
                            'location_id' => data_get($selectedLocation, 'id'),
                            'account_name' => data_get($selectedLocation, 'account_name'),
                            'location_name' => data_get($selectedLocation, 'location_name'),
                            'google_user_id' => data_get($oauthData, 'user_id'),
                        ],
                    ]);
                    $existingAccount->markAsConnected();

                    session()->forget(['google_business_oauth', 'social_reconnect_id']);

                    return $this->popupCallback(true, __('accounts.popup_callback.reconnected'), $this->platform->value);
                }
            }

            $this->connectLocation(
                $workspace,
                $selectedLocation,
                data_get($oauthData, 'access_token'),
                data_get($oauthData, 'refresh_token'),
                data_get($oauthData, 'expires_in'),
                data_get($oauthData, 'user_id'),
            );

            session()->forget(['google_business_oauth', 'social_reconnect_id']);

            return $this->popupCallback(true, __('accounts.popup_callback.connected'), $this->platform->value);
        } catch (NetworkAlreadyConnectedException) {
            return $this->popupCallback(false, __('accounts.popup_callback.network_taken'), $this->platform->value);
        } catch (\Exception $e) {
            Log::error('Google Business Profile location selection error', [
                'error' => $e->getMessage(),
            ]);

            return $this->popupCallback(false, __('accounts.popup_callback.error_connecting_location'), $this->platform->value);
        }
    }

    private function connectLocation(Workspace $workspace, array $location, string $accessToken, ?string $refreshToken, ?int $expiresIn, ?string $googleUserId): void
    {
        $workspace->socialAccounts()->updateOrCreate(
            [
                'platform' => $this->platform->value,
                'platform_user_id' => data_get($location, 'id'),
            ],
            [
                'username' => data_get($location, 'title'),
                'display_name' => data_get($location, 'title'),
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_expires_at' => $expiresIn ? now()->addSeconds($expiresIn) : null,
                'scopes' => $this->scopes,
                'status' => Status::Connected,
                'error_message' => null,
                'disconnected_at' => null,
                'meta' => [
                    'location_id' => data_get($location, 'id'),
                    'account_name' => data_get($location, 'account_name'),
                    'location_name' => data_get($location, 'location_name'),
                    'google_user_id' => $googleUserId,
                ],
            ],
        );
    }

    private function redirectToGoogle(): Response
    {
        return Inertia::location(
            Socialite::driver($this->driver)
                ->scopes($this->scopes)
                ->with([
                    'access_type' => 'offline',
                    'prompt' => 'consent',
                    'include_granted_scopes' => 'true',
                ])
                ->redirect()
                ->getTargetUrl()
        );
    }
}
