<?php

namespace Fleetbase\Solid\Tests\Unit;

use Fleetbase\Solid\Auth\DPoPKeyPair;
use PHPUnit\Framework\TestCase;

/**
 * DPoP proofs are what make a Solid access token usable, so the shape of the
 * proof and the thumbprint the token is bound to are both asserted against the
 * RFCs rather than against the previous implementation's output.
 */
class DPoPKeyPairTest extends TestCase
{
    private static DPoPKeyPair $keyPair;

    public static function setUpBeforeClass(): void
    {
        // 2048-bit keygen is slow enough to be worth doing once.
        self::$keyPair = DPoPKeyPair::generate();
    }

    /**
     * @return array<string, mixed>
     */
    private function segment(string $jwt, int $index): array
    {
        $parts = explode('.', $jwt);

        return (array) json_decode((string) base64_decode(strtr($parts[$index], '-_', '+/'), true), true);
    }

    public function testItGeneratesAnRsaPublicJwk(): void
    {
        $jwk = self::$keyPair->publicJwk();

        $this->assertSame('RSA', $jwk['kty']);
        $this->assertNotSame('', $jwk['n']);
        $this->assertSame('AQAB', $jwk['e']);
        $this->assertStringNotContainsString('=', $jwk['n'], 'JWK members must be unpadded base64url.');
        $this->assertStringNotContainsString('+', $jwk['n']);
        $this->assertStringNotContainsString('/', $jwk['n']);
    }

    public function testItRoundTripsThroughItsPersistableForm(): void
    {
        $restored = DPoPKeyPair::fromArray(self::$keyPair->toArray());

        $this->assertInstanceOf(DPoPKeyPair::class, $restored);
        // The same key must produce the same binding, or every token bound to it
        // would be rejected after a restart.
        $this->assertSame(self::$keyPair->thumbprint(), $restored->thumbprint());
    }

    public function testItDeclinesToRestoreAnIncompleteStoredKey(): void
    {
        // A truncated record must produce a fresh key, not a broken one.
        $this->assertNull(DPoPKeyPair::fromArray(null));
        $this->assertNull(DPoPKeyPair::fromArray([]));
        $this->assertNull(DPoPKeyPair::fromArray(['private_key' => 'x']));
        $this->assertNull(DPoPKeyPair::fromArray(['private_key' => 'x', 'public_jwk' => ['kty' => 'RSA']]));
    }

    public function testTheThumbprintIsTheRfc7638HashOfTheCanonicalJwk(): void
    {
        $jwk = self::$keyPair->publicJwk();

        // RFC 7638: required members only, lexicographic order, no whitespace.
        $expected = rtrim(strtr(base64_encode(hash('sha256', (string) json_encode([
            'e'   => $jwk['e'],
            'kty' => $jwk['kty'],
            'n'   => $jwk['n'],
        ], JSON_UNESCAPED_SLASHES), true)), '+/', '-_'), '=');

        $this->assertSame($expected, self::$keyPair->thumbprint());
    }

    public function testItSignsAProofWithTheHeadersRfc9449Requires(): void
    {
        $proof  = self::$keyPair->proof('post', 'https://pod.example.test/token');
        $header = $this->segment($proof, 0);

        $this->assertSame('dpop+jwt', $header['typ']);
        $this->assertSame('RS256', $header['alg']);
        $this->assertSame(self::$keyPair->publicJwk(), $header['jwk']);
    }

    public function testItBindsTheProofToTheMethodAndUri(): void
    {
        $claims = $this->segment(self::$keyPair->proof('post', 'https://pod.example.test/token'), 1);

        $this->assertSame('POST', $claims['htm'], 'htm is upper-case regardless of how the method was given.');
        $this->assertSame('https://pod.example.test/token', $claims['htu']);
        $this->assertLessThanOrEqual(time(), $claims['iat']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $claims['jti']);
    }

    public function testItStripsTheQueryAndFragmentFromHtu(): void
    {
        // RFC 9449 4.2: htu carries no query or fragment. Leaving them in makes
        // the proof unusable against a server that compares htu strictly.
        $claims = $this->segment(
            self::$keyPair->proof('GET', 'https://pod.example.test/alice/card?a=1&b=2#me'),
            1
        );

        $this->assertSame('https://pod.example.test/alice/card', $claims['htu']);
    }

    public function testItOmitsAthWhenThereIsNoAccessToken(): void
    {
        // The token endpoint is called before any access token exists.
        $this->assertArrayNotHasKey('ath', $this->segment(self::$keyPair->proof('POST', 'https://pod.example.test/token'), 1));
    }

    public function testItAddsAthAsTheHashOfTheAccessToken(): void
    {
        $accessToken = 'an-access-token';
        $claims      = $this->segment(self::$keyPair->proof('GET', 'https://pod.example.test/alice/card', $accessToken), 1);

        $this->assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $accessToken, true)), '+/', '-_'), '='),
            $claims['ath']
        );
    }

    public function testItEchoesAServerSuppliedNonce(): void
    {
        $claims = $this->segment(self::$keyPair->proof('POST', 'https://pod.example.test/token', null, 'server-nonce'), 1);

        $this->assertSame('server-nonce', $claims['nonce']);
    }

    public function testEachProofCarriesAFreshJti(): void
    {
        // Replay protection depends on this.
        $first  = $this->segment(self::$keyPair->proof('GET', 'https://pod.example.test/a'), 1);
        $second = $this->segment(self::$keyPair->proof('GET', 'https://pod.example.test/a'), 1);

        $this->assertNotSame($first['jti'], $second['jti']);
    }

    public function testTheProofSignatureVerifiesAgainstThePublicKey(): void
    {
        $proof = self::$keyPair->proof('POST', 'https://pod.example.test/token');

        [$header, $payload, $signature] = explode('.', $proof);

        $publicKey = openssl_pkey_get_details(openssl_pkey_get_private(self::$keyPair->toArray()['private_key']))['key'];

        $this->assertSame(1, openssl_verify(
            $header . '.' . $payload,
            (string) base64_decode(strtr($signature, '-_', '+/'), true),
            $publicKey,
            OPENSSL_ALGO_SHA256
        ));
    }

    public function testATamperedProofDoesNotVerify(): void
    {
        $proof = self::$keyPair->proof('GET', 'https://pod.example.test/alice/card');

        [$header, , $signature] = explode('.', $proof);

        // Swap the payload for one naming a different URI.
        $forged = rtrim(strtr(base64_encode((string) json_encode([
            'jti' => bin2hex(random_bytes(16)),
            'htm' => 'GET',
            'htu' => 'https://attacker.example.test/',
            'iat' => time(),
        ])), '+/', '-_'), '=');

        $publicKey = openssl_pkey_get_details(openssl_pkey_get_private(self::$keyPair->toArray()['private_key']))['key'];

        $this->assertSame(0, openssl_verify(
            $header . '.' . $forged,
            (string) base64_decode(strtr($signature, '-_', '+/'), true),
            $publicKey,
            OPENSSL_ALGO_SHA256
        ));
    }

    public function testDistinctKeyPairsHaveDistinctThumbprints(): void
    {
        $this->assertNotSame(self::$keyPair->thumbprint(), DPoPKeyPair::generate()->thumbprint());
    }
}
