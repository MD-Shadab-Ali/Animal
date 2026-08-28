<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects a token belonging to a disabled account.
 *
 * Login already refuses an inactive account, but that check runs once. A token
 * issued before the account was disabled would otherwise keep working for as
 * long as it existed -- disabling someone in the admin panel looked like it
 * had taken effect while they carried on shopping. This closes the gap on
 * every authenticated request; the panel additionally revokes the tokens so
 * the session ends the moment the toggle is saved rather than on next use.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            // Drop the token on the way out: there is no path back to an active
            // session with it, and leaving it alive invites a retry loop.
            $request->user()->currentAccessToken()?->delete();

            // 401 rather than 403 so the storefront treats it like any other
            // expired token and clears the stored session instead of parking
            // the user on a dead page.
            return response()->json([
                'message' => 'This account has been disabled. Please contact us.',
                'code'    => 'account_disabled',
            ], 401);
        }

        return $next($request);
    }
}
