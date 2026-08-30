<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EmailOtp;
use App\Models\User;
use App\Services\GoogleIdTokenVerifier;
use App\Services\OtpService;
use App\Services\RecaptchaVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request, RecaptchaVerifier $recaptcha): JsonResponse
    {
        $data = $request->validate([
            'name'            => ['required', 'string', 'max:255'],
            'email'           => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone'           => ['required', 'string', 'max:30'],
            'password'        => ['required', 'confirmed', Password::min(8)],

            // Presence is the verifier's business, not a rule's: leaving it
            // nullable here keeps one place that decides what a missing or
            // stale token means.
            'recaptcha_token' => ['nullable', 'string'],
        ]);

        // After the rest, so a taken email is reported without also spending a
        // token that was perfectly good.
        $recaptcha->assertValid($data['recaptcha_token'] ?? null, $request->ip());

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

    public function login(Request $request, RecaptchaVerifier $recaptcha): JsonResponse
    {
        $data = $request->validate([
            'email'           => ['required', 'email'],
            'password'        => ['required', 'string'],
            'recaptcha_token' => ['nullable', 'string'],
        ]);

        // Before the password is looked at, so a wrong password and a stale
        // token are answered in the order the person has to fix them.
        $recaptcha->assertValid($data['recaptcha_token'] ?? null, $request->ip());

        $user = User::where('email', $data['email'])->first();

        /*
         * blank() covers an account that only exists through Google: it has no
         * password, and Hash::check() against null is a type error rather than
         * a false.
         *
         * The message stays the same either way. Telling someone their address
         * is on a Google account would be more helpful and would also answer,
         * for anybody who asks, whether a given address is registered here.
         */
        if (! $user || blank($user->password) || ! Hash::check($data['password'], $user->password)) {
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

    /**
     * Signing in, or up, with Google -- one handler for both.
     *
     * There is deliberately no register-with-Google and sign-in-with-Google
     * distinction. The button is the same on both pages and this decides what
     * it meant: an account already on that address is signed in, and one that
     * is not there yet is created and signed in. Asking someone to know which
     * of the two they are is asking them a question about our database.
     *
     * What comes back is the same token and the same user payload as an
     * ordinary sign-in, so nothing downstream can tell the difference.
     */
    public function google(Request $request, GoogleIdTokenVerifier $verifier): JsonResponse
    {
        $data = $request->validate([
            'credential' => ['required', 'string'],
        ]);

        $profile = $verifier->verify($data['credential']);

        // The subject id first: it survives someone changing the address on
        // their Google account, where a match on email would not.
        $user = User::where('google_id', $profile['sub'])->first()
            ?: User::where('email', $profile['email'])->first();

        $created = false;

        if (! $user) {
            $user = User::create([
                'name'  => $profile['name'] ?: Str::before($profile['email'], '@'),
                'email' => $profile['email'],
                'role'  => 'customer',

                // Stated rather than left to the column default. The default
                // applies in the database, but the model in hand still has null
                // for it, and the is_active check below would then refuse the
                // account it had just created.
                'is_active' => true,
            ]);

            $created = true;
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'credential' => ['This account has been disabled. Please contact us.'],
            ]);
        }

        /*
         * Linking an existing password account to Google on the strength of a
         * matching address. Safe only because the verifier refuses any token
         * whose email Google has not itself verified -- without that check this
         * line would be a way into somebody else's account.
         *
         * The password, if there is one, is left alone and goes on working.
         */
        $user->forceFill([
            'google_id'         => $profile['sub'],
            'email_verified_at' => $user->email_verified_at ?? now(),
            'last_login_at'     => now(),
        ])->save();

        return response()->json([
            'message' => $created ? 'Welcome aboard.' : 'Signed in.',
            'data'    => [
                'user'  => $this->userPayload($user),
                'token' => $user->createToken('storefront')->plainTextToken,
            ],
        ], $created ? 201 : 200);
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

        // An account that only ever signed in through Google has no current
        // password to check. Pointed at the reset flow rather than left to fail
        // on a comparison against null -- that flow emails a code, which proves
        // the address just as well as an old password would.
        if (blank($user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['This account signs in with Google. Use "Forgot your password?" to set one.'],
            ]);
        }

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
