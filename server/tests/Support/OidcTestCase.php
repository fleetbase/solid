<?php

namespace Fleetbase\Solid\Tests\Support;

use Fleetbase\Solid\Client\OpenIDConnectClient;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * A test case with just enough container for the OIDC classes to run.
 *
 * Only the log facade is bound. Config is deliberately left unbound so the
 * client falls back to the defaults compiled into it, which are the same values
 * `server/config/solid.php` ships — a test that needs a different setting passes
 * it to the collaborator directly instead.
 */
abstract class OidcTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $container->instance('log', new NullLogger());

        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        // The memos are static, so one test's provider must not leak into the next.
        OpenIDConnectClient::flushProviderCaches();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }
}
