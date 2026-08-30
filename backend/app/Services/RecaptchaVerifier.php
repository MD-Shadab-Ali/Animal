<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * Checks the "I'm not a robot" token with Google.
 *
 * The widget in the browser produces a token and nothing else. It is not proof
 * of anything until Google has been asked about it here -- a token is easy to
 * omit, replay or invent, and a "captcha passed" flag from the browser is worth
 * exactly what the browser says it is.
 *
 * Applies to the email and password forms only. Signing in through Google has
 * already proved there is a person there, and putting a second challenge in
 * front of it would only be friction.
 */
class RecaptchaVerifier
{
    private const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

    /**
     * Throws unless the token is one Google recognises for this site.
     *
     * The field is named `recaptcha` in the errors so both forms can put the
     * message under the widget.
     */
    public function assertValid(?string $token, ?string $ip = null): void
    {
        $secret = config('services.recaptcha.secret_key');

        // Nothing configured means nothing to check. Refusing here rather than
        // waving the request through: a missing key is a deployment mistake,
        // and the failure should be loud rather than a silently open form.
        if (blank($secret)) {
            throw ValidationException::withMessages([
                'recaptcha' => ['The robot check is not set up on this server yet.'],
            ]);
        }

        if (blank($token)) {
            throw ValidationException::withMessages([
                'recaptcha' => ['Please tick the "I\'m not a robot" box.'],
            ]);
        }

        $result = $this->ask($secret, $token, $ip);

        if ($result['success'] ?? false) {
            return;
        }

        $codes = (array) ($result['error-codes'] ?? []);

        throw ValidationException::withMessages([
            'recaptcha' => [$this->messageFor($codes)],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function ask(string $secret, string $token, ?string $ip): array
    {
        $request = Http::asForm()->timeout(10);

        /*
         * Some PHP installs -- Laragon on Windows among them -- ship with no CA
         * bundle configured, and every HTTPS call from PHP then fails. Pointing
         * at one here is cheaper than making each developer edit php.ini, and
         * does nothing when the setting is left empty.
         */
        if ($bundle = config('services.recaptcha.ca_bundle')) {
            $request = $request->withOptions(['verify' => $bundle]);
        }

        try {
            return $request->post(self::VERIFY_URL, array_filter([
                'secret'   => $secret,
                'response' => $token,
                'remoteip' => $ip,
            ]))->json() ?? [];
        } catch (ConnectionException) {
            /*
             * Google could not be reached. Failing closed on purpose: an open
             * form is the thing this is here to prevent, and an outage that
             * turns the check off is the same as not having it.
             */
            throw ValidationException::withMessages([
                'recaptcha' => ['We could not reach the robot check just now. Please try again.'],
            ]);
        }
    }

    /**
     * @param  array<int, string>  $codes
     */
    private function messageFor(array $codes): string
    {
        // A token is good for about two minutes and for one use only, which is
        // the most common failure by far and deserves its own wording.
        if (in_array('timeout-or-duplicate', $codes, true)) {
            return 'That robot check expired. Please tick the box again.';
        }

        if (in_array('missing-input-response', $codes, true)) {
            return 'Please tick the "I\'m not a robot" box.';
        }

        return 'The robot check did not pass. Please tick the box again.';
    }
}
