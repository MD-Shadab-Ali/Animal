<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    /*
     * eSewa ePay v2. The sandbox merchant code and secret are the ones eSewa
     * publishes for testing -- they are not credentials, and live values must
     * come from the environment.
     */
    'esewa' => [
        'mode'         => env('ESEWA_MODE', 'sandbox'),
        'product_code' => env('ESEWA_PRODUCT_CODE', 'EPAYTEST'),
        'secret'       => env('ESEWA_SECRET', '8gBm/:&EnhH.1/q'),
    ],

    /*
     * Khalti ePayment (KPG-2). Khalti calls it a "live secret key" even on
     * sandbox; the sandbox one comes from test-admin.khalti.com.
     */
    'khalti' => [
        'mode'       => env('KHALTI_MODE', 'sandbox'),
        'secret_key' => env('KHALTI_SECRET_KEY'),
    ],

    /*
     * Google sign-in, through Google Identity Services.
     *
     * Only the client id is needed. The browser obtains an ID token from Google
     * and posts it here; we verify its signature against Google's public keys,
     * so there is no authorisation code to exchange and no client secret to
     * keep. The same id goes to the frontend as NEXT_PUBLIC_GOOGLE_CLIENT_ID --
     * it is public by design and safe to ship in a page.
     *
     * ca_bundle is a local-development escape hatch: PHP on some Windows setups
     * ships with no CA bundle configured, and every HTTPS call from PHP then
     * fails. Leave it empty in production, where the system store is set up.
     */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'ca_bundle' => env('GOOGLE_CA_BUNDLE'),
    ],

    /*
     * Google reCAPTCHA v2 (the "I'm not a robot" checkbox).
     *
     * Two keys, and only one of them is a secret. The site key is rendered into
     * the page and also lives in the frontend as NEXT_PUBLIC_RECAPTCHA_SITE_KEY;
     * the secret key never leaves the server and is what makes the browser's
     * token mean anything.
     *
     * Applies to the email and password forms only -- "Continue with Google"
     * has already proved there is a person there.
     *
     * ca_bundle is the same local-development escape hatch as the Google block
     * above, and falls back to it so there is only one path to keep current.
     */
    'recaptcha' => [
        'site_key'   => env('RECAPTCHA_SITE_KEY'),
        'secret_key' => env('RECAPTCHA_SECRET_KEY'),
        'ca_bundle'  => env('RECAPTCHA_CA_BUNDLE', env('GOOGLE_CA_BUNDLE')),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
