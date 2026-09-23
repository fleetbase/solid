<?php

namespace Fleetbase\Solid\Auth;

use Fleetbase\Solid\Exceptions\OpenIDConnectClientException;

/**
 * The RSA key pair Fleetbase proves possession of, and the DPoP proofs it signs
 * with it (RFC 9449).
 *
 * Solid-OIDC does not issue bearer tokens: the access token is bound to a public
 * key, and every request that carries it must also carry a fresh proof signed by
 * the matching private key. That makes this class part of the security boundary,
 * so it is kept free of framework calls and is unit tested directly.
 *
 * The key is per Solid identity and is persisted by the caller. Rotating it
 * invalidates every access token already bound to it, which is why
 * `OpenIDConnectClient::clearClientCredentials()` discards the tokens alongside
 * the key.
 */
final class DPoPKeyPair
{
    /**
     * The only signing algorithm used for proofs. RSASSA-PKCS1-v1_5 with SHA-256
     * is what `openssl_sign(..., OPENSSL_ALGO_SHA256)` produces, and it is on
     * every Solid server's `dpop_signing_alg_values_supported`.
     */
    private const ALGORITHM = 'RS256';

    /**
     * @param array{kty: string, n: string, e: string} $publicJwk
     */
    private function __construct(
        private readonly string $privateKeyPem,
        private readonly string $publicKeyPem,
        private readonly array $publicJwk,
    ) {
    }

    /**
     * Mint a new 2048-bit RSA key pair.
     *
     * @throws OpenIDConnectClientException
     */
    public static function generate(): self
    {
        $resource = openssl_pkey_new([
            'digest_alg'       => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($resource === false) {
            throw new OpenIDConnectClientException('Unable to generate a DPoP key pair.');
        }

        $privateKeyPem = '';

        if (!openssl_pkey_export($resource, $privateKeyPem)) {
            throw new OpenIDConnectClientException('Unable to export the DPoP private key.');
        }

        $details = openssl_pkey_get_details($resource);

        if (!is_array($details) || !isset($details['key'], $details['rsa']['n'], $details['rsa']['e'])) {
            throw new OpenIDConnectClientException('Unable to read the generated DPoP public key.');
        }

        return new self($privateKeyPem, (string) $details['key'], [
            'kty' => 'RSA',
            'n'   => self::base64UrlEncode((string) $details['rsa']['n']),
            'e'   => self::base64UrlEncode((string) $details['rsa']['e']),
        ]);
    }

    /**
     * Rehydrate a key pair previously produced by `toArray()`.
     *
     * Returns null rather than throwing for anything it does not recognise: a
     * truncated or older stored shape should cause a fresh key to be generated,
     * not a failed request.
     *
     * @param array<string, mixed>|null $stored
     */
    public static function fromArray(?array $stored): ?self
    {
        $privateKeyPem = $stored['private_key'] ?? null;
        $publicKeyPem  = $stored['public_key'] ?? null;
        $publicJwk     = $stored['public_jwk'] ?? null;

        if (!is_string($privateKeyPem) || $privateKeyPem === '' || !is_array($publicJwk)) {
            return null;
        }

        if (!isset($publicJwk['kty'], $publicJwk['n'], $publicJwk['e'])) {
            return null;
        }

        return new self(
            $privateKeyPem,
            is_string($publicKeyPem) ? $publicKeyPem : '',
            [
                'kty' => (string) $publicJwk['kty'],
                'n'   => (string) $publicJwk['n'],
                'e'   => (string) $publicJwk['e'],
            ]
        );
    }

    /**
     * The persistable form. Contains the private key, so the caller must store it
     * somewhere only the application can read.
     *
     * @return array{private_key: string, public_key: string, public_jwk: array{kty: string, n: string, e: string}}
     */
    public function toArray(): array
    {
        return [
            'private_key' => $this->privateKeyPem,
            'public_key'  => $this->publicKeyPem,
            'public_jwk'  => $this->publicJwk,
        ];
    }

    /**
     * @return array{kty: string, n: string, e: string}
     */
    public function publicJwk(): array
    {
        return $this->publicJwk;
    }

    /**
     * The RFC 7638 JWK thumbprint of the public key.
     *
     * This is the value a Solid server puts in the access token's `cnf.jkt`
     * claim. Comparing the two is how we detect that a token was bound to a
     * different key than the one we hold — see
     * `OpenIDConnectClient::assertTokenBoundToDPoPKey()`.
     */
    public function thumbprint(): string
    {
        // RFC 7638 requires the required members only, lexicographically ordered,
        // with no whitespace: for RSA that is exactly e, kty, n.
        $canonical = json_encode([
            'e'   => $this->publicJwk['e'],
            'kty' => $this->publicJwk['kty'],
            'n'   => $this->publicJwk['n'],
        ], JSON_UNESCAPED_SLASHES);

        return self::base64UrlEncode(hash('sha256', (string) $canonical, true));
    }

    /**
     * Sign a DPoP proof for one request.
     *
     * @param string      $method      the HTTP method the proof is bound to
     * @param string      $url         the HTTP URI the proof is bound to; query and
     *                                 fragment are stripped, as RFC 9449 §4.2 requires
     * @param string|null $accessToken when present, adds the `ath` claim binding the
     *                                 proof to that access token. Omitted for the
     *                                 token endpoint, where no access token exists yet.
     * @param string|null $nonce       a server-supplied DPoP nonce, echoed back when
     *                                 the provider demands one
     *
     * @throws OpenIDConnectClientException
     */
    public function proof(string $method, string $url, ?string $accessToken = null, ?string $nonce = null): string
    {
        $header = [
            'typ' => 'dpop+jwt',
            'alg' => self::ALGORITHM,
            'jwk' => $this->publicJwk,
        ];

        $payload = [
            'jti' => bin2hex(random_bytes(16)),
            'htm' => strtoupper($method),
            'htu' => self::normalizeHtu($url),
            'iat' => time(),
        ];

        if (is_string($accessToken) && $accessToken !== '') {
            $payload['ath'] = self::base64UrlEncode(hash('sha256', $accessToken, true));
        }

        if (is_string($nonce) && $nonce !== '') {
            $payload['nonce'] = $nonce;
        }

        $signingInput = self::base64UrlEncode((string) json_encode($header))
            . '.' . self::base64UrlEncode((string) json_encode($payload));

        $privateKey = openssl_pkey_get_private($this->privateKeyPem);

        if ($privateKey === false) {
            throw new OpenIDConnectClientException('Unable to load the DPoP private key.');
        }

        $signature = '';

        if (!openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new OpenIDConnectClientException('Unable to sign the DPoP proof.');
        }

        return $signingInput . '.' . self::base64UrlEncode($signature);
    }

    /**
     * RFC 9449 §4.2: `htu` is the request URI without query or fragment.
     *
     * Leaving a query string in place makes the proof unusable for any request
     * whose parameters differ, which is how the previous implementation broke
     * against servers that compare `htu` strictly.
     */
    private static function normalizeHtu(string $url): string
    {
        return explode('?', explode('#', $url, 2)[0], 2)[0];
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
