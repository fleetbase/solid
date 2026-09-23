<?php

namespace Fleetbase\Solid\Tests\Unit;

use Fleetbase\Solid\Auth\RedisStateStore;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

/**
 * The Redis writes, asserted through a recording fake.
 *
 * Worth testing directly because of the TTL contract: a zero TTL means "no
 * expiry" for a dynamic client registration, and a store that turned that into
 * `SETEX ... 1` would throw away every registration a second after saving it.
 */
class RedisStateStoreTest extends TestCase
{
    /**
     * @var array<int, array{0: string, 1: array<int, mixed>}>
     */
    private array $calls = [];

    private RedisStateStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $calls = &$this->calls;

        $redis = new class($calls) {
            /**
             * @param array<int, array{0: string, 1: array<int, mixed>}> $calls
             */
            public function __construct(private array &$calls)
            {
            }

            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $method, array $arguments): mixed
            {
                $this->calls[] = [$method, $arguments];

                return $method === 'get' ? 'a-stored-value' : true;
            }
        };

        $container = new Container();
        $container->instance('redis', $redis);

        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        $this->store = new RedisStateStore('oidc:an-identity-uuid:');
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function testItNamespacesEveryKeyWithItsPrefix(): void
    {
        // One identity must not be able to read another's pending authorization.
        $this->store->get('state');

        $this->assertSame(['get', ['oidc:an-identity-uuid:state']], $this->calls[0]);
    }

    public function testItWritesAPositiveTtlWithSetex(): void
    {
        $this->store->put('state', 'a-state', 600);

        $this->assertSame(['setex', ['oidc:an-identity-uuid:state', 600, 'a-state']], $this->calls[0]);
    }

    public function testAZeroTtlWritesWithoutAnExpiry(): void
    {
        $this->store->put('client_credentials:fleetbase-v2', '{}', 0);

        $this->assertSame(['set', ['oidc:an-identity-uuid:client_credentials:fleetbase-v2', '{}']], $this->calls[0]);
    }

    public function testANegativeTtlIsTreatedTheSameAsZero(): void
    {
        $this->store->put('client_credentials:fleetbase-v2', '{}', -1);

        $this->assertSame('set', $this->calls[0][0]);
    }

    public function testItDeletesOnForget(): void
    {
        $this->store->forget('nonce');

        $this->assertSame(['del', ['oidc:an-identity-uuid:nonce']], $this->calls[0]);
    }

    public function testItReturnsNullForANonStringValue(): void
    {
        $container = Container::getInstance();
        $container->instance('redis', new class {
            /**
             * @param array<int, mixed> $arguments
             */
            public function __call(string $method, array $arguments): mixed
            {
                return null;
            }
        });
        Facade::clearResolvedInstances();

        $this->assertNull((new RedisStateStore('oidc:x:'))->get('state'));
    }

    public function testItReturnsAStoredString(): void
    {
        $this->assertSame('a-stored-value', $this->store->get('state'));
    }
}
