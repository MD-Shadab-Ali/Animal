<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmailOtp;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    /**
     * Email a reset code.
     *
     * The response is deliberately identical whether or not the address exists,
     * so this endpoint cannot be used to discover who has an account.
     */
    public function sendResetLink(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        // Only issued for an address that actually has an account, but the
        // answer below never says so either way.
        if (User::where('email', $data['email'])->exists()) {
            app(OtpService::class)->issue($data['email'], EmailOtp::PURPOSE_PASSWORD_RESET);
        }

        return response()->json([
            'message' => 'If that email belongs to an account, a reset code is on its way.',
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'code'     => ['required', 'string'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $otps = app(OtpService::class);

        // Checked before the account is looked up so a wrong code and an
        // unknown address fail identically.
        $otps->verify($data['email'], EmailOtp::PURPOSE_PASSWORD_RESET, $data['code']);

        $user = User::where('email', $data['email'])->firstOrFail();

        $user->forceFill([
            'password'       => $data['password'],
            'remember_token' => Str::random(60),
        ])->save();

        // A reset invalidates every existing API token.
        $user->tokens()->delete();

        // Someone who can read this address has now proved it, which is the
        // same thing signup asks for.
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        $otps->forget($user->email, EmailOtp::PURPOSE_PASSWORD_RESET);

        event(new PasswordReset($user));

        return response()->json([
            'message' => 'Your password has been reset. You can sign in now.',
        ]);
    }
}
