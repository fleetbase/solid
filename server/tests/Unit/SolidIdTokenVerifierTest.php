<?php

namespace Fleetbase\Solid\Tests\Unit;

use Fleetbase\Solid\Auth\SolidIdTokenVerifier;
use Fleetbase\Solid\Exceptions\OpenIDConnectClientException;
use Fleetbase\Solid\Tests\Support\ArrayJwksResolver;
use Fleetbase\Solid\Tests\Support\TestSigner;
use PHPUnit\Framework\TestCase;

/**
 * The checks in here are the ones the Solid callback previously performed none
 * of. Each test states the attack or mistake it rules out.
 */
class SolidIdTokenVerifierTest extends TestCase
{
    private const ISSUER    = 'https://pod.example.test';
    private const CLIENT_ID = 'client-abc';
    private const JWKS_URI  = 'https://pod.example.test/.oidc/jwks';

    private function verifier(?ArrayJwksResolver $resolver = null, bool $requireNonce = true): SolidIdTokenVerifier
    {
        return new SolidIdTokenVerifier(
            $resolver ?? new ArrayJwksResolver(TestSigner::jwks([['key-1']])),
            SolidIdTokenVerifier::DEFAULT_ALGORITHMS,
            60,
            $requireNonce
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function verify(string $jwt, ?string $nonce = 'nonce-value', ?ArrayJwksResolver $resolver = null, bool $requireNonce = true): array
    {
        return $this->verifier($resolver, $requireNonce)->verify($jwt, self::JWKS_URI, self::ISSUER, self::CLIENT_ID, $nonce);
    }

    public function testItAcceptsAValidRs256TokenAndReturnsItsClaims(): void
    {
        $claims = $this->verify(TestSigner::sign(TestSigner::claims()));

        $this->assertSame(self::ISSUER, $claims['iss']);
        $this->assertSame('https://pod.example.test/alice/profile/card#me', $claims['webid']);
    }

    public function testItAcceptsAnEs256Token(): void
    {
        // A Solid provider may sign with EC. Core API's verifier is hardcoded to
        // RS256, which is the reason this class does not reuse it.
        $resolver = new ArrayJwksResolver(TestSigner::jwks([['ec-key', 'ES256']]));
        $jwt      = TestSigner::sign(TestSigner::claims(), 'ec-key', 'ES256');

        $this->assertSame(self::ISSUER, $this->verify($jwt, 'nonce-value', $resolver)['iss']);
    }

    public function testItRejectsATokenWhoseSignatureDoesNotMatchTheNamedKey(): void
    {
        $resolver = new ArrayJwksResolver(TestSigner::jwks([['key-1'], ['key-2']]));
        $jwt      = TestSigner::signWithWrongKey(TestSigner::claims(), 'key-1', 'key-2');

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/failed verification/');

        $this->verify($jwt, 'nonce-value', $resolver);
    }

    public function testItRejectsAnExpiredToken(): void
    {
        // Beyond the 60s leeway the verifier is configured with.
        $jwt = TestSigner::sign(TestSigner::claims(['exp' => time() - 3600, 'iat' => time() - 7200]));

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/failed verification/');

        $this->verify($jwt);
    }

    public function testItAcceptsATokenThatExpiredInsideTheLeeway(): void
    {
        $jwt = TestSigner::sign(TestSigner::claims(['exp' => time() - 10]));

        $this->assertSame(self::ISSUER, $this->verify($jwt)['iss']);
    }

    public function testItRejectsATokenThatIsNotYetValid(): void
    {
        $jwt = TestSigner::sign(TestSigner::claims(['nbf' => time() + 3600]));

        $this->expectException(OpenIDConnectClientException::class);

        $this->verify($jwt);
    }

    public function testItRejectsAnUnexpectedIssuer(): void
    {
        $jwt = TestSigner::sign(TestSigner::claims(['iss' => 'https://attacker.example.test']));

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/unexpected issuer/');

        $this->verify($jwt);
    }

    public function testItRejectsAnIssuerThatOnlyPrefixesTheExpectedOne(): void
    {
        $jwt = TestSigner::sign(TestSigner::claims(['iss' => self::ISSUER . '.attacker.test']));

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/unexpected issuer/');

        $this->verify($jwt);
    }

    public function testItRejectsATokenAddressedToAnotherClient(): void
    {
        $jwt = TestSigner::sign(TestSigner::claims(['aud' => 'some-other-client']));

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/not addressed to this client/');

        $this->verify($jwt);
    }

    public function testItAcceptsAnAudienceArrayContainingThisClientWithAMatchingAzp(): void
    {
        $jwt = TestSigner::sign(TestSigner::claims([
            'aud' => [self::CLIENT_ID, 'another-client'],
            'azp' => self::CLIENT_ID,
        ]));

        $this->assertSame(self::ISSUER, $this->verify($jwt)['iss']);
    }

    public function testItRejectsMultipleAudiencesWithoutAnAzpNamingThisClient(): void
    {
        // OIDC Core 3.1.3.7. Without this, a token minted for another client that
        // happens to list us as an audience would be accepted.
        $jwt = TestSigner::sign(TestSigner::claims([
            'aud' => [self::CLIENT_ID, 'another-client'],
            'azp' => 'another-client',
        ]));

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/azp/');

        $this->verify($jwt);
    }

    public function testItRejectsATokenWithNoSubject(): void
    {
        $claims = TestSigner::claims();
        unset($claims['sub']);

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/no subject/');

        $this->verify(TestSigner::sign($claims));
    }

    public function testItRejectsAMismatchedNonce(): void
    {
        $jwt = TestSigner::sign(TestSigner::claims(['nonce' => 'some-other-nonce']));

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/nonce does not match/');

        $this->verify($jwt);
    }

    public function testItRejectsAMissingNonceWhenOneWasSent(): void
    {
        $claims = TestSigner::claims();
        unset($claims['nonce']);

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/echoed no nonce/');

        $this->verify(TestSigner::sign($claims));
    }

    public function testItToleratesAMissingNonceWhenTheRequirementIsSwitchedOff(): void
    {
        $claims = TestSigner::claims();
        unset($claims['nonce']);

        $verified = $this->verify(TestSigner::sign($claims), 'nonce-value', null, false);

        $this->assertSame(self::ISSUER, $verified['iss']);
    }

    public function testItRejectsAnUnsignedToken(): void
    {
        // `alg: none` must never be treated as a signature.
        $jwt = TestSigner::unsigned(['typ' => 'JWT', 'alg' => 'none', 'kid' => 'key-1'], TestSigner::claims());

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/unsupported algorithm/');

        $this->verify($jwt);
    }

    public function testItRejectsAnHmacTokenSignedWithTheRsaPublicKey(): void
    {
        // The algorithm-confusion attack: the header claims HS256 so that the
        // published RSA public key is used as a shared secret.
        $jwt = TestSigner::hmacSignedWithPublicKey(TestSigner::claims());

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/unsupported algorithm/');

        $this->verify($jwt);
    }

    public function testItRejectsATokenWithNoKeyId(): void
    {
        $jwt = TestSigner::unsigned(['typ' => 'JWT', 'alg' => 'RS256'], TestSigner::claims(), 'not-a-signature');

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/no kid/');

        $this->verify($jwt);
    }

    public function testItRejectsAMalformedToken(): void
    {
        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/not a compact JWS/');

        $this->verify('not.a-jwt');
    }

    public function testItRejectsATokenWithAnUndecodableHeader(): void
    {
        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/header could not be decoded/');

        $this->verify('###.###.###');
    }

    public function testItRefetchesTheJwksOnceForAnUnknownKeyId(): void
    {
        // A rotated signing key looks exactly like a forged `kid`, so the cached
        // document is dropped and refetched before the token is judged.
        $resolver = new ArrayJwksResolver(
            TestSigner::jwks([['key-1']]),
            TestSigner::jwks([['key-1'], ['key-2']])
        );

        $claims = $this->verify(TestSigner::sign(TestSigner::claims(), 'key-2'), 'nonce-value', $resolver);

        $this->assertSame(self::ISSUER, $claims['iss']);
        $this->assertSame(1, $resolver->refreshCount);
    }

    public function testItRejectsAKeyIdThatIsStillUnknownAfterARefetch(): void
    {
        $resolver = new ArrayJwksResolver(TestSigner::jwks([['key-1']]));

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/does not publish/');

        try {
            $this->verify(TestSigner::sign(TestSigner::claims(), 'key-9'), 'nonce-value', $resolver);
        } finally {
            // Exactly one retry, not a loop against the provider.
            $this->assertSame(1, $resolver->refreshCount);
        }
    }

    public function testItRefusesToVerifyWithoutAKnownIssuer(): void
    {
        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/without a known issuer/');

        $this->verifier()->verify(TestSigner::sign(TestSigner::claims()), self::JWKS_URI, '', self::CLIENT_ID, null);
    }

    public function testItRefusesToVerifyWithoutAClientId(): void
    {
        // An empty audience would make the audience check vacuous.
        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/without a registered client id/');

        $this->verifier()->verify(TestSigner::sign(TestSigner::claims()), self::JWKS_URI, self::ISSUER, '', null);
    }

    public function testItRejectsAnEmptyToken(): void
    {
        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/no id_token/');

        $this->verify('');
    }
}
