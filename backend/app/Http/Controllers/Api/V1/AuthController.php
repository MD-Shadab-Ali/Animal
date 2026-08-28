<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmailOtp;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone'    => ['required', 'string', 'max:30'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        // Created unverified and handed no token: the account exists so the
        // email is taken and the details are kept, but it cannot be used until
        // someone proves they can read mail sent to that address.
        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'phone'    => $data['phone'],
            'password' => $data['password'],
            'role'     => 'customer',
        ]);

        app(OtpService::class)->issue($user->email, EmailOtp::PURPOSE_REGISTER);

        return response()->json([
            'message' => 'We sent a code to '.$user->email.'. Enter it to finish signing up.',
            'data'    => ['email' => $user->email, 'verification_required' => true],
        ], 201);
    }

    /**
     * Finishing a signup with the code that was emailed.
     *
     * The token is issued here rather than at registration, so an account
     * cannot be used by whoever typed the address -- only by whoever can read
     * mail sent to it.
     */
    public function verifyEmail(Request $request, OtpService $otps): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'code'  => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->firstOrFail();

        if ($user->email_verified_at !== null) {
            throw ValidationException::withMessages([
                'email' => ['This email is already verified. You can sign in.'],
            ]);
        }

        $otps->verify($user->email, EmailOtp::PURPOSE_REGISTER, $data['code']);

        $user->forceFill(['email_verified_at' => now(), 'last_login_at' => now()])->save();
        $otps->forget($user->email, EmailOtp::PURPOSE_REGISTER);

        return response()->json([
            'message' => 'Welcome aboard.',
            'data'    => [
                'user'  => $this->userPayload($user),
                'token' => $user->createToken('storefront')->plainTextToken,
            ],
        ], 201);
    }

    /** Another code, for a first one that never arrived. */
    public function resendVerification(Request $request, OtpService $otps): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::where('email', $data['email'])->first();

        // Answered the same way whether or not the account exists or is
        // already verified, so this cannot be used to find out either.
        if ($user && $user->email_verified_at === null) {
            $otps->issue($user->email, EmailOtp::PURPOSE_REGISTER);
        }

        return response()->json([
            'message' => 'If that account is waiting to be verified, a new code is on its way.',
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['These credentials do not match our records.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['This account has been disabled. Please contact us.'],
            ]);
        }

        // Signing up is not finished until the address is proved, or a made-up
        // address would be as good as a real one.
        if ($user->email_verified_at === null) {
            throw ValidationException::withMessages([
                'email' => ['Please verify your email first. We can send you a new code.'],
                'verification_required' => [true],
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        return response()->json([
            'message' => 'Signed in.',
            'data'    => [
                'user'  => $this->userPayload($user),
                'token' => $user->createToken('storefront')->plainTextToken,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->userPayload($request->user())]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name'  => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,'.$user->id],
        ]);

        $user->update($data);

        return response()->json([
            'message' => 'Profile updated.',
            'data'    => $this->userPayload($user->fresh()),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password'         => ['required', 'confirmed', Password::min(8)],
        ]);

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['That is not your current password.'],
            ]);
        }

        $user->update(['password' => $data['password']]);

        // Signing out other sessions after a password change.
        $user->tokens()->where('id', '!=', $request->user()->currentAccessToken()->id)->delete();

        return response()->json(['message' => 'Password changed.']);
    }

    /**
     * The shape the storefront holds as the signed-in user.
     *
     * `role` and `is_staff` are here so the storefront can offer a staff member
     * the way through to the admin panel, and keep seller-only prompts away
     * from them. They are hints for rendering and nothing more -- what a
     * request may actually do is decided here, by the middleware and the
     * controllers, never by what the browser believes about itself. The list of
     * areas each role may open stays on the server for the same reason.
     */
    private function userPayload(User $user): array
    {
        return [
            'id'       => $user->id,
            'name'     => $user->name,
            'email'    => $user->email,
            'phone'    => $user->phone,
            'avatar'   => $user->avatar_url,
            'role'     => $user->role->value,
            'is_staff' => $user->isStaff(),
        ];
    }
}
