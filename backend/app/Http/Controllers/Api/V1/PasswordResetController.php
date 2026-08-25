<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    /**
     * Email a reset link.
     *
     * The response is deliberately identical whether or not the address exists,
     * so this endpoint cannot be used to discover who has an account.
     */
    public function sendResetLink(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $status = Password::sendResetLink($data);

        if ($status === Password::RESET_THROTTLED) {
            throw ValidationException::withMessages([
                'email' => ['Please wait a moment before asking for another link.'],
            ]);
        }

        return response()->json([
            'message' => 'If that email belongs to an account, a reset link is on its way.',
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'    => ['required', 'string'],
            'email'    => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill([
                'password'       => $password,
                'remember_token' => Str::random(60),
            ])->save();

            // A reset invalidates every existing API token.
            $user->tokens()->delete();

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => [match ($status) {
                    Password::INVALID_TOKEN => 'This reset link has expired or has already been used.',
                    Password::INVALID_USER  => 'We could not find an account for that email.',
                    default                 => 'We could not reset your password. Please request a new link.',
                }],
            ]);
        }

        return response()->json([
            'message' => 'Your password has been reset. You can sign in now.',
        ]);
    }
}
