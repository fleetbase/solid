<?php

/**
 * -------------------------------------------
 * Fleetbase Solid Extension Configuration
 * -------------------------------------------
 */
return [
    'api' => [
        'version' => '0.0.1',
        'routing' => [
            'prefix'          => 'solid',
            'internal_prefix' => 'int',
        ],
    ],

    'server' => [
        'host'   => env('SOLID_HOST', 'http://solid'),
        'port'   => (int) env('SOLID_PORT', 3000),
        'secure' => (bool) env('SOLID_SECURE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Solid-OIDC
    |--------------------------------------------------------------------------
    |
    | Settings for the Solid-OIDC handshake in Fleetbase\Solid\Client\OpenIDConnectClient.
    | Everything here has a safe default; an operator only needs to touch these
    | when the identity provider is reached differently from the data API, or
    | when running against a development server with a self-signed certificate.
    |
    */
    'oidc' => [
        /*
        | Where `.well-known/openid-configuration` is fetched from. Null means
        | "the Solid server URL above", which is the normal single-host setup.
        | Set it when the handshake goes through a different origin than the API
        | — for example HTTPS via a proxy for OIDC while API calls stay on the
        | internal HTTP address. The issuer every ID token is then checked
        | against is whatever the discovery document itself reports, never this
        | value.
        */
        'issuer' => env('SOLID_OIDC_ISSUER'),

        /*
        | TLS peer verification for discovery, registration, the token endpoint
        | and the JWKS document.
        |
        | This is a single explicit flag rather than a check on the app
        | environment. The previous implementation disabled verification
        | whenever APP_ENV was `local` or `development`, which silently also
        | covered any staging environment named that way. The default preserves
        | the old behaviour for local development while making it impossible for
        | verification to be off in production without someone saying so.
        */
        'verify_tls' => env(
            'SOLID_OIDC_VERIFY_TLS',
            !in_array(env('APP_ENV', 'production'), ['local', 'development'], true)
        ),

        /*
        | How long an in-flight authorization request stays valid, in seconds.
        | The `state`, `nonce` and PKCE verifier are all stored for this long and
        | then expire on their own — an abandoned sign-in must not leave them
        | readable indefinitely.
        */
        'state_ttl' => (int) env('SOLID_OIDC_STATE_TTL', 600),

        /*
        | How long a dynamic client registration is kept, in seconds. 0 means it
        | never expires, which is the default: a registration has to outlive any
        | single sign-in, and re-registering on every login would leave orphaned
        | clients behind on the provider. Logging out clears it explicitly.
        */
        'client_credentials_ttl' => (int) env('SOLID_OIDC_CLIENT_CREDENTIALS_TTL', 0),

        /*
        | How long the provider's JWKS and discovery documents are cached. Both
        | are short so that a provider rotating a signing key cannot lock users
        | out for more than a few minutes; an ID token naming an unknown key also
        | forces one immediate refetch regardless of this value.
        */
        'jwks_cache_ttl'      => (int) env('SOLID_OIDC_JWKS_CACHE_TTL', 300),
        'discovery_cache_ttl' => (int) env('SOLID_OIDC_DISCOVERY_CACHE_TTL', 300),

        /*
        | Clock skew tolerated on an ID token's exp, iat and nbf claims, in
        | seconds. Keep this small: it is the window in which an expired token is
        | still accepted.
        */
        'leeway' => (int) env('SOLID_OIDC_LEEWAY', 60),

        /*
        | Reject an ID token that does not echo the nonce we sent. Providers
        | built on node-oidc-provider — including Community Solid Server — always
        | echo it, so this stays on. It exists only for a provider that violates
        | OIDC Core 3.1.3.7, and turning it off removes a replay control.
        */
        'require_nonce' => (bool) env('SOLID_OIDC_REQUIRE_NONCE', true),

        /*
        | Request timeout for OIDC HTTP calls, in seconds.
        */
        'timeout' => (int) env('SOLID_OIDC_TIMEOUT', 15),

        /*
        | The filesystem disk that holds each identity's DPoP private key.
        |
        | Named explicitly, and never `filesystems.default`: in a Fleetbase
        | install that default is the `public` disk — rooted at
        | storage/app/public, symlinked to public/storage and served over HTTP —
        | or an S3/GCS bucket. An earlier version of this extension wrote the
        | keys there; a key found in the old location is moved here automatically
        | on first use and the exposed copy is deleted.
        |
        | Whatever disk is named must not be reachable from the internet.
        */
        'key_disk' => env('SOLID_OIDC_KEY_DISK', 'local'),
    ],
];
