<?php

namespace Fleetbase\Solid\Tests\Support;

use Firebase\JWT\JWT;

/**
 * Mints signing keys, JWKS documents and signed (or deliberately broken) JWTs so
 * the ID token verifier can be tested against real cryptography rather than a
 * mock.
 *
 * Keys are generated once per process and reused: a 2048-bit RSA keygen is slow
 * enough that doing it per test case is noticeable.
 */
final class TestSigner
{
    /**
     * @var array<string, array{private: string, jwk: array<string, string>}>
     */
    private static array $keys = [];

    /**
     * An RSA or EC key for a key id, generated on first use.
     *
     * @param 'RS256'|'ES256' $algorithm
     *
     * @return array{private: string, jwk: array<string, string>}
     */
    public static function key(string $kid, string $algorithm = 'RS256'): array
    {
        $cacheKey = $kid . ':' . $algorithm;

        if (isset(self::$keys[$cacheKey])) {
            return self::$keys[$cacheKey];
        }

        $resource = openssl_pkey_new($algorithm === 'ES256'
            ? ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']
            : ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);

        if ($resource === false) {
            throw new \RuntimeException('Unable to generate a test key: ' . openssl_error_string());
        }

        $privateKey = '';
        openssl_pkey_export($resource, $privateKey);

        $details = openssl_pkey_get_details($resource);

        if (!is_array($details)) {
            throw new \RuntimeException('Unable to read the generated test key.');
        }

        $jwk = $algorithm === 'ES256'
            ? [
                'kty' => 'EC',
                'crv' => 'P-256',
                'x'   => self::b64(str_pad((string) $details['ec']['x'], 32, "\0", STR_PAD_LEFT)),
                'y'   => self::b64(str_pad((string) $details['ec']['y'], 32, "\0", STR_PAD_LEFT)),
            ]
            : [
                'kty' => 'RSA',
                'n'   => self::b64((string) $details['rsa']['n']),
                'e'   => self::b64((string) $details['rsa']['e']),
            ];

        return self::$keys[$cacheKey] = [
            'private' => $privateKey,
            'jwk'     => $jwk + ['kid' => $kid, 'alg' => $algorithm, 'use' => 'sig'],
        ];
    }

    /**
     * A JWKS document containing the named keys.
     *
     * @param array<int, array{0: string, 1?: string}> $keys pairs of [kid, algorithm]
     *
     * @return array{keys: array<int, array<string, string>>}
     */
    public static function jwks(array $keys): array
    {
        return [
            'keys' => array_map(
                static fn (array $key): array => self::key($key[0], $key[1] ?? 'RS256')['jwk'],
                $keys
            ),
        ];
    }

    /**
     * Sign a token with a named key.
     *
     * @param array<string, mixed> $claims
     * @param 'RS256'|'ES256'      $algorithm
     */
    public static function sign(array $claims, string $kid = 'key-1', string $algorithm = 'RS256'): string
    {
        return JWT::encode($claims, self::key($kid, $algorithm)['private'], $algorithm, $kid);
    }

    /**
     * Sign with one key while claiming to be another, so the signature is valid
     * but does not match the key the verifier will resolve.
     *
     * @param array<string, mixed> $claims
     */
    public static function signWithWrongKey(array $claims, string $claimedKid, string $actualKid): string
    {
        $jwt = JWT::encode($claims, self::key($actualKid)['private'], 'RS256', $actualKid);

        // Rewrite only the `kid` so the header still says RS256 and the verifier
        // fetches the wrong public key.
        [, $payload, $signature] = explode('.', $jwt);

        $header = self::b64((string) json_encode(['typ' => 'JWT', 'alg' => 'RS256', 'kid' => $claimedKid]));

        return $header . '.' . $payload . '.' . $signature;
    }

    /**
     * A token with a header and payload but no real signature, for the `alg: none`
     * and algorithm-confusion cases.
     *
     * @param array<string, mixed> $header
     * @param array<string, mixed> $claims
     */
    public static function unsigned(array $header, array $claims, string $signature = ''): string
    {
        return self::b64((string) json_encode($header))
            . '.' . self::b64((string) json_encode($claims))
            . '.' . $signature;
    }

    /**
     * An HMAC-signed token whose secret is the RSA public key — the classic
     * algorithm-confusion attack.
     *
     * @param array<string, mixed> $claims
     */
    public static function hmacSignedWithPublicKey(array $claims, string $kid = 'key-1'): string
    {
        $details   = openssl_pkey_get_details(openssl_pkey_get_private(self::key($kid)['private']));
        $publicPem = is_array($details) ? (string) $details['key'] : '';

        return JWT::encode($claims, $publicPem, 'HS256', $kid);
    }

    /**
     * Claims for a token that should pass every check.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function claims(array $overrides = []): array
    {
        return array_merge([
            'iss'   => 'https://pod.example.test',
            'aud'   => 'client-abc',
            'sub'   => 'https://pod.example.test/alice/profile/card#me',
            'webid' => 'https://pod.example.test/alice/profile/card#me',
            'nonce' => 'nonce-value',
            'iat'   => time() - 5,
            'exp'   => time() + 600,
        ], $overrides);
    }

    private static function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
