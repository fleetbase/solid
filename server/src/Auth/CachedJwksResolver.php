<?php

namespace Fleetbase\Solid\Auth;

use Fleetbase\Solid\Auth\Contracts\JwksResolver;
use Fleetbase\Solid\Auth\Contracts\OidcTransport;
use Illuminate\Support\Facades\Cache;

/**
 * Fetches a provider's JWKS over HTTP and caches it briefly.
 *
 * The TTL is short for the same reason Core API's `IdTokenVerifier` keeps it
 * short: a provider that rotates a signing key must not be able to lock users
 * out for longer than a few minutes. `$forceRefresh` handles the other half of
 * rotation — a token arriving before the cache expires, naming a key we have not
 * seen yet.
 */
class CachedJwksResolver implements JwksResolver
{
    /**
     * Matches `Fleetbase\Auth\OAuth\IdTokenVerifier::JWKS_CACHE_SECONDS`.
     */
    public const DEFAULT_TTL_SECONDS = 300;

    public function __construct(
        private readonly OidcTransport $transport,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function resolve(string $jwksUri, bool $forceRefresh = false): array
    {
        $cacheKey = 'solid:oidc:jwks:' . sha1($jwksUri);

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        $cached = Cache::get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $jwks = $this->transport->get($jwksUri)->array('the provider jwks_uri');

        if ($this->ttlSeconds > 0) {
            Cache::put($cacheKey, $jwks, $this->ttlSeconds);
        }

        return $jwks;
    }
}
