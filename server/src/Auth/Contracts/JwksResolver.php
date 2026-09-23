<?php

namespace Fleetbase\Solid\Auth\Contracts;

/**
 * Supplies the identity provider's JSON Web Key Set.
 *
 * Split out from the verifier so that verification itself is pure: the verifier
 * decides what a key must satisfy, the resolver decides how the document is
 * fetched and cached.
 */
interface JwksResolver
{
    /**
     * The decoded JWKS document for a jwks_uri.
     *
     * @param bool $forceRefresh bypass any cache; used once when a token names a
     *                           key id the cached document does not contain, which
     *                           is what a legitimate key rotation looks like
     *
     * @return array<string, mixed>
     *
     * @throws \Fleetbase\Solid\Exceptions\OpenIDConnectClientException
     */
    public function resolve(string $jwksUri, bool $forceRefresh = false): array;
}
