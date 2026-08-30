<?php

namespace App\Services;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Checks the ID token Google hands the browser.
 *
 * The token is a JWT signed by Google. Verifying it here -- against Google's
 * published keys, and for our own client id -- is the whole of what makes it
 * proof of identity. Without that, anyone could post a token of their own
 * making and be handed a session as whoever they named in it.
 */
class GoogleIdTokenVerifier
{
    private const CERTS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    /** Google mints tokens under both spellings. */
    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    /** @return array{sub: string, email: string, name: ?string} */
    public function verify(string $credential): array
    {
        $clientId = config('services.google.client_id');

        if (blank($clientId)) {
            throw ValidationException::withMessages([
                'credential' => ['Google sign-in is not set up on this server yet.'],
            ]);
        }

        try {
            // Checks the signature and the expiry, and nothing about who the
            // token was meant for -- that is on us, below.
            $claims = (array) JWT::decode($credential, JWK::parseKeySet($this->certs()));
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'credential' => ['We could not verify that Google sign-in. Please try again.'],
            ]);
        }

        if (($claims['aud'] ?? null) !== $clientId || ! in_array($claims['iss'] ?? null, self::ISSUERS, true)) {
            throw ValidationException::withMessages([
                'credential' => ['That Google sign-in was not issued for this site.'],
            ]);
        }

        $email = $claims['email'] ?? null;

        // Finding an existing account by email is only safe while Google is
        // vouching for the address. An unverified one could be anybody's, and
        // would be a way to walk into somebody else's account.
        if (! is_string($email) || ! filter_var($email, FILTER_VALIDATE_EMAIL)
            || ! filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            throw ValidationException::withMessages([
                'credential' => ['That Google account has no verified email address.'],
            ]);
        }

        return [
            'sub'   => (string) $claims['sub'],
            'email' => mb_strtolower($email),
            'name'  => is_string($claims['name'] ?? null) ? $claims['name'] : null,
        ];
    }

    /**
     * Google's signing keys, cached.
     *
     * They rotate, so this cannot be a fixture -- but fetching them on every
     * sign-in would put Google in the critical path of our own login.
     */
    private function certs(): array
    {
        return Cache::remember('google:jwks', now()->addHours(6), function (): array {
            $request = Http::timeout(10);

            /*
             * Some PHP installs -- Laragon on Windows among them -- ship with
             * no CA bundle configured, which makes every HTTPS call from PHP
             * fail with "unable to get local issuer certificate". Pointing at
             * a bundle here is cheaper than making each developer edit php.ini,
             * and does nothing when the setting is left empty.
             */
            if ($bundle = config('services.google.ca_bundle')) {
                $request = $request->withOptions(['verify' => $bundle]);
            }

            return $request->get(self::CERTS_URL)->throw()->json();
        });
    }
}
