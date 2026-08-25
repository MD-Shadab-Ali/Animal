<?php

namespace App\Http\Middleware;

use App\Models\Seller;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the seller area. A pending or suspended account can still read its own
 * profile (so it can see why), but cannot touch listings.
 */
class EnsureUserIsSeller
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Read through instead of trusting a relation that may already be loaded.
        $seller = $user ? Seller::where('user_id', $user->id)->first() : null;

        if ($seller) {
            $user->setRelation('seller', $seller);
        }

        if (! $seller) {
            return response()->json([
                'message' => 'You do not have a seller account yet.',
                'code'    => 'not_a_seller',
            ], 403);
        }

        if (! $seller->isApproved()) {
            return response()->json([
                'message' => match ($seller->status) {
                    'pending'   => 'Your seller application is still being reviewed.',
                    'suspended' => 'Your seller account is suspended. Please contact us.',
                    default     => 'Your seller application was not approved.',
                },
                'code'   => 'seller_'.$seller->status,
                'status' => $seller->status,
            ], 403);
        }

        return $next($request);
    }
}
