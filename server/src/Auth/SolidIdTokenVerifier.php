<?php

namespace Fleetbase\Solid\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Fleetbase\Solid\Auth\Contracts\JwksResolver;
use Fleetbase\Solid\Exceptions\OpenIDConnectClientException;

/**
 * Verifies a Solid-OIDC ID token and returns its claims.
 *
 * Solid previously had no ID-token verification at all on the callback path:
 * `OIDCController` called `exchangeCodeForTokens()`, which never reached
 * Jumbojett's `authenticate()`, so the signature, issuer, audience, expiry and
 * nonce were all unchecked and the WebID was read out of an unverified JWT. This
 * class closes that gap.
 *
 * It follows the same shape as Core API's `Fleetbase\Auth\OAuth\IdTokenVerifier`
 * — short-lived JWKS cache, resolve by `kid`, refetch once on an unknown `kid`,
 * TLS always on, claims returned rather than a boolean — with two deliberate
 * differences:
 *
 *   1. Signature checking goes through `firebase/php-jwt` rather than
 *      `lcobucci/jwt`. Core API's verifier hardcodes `Lcobucci\JWT\Signer\Rsa\Sha256`,
 *      i.e. RS256 only. A Solid identity provider is operator-chosen (the server
 *      URL is an admin setting), and `node-oidc-provider` — which Community Solid
 *      Server is built on — also offers PS256 and ES256. php-jwt resolves the
 *      algorithm from the JWK, so all three work without weakening anything.
 *   2. It validates `nonce`, which is a Solid-flow concern Core API's verifier
 *      has no reason to know about.
 *
 * Algorithm confusion is prevented structurally: `JWK::parseKeySet()` produces a
 * `Key` carrying the algorithm declared by the *key*, and `JWT::decode()` rejects
 * a token whose header algorithm differs from it. A token claiming `none`, or
 * claiming `HS256` so that the RSA public key is treated as an HMAC secret, is
 * therefore rejected before any signature is computed. `$allowedAlgorithms` is a
 * second, explicit gate on top of that.
 */
class SolidIdTokenVerifier
{
    /**
     * Asymmetric signature algorithms a Solid provider may legitimately use.
     *
     * Symmetric algorithms are absent on purpose. An HMAC-signed ID token would
     * be verified with the client secret, and Solid clients are registered
     * dynamically per identity — there is no scenario in this flow where that is
     * the right thing to accept.
     *
     * @var array<int, string>
     */
    public const DEFAULT_ALGORITHMS = ['RS256', 'RS384', 'RS512', 'PS256', 'PS384', 'PS512', 'ES256', 'ES384', 'ES512'];

    /**
     * @param array<int, string> $allowedAlgorithms
     * @param int                $leewaySeconds     clock skew tolerated on exp/iat/nbf
     */
    public function __construct(
        private readonly JwksResolver $jwks,
        private readonly array $allowedAlgorithms = self::DEFAULT_ALGORITHMS,
        private readonly int $leewaySeconds = 60,
        private readonly bool $requireNonce = true,
    ) {
    }

    /**
     * Verify an ID token against a provider and return its claims.
     *
     * @param string      $idToken       the raw compact JWS
     * @param string      $jwksUri       the provider's `jwks_uri`, from discovery
     * @param string      $issuer        the exact issuer the token must name; discovery's
     *                                   `issuer`, not a value taken from the token
     * @param string      $clientId      the audience the token must be addressed to
     * @param string|null $expectedNonce the nonce sent on the authorization request,
     *                                   or null when this token did not originate
     *                                   from one (e.g. a refresh)
     *
     * @return array<string, mixed> the verified claims
     *
     * @throws OpenIDConnectClientException
     */
    public function verify(string $idToken, string $jwksUri, string $issuer, string $clientId, ?string $expectedNonce = null): array
    {
        if ($idToken === '') {
            throw new OpenIDConnectClientException('The token response carried no id_token.');
        }

        if ($issuer === '') {
            throw new OpenIDConnectClientException('Refusing to verify an id_token without a known issuer.');
        }

        // An empty client id would make the audience check vacuous.
        if ($clientId === '') {
            throw new OpenIDConnectClientException('Refusing to verify an id_token without a registered client id.');
        }

        $keyId  = $this->keyIdFrom($idToken);
        $claims = $this->decode($idToken, $jwksUri, $keyId);

        $this->assertIssuer($claims, $issuer);
        $this->assertAudience($claims, $clientId);
        $this->assertSubject($claims);
        $this->assertNonce($claims, $expectedNonce);

        return $claims;
    }

    /**
     * Read the `kid` out of the (still unverified) JWS header.
     *
     * Nothing is trusted from this beyond picking which published key to check
     * the signature with — a forged `kid` simply fails to resolve.
     *
     * @throws OpenIDConnectClientException
     */
    private function keyIdFrom(string $idToken): string
    {
        $segments = explode('.', $idToken);

        if (count($segments) !== 3) {
            throw new OpenIDConnectClientException('The id_token is not a compact JWS.');
        }

        $header = json_decode((string) self::base64UrlDecode($segments[0]), true);

        if (!is_array($header)) {
            throw new OpenIDConnectClientException('The id_token header could not be decoded.');
        }

        $algorithm = $header['alg'] ?? null;

        // Rejected here as well as by php-jwt so the log names the real reason.
        if (!is_string($algorithm) || !in_array($algorithm, $this->allowedAlgorithms, true)) {
            throw new OpenIDConnectClientException('The id_token is signed with an unsupported algorithm.');
        }

        $keyId = $header['kid'] ?? null;

        if (!is_string($keyId) || $keyId === '') {
            throw new OpenIDConnectClientException('The id_token header carries no kid.');
        }

        return $keyId;
    }

    /**
     * Check the signature and the time-based claims.
     *
     * A `kid` missing from the cached JWKS is indistinguishable from a rotated
     * key, so the document is refetched once before the token is rejected. This
     * mirrors the cache-busting in Core API's `IdTokenVerifier::resolvePublicKey()`.
     *
     * @return array<string, mixed>
     *
     * @throws OpenIDConnectClientException
     */
    private function decode(string $idToken, string $jwksUri, string $keyId): array
    {
        $keys = $this->parseKeySet($this->jwks->resolve($jwksUri));

        if (!isset($keys[$keyId])) {
            $keys = $this->parseKeySet($this->jwks->resolve($jwksUri, true));
        }

        if (!isset($keys[$keyId])) {
            throw new OpenIDConnectClientException('The id_token names a signing key the provider does not publish.');
        }

        // Applies to exp, iat and nbf inside php-jwt. Static rather than injected
        // because php-jwt exposes it only as a static property.
        JWT::$leeway = $this->leewaySeconds;

        try {
            $claims = JWT::decode($idToken, $keys[$keyId]);
        } catch (\Throwable $e) {
            // Signature, expiry or structure. Which one is an operator detail, and
            // the token itself is deliberately kept out of the message.
            throw new OpenIDConnectClientException('The id_token failed verification: ' . $e->getMessage(), 0, $e);
        }

        // php-jwt hands back a stdClass tree; re-decoding is the least surprising
        // way to get plain arrays all the way down.
        $decoded = json_decode((string) json_encode($claims), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $jwks
     *
     * @return array<string, \Firebase\JWT\Key>
     *
     * @throws OpenIDConnectClientException
     */
    private function parseKeySet(array $jwks): array
    {
        try {
            // No default algorithm is supplied: a key that does not declare `alg`
            // is dropped rather than assumed to be RS256. Core API passes 'RS256'
            // here because Microsoft publishes keys without one; Solid providers
            // built on node-oidc-provider always declare it, and guessing would
            // reopen the algorithm-confusion hole this class exists to close.
            return JWK::parseKeySet($jwks);
        } catch (\Throwable $e) {
            throw new OpenIDConnectClientException('The provider JWKS could not be read: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @throws OpenIDConnectClientException
     */
    private function assertIssuer(array $claims, string $issuer): void
    {
        $tokenIssuer = $claims['iss'] ?? null;

        // Exact comparison. Solid has no multi-tenant issuer pattern of the kind
        // that forces Core API to take a predicate here.
        if (!is_string($tokenIssuer) || !hash_equals($issuer, $tokenIssuer)) {
            throw new OpenIDConnectClientException('The id_token was issued by an unexpected issuer.');
        }
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @throws OpenIDConnectClientException
     */
    private function assertAudience(array $claims, string $clientId): void
    {
        $audience  = $claims['aud'] ?? null;
        $audiences = is_array($audience) ? $audience : [$audience];
        $matched   = false;

        foreach ($audiences as $candidate) {
            if (is_string($candidate) && hash_equals($clientId, $candidate)) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            throw new OpenIDConnectClientException('The id_token is not addressed to this client.');
        }

        // OIDC Core 3.1.3.7: when `aud` names more than one party, `azp` must be
        // present and must be us. Without this a token minted for another client
        // that happens to list us as an audience would be accepted.
        if (count($audiences) > 1) {
            $authorizedParty = $claims['azp'] ?? null;

            if (!is_string($authorizedParty) || !hash_equals($clientId, $authorizedParty)) {
                throw new OpenIDConnectClientException('The id_token has multiple audiences and no azp naming this client.');
            }
        }
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @throws OpenIDConnectClientException
     */
    private function assertSubject(array $claims): void
    {
        $subject = $claims['sub'] ?? null;

        if (!is_string($subject) || $subject === '') {
            throw new OpenIDConnectClientException('The id_token carries no subject.');
        }
    }

    /**
     * @param array<string, mixed> $claims
     *
     * @throws OpenIDConnectClientException
     */
    private function assertNonce(array $claims, ?string $expectedNonce): void
    {
        if ($expectedNonce === null || $expectedNonce === '') {
            // No nonce was sent, so there is nothing to bind the token to. Only
            // reachable for flows that do not start at an authorization request.
            return;
        }

        $tokenNonce = $claims['nonce'] ?? null;

        if (!is_string($tokenNonce) || $tokenNonce === '') {
            if (!$this->requireNonce) {
                return;
            }

            throw new OpenIDConnectClientException('The id_token echoed no nonce for an authorization request that sent one.');
        }

        if (!hash_equals($expectedNonce, $tokenNonce)) {
            throw new OpenIDConnectClientException('The id_token nonce does not match this authorization request.');
        }
    }

    private static function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
