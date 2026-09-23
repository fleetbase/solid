<?php

namespace Fleetbase\Solid\Tests\Unit;

use Fleetbase\Solid\Auth\DPoPKeyPair;
use Fleetbase\Solid\Auth\SolidIdTokenVerifier;
use Fleetbase\Solid\Client\OpenIDConnectClient;
use Fleetbase\Solid\Tests\Support\ArrayJwksResolver;
use Fleetbase\Solid\Tests\Support\ArrayOidcTransport;
use Fleetbase\Solid\Tests\Support\ArrayStateStore;
use Fleetbase\Solid\Tests\Support\TestSigner;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Where a DPoP private key is written.
 *
 * This is a security boundary, not a detail. An earlier version stored the key
 * with a bare `Storage::put()`, and in a Fleetbase install the default disk is
 * `public` — rooted at storage/app/public, symlinked to public/storage and served
 * over HTTP. The RSA private key that every access token is bound to was
 * therefore fetchable from the internet.
 */
class DPoPKeyStorageTest extends TestCase
{
    private const ISSUER    = 'https://pod.example.test';
    private const DISCOVERY = self::ISSUER . '/.well-known/openid-configuration';
    private const KEY_PATH  = 'solid/dpop_keys.json';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/solid-dpop-' . bin2hex(random_bytes(6));

        $container = new Container();
        $container->instance('log', new NullLogger());
        $container->instance('config', new Repository([
            'filesystems' => [
                // Mirrors the Fleetbase application: the default disk is the
                // web-served one.
                'default' => 'public',
                'disks'   => [
                    'public' => ['driver' => 'local', 'root' => $this->root . '/app/public'],
                    'local'  => ['driver' => 'local', 'root' => $this->root . '/app'],
                ],
            ],
        ]));
        $container->singleton('filesystem', fn ($app) => new FilesystemManager($app));

        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        OpenIDConnectClient::flushProviderCaches();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        exec('rm -rf ' . escapeshellarg($this->root));

        parent::tearDown();
    }

    private function client(): OpenIDConnectClient
    {
        $transport = (new ArrayOidcTransport())->queue(self::DISCOVERY, [
            'issuer'                 => self::ISSUER,
            'authorization_endpoint' => self::ISSUER . '/.oidc/auth',
            'token_endpoint'         => self::ISSUER . '/.oidc/token',
            'jwks_uri'               => self::ISSUER . '/.oidc/jwks',
        ]);

        return OpenIDConnectClient::create([
            'transport'   => $transport,
            'stateStore'  => new ArrayStateStore(),
            'verifier'    => new SolidIdTokenVerifier(new ArrayJwksResolver(TestSigner::jwks([['key-1']]))),
            'providerUrl' => self::ISSUER,
            'clientID'    => 'client-abc',
        ]);
    }

    private function privatePath(): string
    {
        return $this->root . '/app/' . self::KEY_PATH;
    }

    private function publicPath(): string
    {
        return $this->root . '/app/public/' . self::KEY_PATH;
    }

    public function testItWritesANewKeyToThePrivateDiskOnly(): void
    {
        $this->client()->createDPoP('POST', self::ISSUER . '/.oidc/token');

        $this->assertFileExists($this->privatePath());
        $this->assertFileDoesNotExist($this->publicPath(), 'A DPoP private key must never reach the web-served disk.');
    }

    public function testTheStoredKeyIsReusedRatherThanRegenerated(): void
    {
        // Regenerating would invalidate every access token already bound to it.
        $first = $this->client();
        $first->createDPoP('POST', self::ISSUER . '/.oidc/token');

        $stored = (string) file_get_contents($this->privatePath());

        OpenIDConnectClient::flushProviderCaches();
        $second = $this->client();
        $second->createDPoP('POST', self::ISSUER . '/.oidc/token');

        $this->assertSame($stored, (string) file_get_contents($this->privatePath()));
    }

    public function testItMovesAKeyLeftOnTheDefaultDiskByAnEarlierVersion(): void
    {
        $keyPair = DPoPKeyPair::generate();

        @mkdir(dirname($this->publicPath()), 0777, true);
        file_put_contents($this->publicPath(), (string) json_encode($keyPair->toArray()));

        $client = $this->client();
        $proof  = $client->createDPoP('POST', self::ISSUER . '/.oidc/token');

        // Same key: the migration must not sign existing users out.
        $header = (array) json_decode(
            (string) base64_decode(strtr(explode('.', $proof)[0], '-_', '+/'), true),
            true
        );
        $this->assertSame($keyPair->publicJwk(), $header['jwk']);

        $this->assertFileExists($this->privatePath());
        $this->assertFileDoesNotExist($this->publicPath(), 'The exposed copy must be deleted.');
    }

    public function testItIgnoresAnUnreadableLegacyKeyAndGeneratesAFreshOne(): void
    {
        @mkdir(dirname($this->publicPath()), 0777, true);
        file_put_contents($this->publicPath(), 'not json');

        $this->client()->createDPoP('POST', self::ISSUER . '/.oidc/token');

        $this->assertFileExists($this->privatePath());
    }

    public function testClearingCredentialsRemovesTheKeyFromBothDisks(): void
    {
        @mkdir(dirname($this->publicPath()), 0777, true);
        file_put_contents($this->publicPath(), (string) json_encode(DPoPKeyPair::generate()->toArray()));

        $client = $this->client();
        $client->createDPoP('POST', self::ISSUER . '/.oidc/token');
        $client->clearClientCredentials();

        $this->assertFileDoesNotExist($this->privatePath());
        $this->assertFileDoesNotExist($this->publicPath());
    }
}
