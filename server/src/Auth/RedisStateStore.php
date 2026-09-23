<?php

namespace Fleetbase\Solid\Auth;

use Fleetbase\Solid\Auth\Contracts\OidcStateStore;
use Illuminate\Support\Facades\Redis;

/**
 * Keeps authorization-request state in Redis, namespaced per Solid identity.
 *
 * Solid already used Redis for this, but wrote every key with `SET` and no
 * expiry, so an abandoned authorization left its `state`, `nonce` and PKCE
 * `code_verifier` readable indefinitely. Every write here carries a TTL.
 */
class RedisStateStore implements OidcStateStore
{
    public function __construct(private readonly string $prefix)
    {
    }

    public function get(string $key): ?string
    {
        $value = Redis::get($this->prefix . $key);

        return is_string($value) ? $value : null;
    }

    public function put(string $key, string $value, int $ttlSeconds): void
    {
        if ($ttlSeconds <= 0) {
            // No expiry. Used for a dynamic client registration, which has to
            // survive between sign-ins and is removed explicitly on logout.
            Redis::set($this->prefix . $key, $value);

            return;
        }

        // SETEX rather than SET followed by EXPIRE: applying the TTL in a second
        // command leaves a window in which the key would never expire.
        Redis::setex($this->prefix . $key, $ttlSeconds, $value);
    }

    public function forget(string $key): void
    {
        Redis::del($this->prefix . $key);
    }
}
