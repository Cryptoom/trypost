<?php

declare(strict_types=1);

namespace App\Http\Middleware\App;

use App\Models\Account;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountReady
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if (! config('trypost.self_hosted')) {
            $account = $user->account;
            $requiresCardForTrial = (bool) config('trypost.billing.require_card_for_trial', true);
            $hasAccess = $account && (
                $account->subscribed(Account::SUBSCRIPTION_NAME)
                || (! $requiresCardForTrial && $account->isOnTrial())
            );

            if (! $hasAccess) {
                return redirect()->route('app.welcome.persona');
            }
        }

        return $next($request);
    }
}
