<?php

namespace Fleetbase\Solid\Auth\Contracts;

/**
 * Short-lived server-side storage for the values that tie an authorization
 * request to its callback: the CSRF `state`, the OIDC `nonce` and the PKCE
 * `code_verifier`.
 *
 * These cannot live in the HTTP session. Solid's callback route is hit by the
 * identity provider's cross-site redirect, and the route group it lives in has no
 * session middleware — the same constraint Core API's OAuth flow solves with the
 * `oauth_states` table (see `Fleetbase\Auth\OAuth\Socialite\Concerns\ServerSidePkce`).
 *
 * Every entry is written with a TTL. An authorization request that is never
 * completed must expire on its own rather than remain replayable forever.
 */
interface OidcStateStore
{
    /**
     * Read a value, or null when it is absent or expired.
     */
    public function get(string $key): ?string;

    /**
     * Write a value that expires after $ttlSeconds.
     *
     * A $ttlSeconds of zero or less means no expiry. That is only correct for
     * values which must outlive a single sign-in — a dynamic client registration —
     * never for `state`, `nonce` or a PKCE verifier.
     */
    public function put(string $key, string $value, int $ttlSeconds): void;

    /**
     * Delete a value. Called as soon as a single-use value has been consumed.
     */
    public function forget(string $key): void;
}
