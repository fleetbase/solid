<?php

namespace Fleetbase\Solid\Tests\Unit;

use Fleetbase\Solid\Auth\CachedJwksResolver;
use Fleetbase\Solid\Exceptions\OpenIDConnectClientException;
use Fleetbase\Solid\Tests\Support\ArrayOidcTransport;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/**
 * The JWKS cache sits directly on the ID-token verification path: too long a TTL
 * locks users out through a key rotation, and no refresh path makes a rotation
 * indistinguishable from a forgery.
 */
class CachedJwksResolverTest extends TestCase
{
    private const JWKS_URI = 'https://pod.example.test/.oidc/jwks';

    private ArrayOidcTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $container->instance('cache', new Repository(new ArrayStore()));

        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        $this->transport = new ArrayOidcTransport();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function testItFetchesAndReturnsTheJwks(): void
    {
        $this->transport->queue(self::JWKS_URI, ['keys' => [['kid' => 'key-1']]]);

        $jwks = (new CachedJwksResolver($this->transport))->resolve(self::JWKS_URI);

        $this->assertSame([['kid' => 'key-1']], $jwks['keys']);
    }

    public function testItServesASecondCallFromTheCache(): void
    {
        $this->transport->queue(self::JWKS_URI, ['keys' => []]);

        $resolver = new CachedJwksResolver($this->transport);
        $resolver->resolve(self::JWKS_URI);
        $resolver->resolve(self::JWKS_URI);

        $this->assertSame(1, $this->transport->countRequestsTo(self::JWKS_URI));
    }

    public function testAForcedRefreshBypassesTheCache(): void
    {
        // This is what makes a rotated signing key usable before the TTL lapses.
        $this->transport->queue(self::JWKS_URI, ['keys' => [['kid' => 'key-1']]]);
        $this->transport->queue(self::JWKS_URI, ['keys' => [['kid' => 'key-2']]]);

        $resolver = new CachedJwksResolver($this->transport);

        $this->assertSame([['kid' => 'key-1']], $resolver->resolve(self::JWKS_URI)['keys']);
        $this->assertSame([['kid' => 'key-2']], $resolver->resolve(self::JWKS_URI, true)['keys']);
        $this->assertSame(2, $this->transport->countRequestsTo(self::JWKS_URI));
    }

    public function testARefreshedDocumentReplacesTheCachedOne(): void
    {
        $this->transport->queue(self::JWKS_URI, ['keys' => [['kid' => 'key-1']]]);
        $this->transport->queue(self::JWKS_URI, ['keys' => [['kid' => 'key-2']]]);

        $resolver = new CachedJwksResolver($this->transport);
        $resolver->resolve(self::JWKS_URI);
        $resolver->resolve(self::JWKS_URI, true);

        $this->assertSame([['kid' => 'key-2']], $resolver->resolve(self::JWKS_URI)['keys']);
    }

    public function testDocumentsForDifferentProvidersDoNotShareACacheEntry(): void
    {
        $other = 'https://other.example.test/.oidc/jwks';

        $this->transport->queue(self::JWKS_URI, ['keys' => [['kid' => 'mine']]]);
        $this->transport->queue($other, ['keys' => [['kid' => 'theirs']]]);

        $resolver = new CachedJwksResolver($this->transport);

        $this->assertSame([['kid' => 'mine']], $resolver->resolve(self::JWKS_URI)['keys']);
        $this->assertSame([['kid' => 'theirs']], $resolver->resolve($other)['keys']);
    }

    public function testTheDefaultTtlIsShortEnoughToSurviveAKeyRotation(): void
    {
        // Matches Fleetbase\Auth\OAuth\IdTokenVerifier::JWKS_CACHE_SECONDS.
        $this->assertSame(300, CachedJwksResolver::DEFAULT_TTL_SECONDS);
    }

    public function testItRejectsAJwksDocumentThatIsNotJson(): void
    {
        $this->transport->queueRaw(self::JWKS_URI, 'not json');

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/Expected a JSON object/');

        (new CachedJwksResolver($this->transport))->resolve(self::JWKS_URI);
    }
}
