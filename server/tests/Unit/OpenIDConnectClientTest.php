<?php

namespace Fleetbase\Solid\Tests\Unit;

use Fleetbase\Solid\Auth\DPoPKeyPair;
use Fleetbase\Solid\Auth\SolidIdTokenVerifier;
use Fleetbase\Solid\Client\OpenIDConnectClient;
use Fleetbase\Solid\Exceptions\OpenIDConnectClientException;
use Fleetbase\Solid\Tests\Support\ArrayJwksResolver;
use Fleetbase\Solid\Tests\Support\ArrayOidcTransport;
use Fleetbase\Solid\Tests\Support\ArrayStateStore;
use Fleetbase\Solid\Tests\Support\OidcTestCase;
use Fleetbase\Solid\Tests\Support\TestSigner;

/**
 * The Solid-OIDC handshake, end to end, with the network and the key store
 * scripted. No Laravel container is booted — every collaborator the client needs
 * is injectable, which is the main practical reason the Jumbojett subclass was
 * replaced rather than patched.
 */
class OpenIDConnectClientTest extends OidcTestCase
{
    private const ISSUER       = 'https://pod.example.test';
    private const CLIENT_ID    = 'client-abc';
    private const REDIRECT_URI = 'https://fleetbase.example.test/solid/int/v1/oidc/complete-registration/abc123';
    private const DISCOVERY    = self::ISSUER . '/.well-known/openid-configuration';
    private const TOKEN        = self::ISSUER . '/.oidc/token';
    private const AUTHORIZE    = self::ISSUER . '/.oidc/auth';
    private const REGISTER     = self::ISSUER . '/.oidc/reg';
    private const JWKS         = self::ISSUER . '/.oidc/jwks';

    private ArrayOidcTransport $transport;
    private ArrayStateStore $stateStore;
    private DPoPKeyPair $keyPair;

    protected function setUp(): void
    {
        parent::setUp();

        $this->transport  = new ArrayOidcTransport();
        $this->stateStore = new ArrayStateStore();
        $this->keyPair    = DPoPKeyPair::generate();

        $this->transport->queue(self::DISCOVERY, [
            'issuer'                                => self::ISSUER,
            'authorization_endpoint'                => self::AUTHORIZE,
            'token_endpoint'                        => self::TOKEN,
            'registration_endpoint'                 => self::REGISTER,
            'jwks_uri'                              => self::JWKS,
            'code_challenge_methods_supported'      => ['S256'],
            'token_endpoint_auth_methods_supported' => ['client_secret_basic'],
        ]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function client(array $options = []): OpenIDConnectClient
    {
        return OpenIDConnectClient::create(array_merge([
            'transport'   => $this->transport,
            'stateStore'  => $this->stateStore,
            'dpopKeyPair' => $this->keyPair,
            'verifier'    => new SolidIdTokenVerifier(new ArrayJwksResolver(TestSigner::jwks([['key-1']]))),
            'clientID'    => self::CLIENT_ID,
            'providerUrl' => self::ISSUER,
        ], $options))->setRedirectURL(self::REDIRECT_URI);
    }

    /**
     * Drive a client through authorization so that state, nonce and the PKCE
     * verifier are all stored, then return the state to hand back on the callback.
     */
    private function startAuthorization(OpenIDConnectClient $client): string
    {
        parse_str((string) parse_url($client->getAuthorizationUrl(), PHP_URL_QUERY), $query);

        return (string) $query['state'];
    }

    /**
     * @param array<string, mixed> $overrides
     * @param array<string, mixed> $claimOverrides
     */
    private function queueTokenResponse(array $overrides = [], array $claimOverrides = [], int $status = 200): void
    {
        $nonce = $this->stateStore->get('nonce');

        $this->transport->queue(self::TOKEN, array_merge([
            'access_token'  => TestSigner::sign(['cnf' => ['jkt' => $this->keyPair->thumbprint()], 'exp' => time() + 600]),
            'id_token'      => TestSigner::sign(TestSigner::claims(array_merge([
                'iss'   => self::ISSUER,
                'aud'   => self::CLIENT_ID,
                'nonce' => $nonce,
            ], $claimOverrides))),
            'refresh_token' => 'a-refresh-token',
            'token_type'    => 'DPoP',
        ], $overrides), $status);
    }

    /*
    |--------------------------------------------------------------------------
    | Discovery
    |--------------------------------------------------------------------------
    */

    public function testItTakesTheIssuerFromTheDiscoveryDocument(): void
    {
        $this->assertSame(self::ISSUER, $this->client()->getIssuer());
    }

    public function testItRefusesADiscoveryDocumentWithNoIssuer(): void
    {
        $transport = (new ArrayOidcTransport())->queue(self::DISCOVERY, ['token_endpoint' => self::TOKEN]);

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/carries no issuer/');

        OpenIDConnectClient::create([
            'transport'   => $transport,
            'stateStore'  => $this->stateStore,
            'providerUrl' => self::ISSUER,
        ]);
    }

    public function testItFetchesDiscoveryOncePerProcess(): void
    {
        // SolidIdentity::request() builds a client per Solid call; without this the
        // discovery document would be refetched on every one of them.
        $this->client();
        $this->client();

        $this->assertSame(1, $this->transport->countRequestsTo(self::DISCOVERY));
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization request
    |--------------------------------------------------------------------------
    */

    public function testTheAuthorizationUrlCarriesTheParametersSolidOidcRequires(): void
    {
        parse_str((string) parse_url($this->client()->getAuthorizationUrl(), PHP_URL_QUERY), $query);

        $this->assertSame('code', $query['response_type']);
        $this->assertSame(self::CLIENT_ID, $query['client_id']);
        $this->assertSame(self::REDIRECT_URI, $query['redirect_uri']);
        $this->assertSame('openid webid offline_access', $query['scope']);
        $this->assertSame('S256', $query['code_challenge_method']);
        $this->assertNotEmpty($query['state']);
        $this->assertNotEmpty($query['nonce']);
    }

    public function testTheCodeChallengeIsTheS256HashOfTheStoredVerifier(): void
    {
        parse_str((string) parse_url($this->client()->getAuthorizationUrl(), PHP_URL_QUERY), $query);

        $verifier = (string) $this->stateStore->get('code_verifier');

        $this->assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            $query['code_challenge']
        );
    }

    public function testStateNonceAndVerifierAreAllStoredWithATtl(): void
    {
        // Written with no expiry before, which left an abandoned sign-in readable
        // in Redis indefinitely.
        $this->client()->getAuthorizationUrl();

        foreach (['state', 'nonce', 'code_verifier'] as $key) {
            $this->assertGreaterThan(0, $this->stateStore->ttl($key), $key . ' must expire on its own.');
        }
    }

    public function testEachAuthorizationRequestGetsFreshValues(): void
    {
        $first  = $this->startAuthorization($this->client());
        $second = $this->startAuthorization($this->client());

        $this->assertNotSame($first, $second);
    }

    public function testAuthenticateReturnsARedirectToTheAuthorizationUrl(): void
    {
        // It used to call header() + exit, which bypassed Laravel's response
        // pipeline entirely and could not be asserted on.
        $response = $this->client()->authenticate();

        parse_str((string) parse_url($response->getTargetUrl(), PHP_URL_QUERY), $query);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringStartsWith(self::AUTHORIZE, $response->getTargetUrl());
        $this->assertSame((string) $this->stateStore->get('state'), $query['state']);
    }

    public function testItWillNotStartAuthorizationWithoutAClientId(): void
    {
        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/registered client id/');

        OpenIDConnectClient::create([
            'transport'   => $this->transport,
            'stateStore'  => $this->stateStore,
            'providerUrl' => self::ISSUER,
        ])->setRedirectURL(self::REDIRECT_URI)->getAuthorizationUrl();
    }

    public function testItRefusesAProviderThatDoesNotAdvertiseS256Pkce(): void
    {
        // Solid-OIDC requires PKCE, so this fails loudly instead of silently
        // downgrading to an authorization request without a challenge.
        $transport = (new ArrayOidcTransport())->queue(self::DISCOVERY, [
            'issuer'                           => self::ISSUER,
            'authorization_endpoint'           => self::AUTHORIZE,
            'token_endpoint'                   => self::TOKEN,
            'jwks_uri'                         => self::JWKS,
            'code_challenge_methods_supported' => ['plain'],
        ]);

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/does not support the S256 PKCE method/');

        $this->client(['transport' => $transport])->getAuthorizationUrl();
    }

    /*
    |--------------------------------------------------------------------------
    | Callback
    |--------------------------------------------------------------------------
    */

    public function testItExchangesTheCodeAndReturnsTheVerifiedTokenResponse(): void
    {
        $client = $this->client();
        $state  = $this->startAuthorization($client);
        $this->queueTokenResponse();

        $tokenResponse = $client->exchangeCodeForTokens('an-auth-code', $state);

        $this->assertNotEmpty($tokenResponse->access_token);
        $this->assertSame('a-refresh-token', $client->getRefreshToken());
        $this->assertSame(
            'https://pod.example.test/alice/profile/card#me',
            $client->getVerifiedClaims()['webid']
        );
    }

    public function testTheTokenRequestSendsThePkceVerifierAndADpopProof(): void
    {
        $client   = $this->client();
        $state    = $this->startAuthorization($client);
        $verifier = (string) $this->stateStore->get('code_verifier');
        $this->queueTokenResponse();

        $client->exchangeCodeForTokens('an-auth-code', $state);

        $request = (array) $this->transport->lastRequestTo(self::TOKEN);

        $this->assertSame('authorization_code', $request['body']['grant_type']);
        $this->assertSame('an-auth-code', $request['body']['code']);
        $this->assertSame(self::REDIRECT_URI, $request['body']['redirect_uri']);
        $this->assertSame($verifier, $request['body']['code_verifier']);
        $this->assertArrayHasKey('DPoP', $request['headers']);
    }

    public function testTheTokenRequestUsesBasicAuthWhenTheProviderSupportsIt(): void
    {
        $client = $this->client(['clientSecret' => 's3cret']);
        $state  = $this->startAuthorization($client);
        $this->queueTokenResponse();

        $client->exchangeCodeForTokens('an-auth-code', $state);

        $request = (array) $this->transport->lastRequestTo(self::TOKEN);

        $this->assertSame(
            'Basic ' . base64_encode(urlencode(self::CLIENT_ID) . ':' . urlencode('s3cret')),
            $request['headers']['Authorization']
        );
        $this->assertArrayNotHasKey('client_secret', $request['body'], 'The secret must not be sent twice.');
    }

    public function testAPublicClientSendsItsClientIdInTheBody(): void
    {
        $client = $this->client();
        $state  = $this->startAuthorization($client);
        $this->queueTokenResponse();

        $client->exchangeCodeForTokens('an-auth-code', $state);

        $request = (array) $this->transport->lastRequestTo(self::TOKEN);

        $this->assertSame(self::CLIENT_ID, $request['body']['client_id']);
        $this->assertArrayNotHasKey('Authorization', $request['headers']);
    }

    public function testItRejectsACallbackWithNoState(): void
    {
        $client = $this->client();
        $this->startAuthorization($client);
        $this->queueTokenResponse();

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/no state parameter/');

        $client->exchangeCodeForTokens('an-auth-code', null);
    }

    public function testItRejectsACallbackWhoseStateDoesNotMatch(): void
    {
        $client = $this->client();
        $this->startAuthorization($client);
        $this->queueTokenResponse();

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/state does not match/');

        $client->exchangeCodeForTokens('an-auth-code', 'a-forged-state');
    }

    public function testItRejectsACallbackWithNoPendingAuthorizationRequest(): void
    {
        $this->queueTokenResponse();

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/no pending authorization request/');

        $this->client()->exchangeCodeForTokens('an-auth-code', 'some-state');
    }

    public function testStateCannotBeReplayed(): void
    {
        $client = $this->client();
        $state  = $this->startAuthorization($client);
        $this->queueTokenResponse();

        $client->exchangeCodeForTokens('an-auth-code', $state);

        $this->assertFalse($this->stateStore->has('state'));
        $this->assertFalse($this->stateStore->has('nonce'));
        $this->assertFalse($this->stateStore->has('code_verifier'), 'The PKCE verifier must be single use.');

        $this->expectException(OpenIDConnectClientException::class);

        $client->exchangeCodeForTokens('an-auth-code', $state);
    }

    public function testAWrongStateConsumesThePendingRequest(): void
    {
        // So a forged callback cannot be retried against the same request.
        $client = $this->client();
        $state  = $this->startAuthorization($client);
        $this->queueTokenResponse();

        try {
            $client->exchangeCodeForTokens('an-auth-code', 'a-forged-state');
        } catch (OpenIDConnectClientException) {
            // expected
        }

        $this->assertFalse($this->stateStore->has('state'));

        $this->expectException(OpenIDConnectClientException::class);

        $client->exchangeCodeForTokens('an-auth-code', $state);
    }

    public function testItRejectsACallbackWhosePkceVerifierHasExpired(): void
    {
        $client = $this->client();
        $state  = $this->startAuthorization($client);
        $this->queueTokenResponse();

        $this->stateStore->forget('code_verifier');

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/code verifier for this authorization request has expired/');

        $client->exchangeCodeForTokens('an-auth-code', $state);
    }

    public function testItRejectsACallbackWhoseNonceHasExpired(): void
    {
        // A null expected nonce reads to the verifier as "no nonce was sent", so
        // letting this through would silently skip the nonce check entirely.
        $client = $this->client();
        $state  = $this->startAuthorization($client);
        $this->queueTokenResponse();

        $this->stateStore->forget('nonce');

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/nonce for this authorization request has expired/');

        $client->exchangeCodeForTokens('an-auth-code', $state);
    }

    public function testAWrongStateAlsoDiscardsTheNonceAndTheVerifier(): void
    {
        $client = $this->client();
        $this->startAuthorization($client);
        $this->queueTokenResponse();

        try {
            $client->exchangeCodeForTokens('an-auth-code', 'a-forged-state');
        } catch (OpenIDConnectClientException) {
            // expected
        }

        $this->assertSame([], $this->stateStore->keys(), 'A rejected callback must void the whole request.');
    }

    public function testItRejectsAnEmptyAuthorizationCode(): void
    {
        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/no authorization code/');

        $this->client()->exchangeCodeForTokens('', 'some-state');
    }

    public function testItSurfacesAnErrorFromTheTokenEndpoint(): void
    {
        $client = $this->client();
        $state  = $this->startAuthorization($client);

        $this->transport->queue(self::TOKEN, [
            'error'             => 'invalid_grant',
            'error_description' => 'The authorization code has expired.',
        ], 400);

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/authorization code has expired/');

        $client->exchangeCodeForTokens('an-auth-code', $state);
    }

    public function testItRejectsATokenResponseWithNoIdToken(): void
    {
        $client = $this->client();
        $state  = $this->startAuthorization($client);
        $this->queueTokenResponse(['id_token' => null]);

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/no id_token/');

        $client->exchangeCodeForTokens('an-auth-code', $state);
    }

    /*
    |--------------------------------------------------------------------------
    | ID token verification, reached through the handshake
    |--------------------------------------------------------------------------
    */

    public function testItRejectsAnIdTokenFromTheWrongIssuer(): void
    {
        $client = $this->client();
        $state  = $this->startAuthorization($client);
        $this->queueTokenResponse([], ['iss' => 'https://attacker.example.test']);

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/unexpected issuer/');

        $client->exchangeCodeForTokens('an-auth-code', $state);
    }

    public function testItRejectsAnIdTokenForAnotherAudience(): void
    {
        $client = $this->client();
        $state  = $this->startAuthorization($client);
        $this->queueTokenResponse([], ['aud' => 'another-client']);

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/not addressed to this client/');

        $client->exchangeCodeForTokens('an-auth-code', $state);
    }

    public function testItRejectsAnIdTokenWhoseNonceIsFromAnotherRequest(): void
    {
        $client = $this->client();
        $state  = $this->startAuthorization($client);
        $this->queueTokenResponse([], ['nonce' => 'a-nonce-from-another-request']);

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/nonce does not match/');

        $client->exchangeCodeForTokens('an-auth-code', $state);
    }

    public function testItRejectsAnExpiredIdToken(): void
    {
        $client = $this->client();
        $state  = $this->startAuthorization($client);
        $this->queueTokenResponse([], ['exp' => time() - 3600, 'iat' => time() - 7200]);

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/failed verification/');

        $client->exchangeCodeForTokens('an-auth-code', $state);
    }

    public function testItRejectsAnIdTokenSignedByAnUnpublishedKey(): void
    {
        $client = $this->client();
        $state  = $this->startAuthorization($client);
        $nonce  = $this->stateStore->get('nonce');

        $this->transport->queue(self::TOKEN, [
            'access_token' => 'an-opaque-token',
            'id_token'     => TestSigner::sign(
                TestSigner::claims(['iss' => self::ISSUER, 'aud' => self::CLIENT_ID, 'nonce' => $nonce]),
                'an-unpublished-key'
            ),
        ]);

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/does not publish/');

        $client->exchangeCodeForTokens('an-auth-code', $state);
    }

    /*
    |--------------------------------------------------------------------------
    | DPoP binding
    |--------------------------------------------------------------------------
    */

    public function testItRejectsAnAccessTokenBoundToADifferentDpopKey(): void
    {
        // Such a token would be refused by every resource request, so failing here
        // — with a reason — beats storing it.
        $client = $this->client();
        $state  = $this->startAuthorization($client);

        $this->queueTokenResponse([
            'access_token' => TestSigner::sign([
                'cnf' => ['jkt' => DPoPKeyPair::generate()->thumbprint()],
                'exp' => time() + 600,
            ]),
        ]);

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/bound to a different DPoP key/');

        $client->exchangeCodeForTokens('an-auth-code', $state);
    }

    public function testItAcceptsAnOpaqueAccessToken(): void
    {
        // OAuth permits one, so a token that is not a JWT is not rejected for
        // lacking a cnf claim.
        $client = $this->client();
        $state  = $this->startAuthorization($client);
        $this->queueTokenResponse(['access_token' => 'an-opaque-token']);

        $this->assertSame('an-opaque-token', $client->exchangeCodeForTokens('an-auth-code', $state)->access_token);
    }

    public function testItRetriesTheTokenRequestOnceWithAServerSuppliedDpopNonce(): void
    {
        // RFC 9449 section 8.
        $client = $this->client();
        $state  = $this->startAuthorization($client);

        $this->transport->queue(self::TOKEN, ['error' => 'use_dpop_nonce'], 400, ['DPoP-Nonce' => 'server-nonce']);
        $this->queueTokenResponse();

        $client->exchangeCodeForTokens('an-auth-code', $state);

        $this->assertSame(2, $this->transport->countRequestsTo(self::TOKEN));

        $proof  = (string) ((array) $this->transport->lastRequestTo(self::TOKEN))['headers']['DPoP'];
        $claims = (array) json_decode(
            (string) base64_decode(strtr(explode('.', $proof)[1], '-_', '+/'), true),
            true
        );

        $this->assertSame('server-nonce', $claims['nonce']);
    }

    /*
    |--------------------------------------------------------------------------
    | Refresh
    |--------------------------------------------------------------------------
    */

    public function testItRefreshesAnAccessToken(): void
    {
        $client = $this->client();

        $this->transport->queue(self::TOKEN, [
            'access_token' => TestSigner::sign(['cnf' => ['jkt' => $this->keyPair->thumbprint()], 'exp' => time() + 600]),
            'token_type'   => 'DPoP',
        ]);

        $client->refreshToken('a-refresh-token');

        $request = (array) $this->transport->lastRequestTo(self::TOKEN);

        $this->assertSame('refresh_token', $request['body']['grant_type']);
        $this->assertSame('a-refresh-token', $request['body']['refresh_token']);
        $this->assertNotNull($client->getAccessToken());
    }

    public function testItVerifiesAnIdTokenReturnedFromARefresh(): void
    {
        // No nonce is expected here: a refresh does not correspond to an
        // authorization request. Everything else still has to hold.
        $client = $this->client();

        $this->transport->queue(self::TOKEN, [
            'access_token' => 'an-opaque-token',
            'id_token'     => TestSigner::sign(TestSigner::claims([
                'iss' => 'https://attacker.example.test',
                'aud' => self::CLIENT_ID,
            ])),
        ]);

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/unexpected issuer/');

        $client->refreshToken('a-refresh-token');
    }

    public function testItRequiresARefreshToken(): void
    {
        $this->expectException(OpenIDConnectClientException::class);

        $this->client()->refreshToken('');
    }

    /*
    |--------------------------------------------------------------------------
    | Dynamic client registration
    |--------------------------------------------------------------------------
    */

    public function testItRegistersAClientAndKeepsTheReturnedCredentials(): void
    {
        $this->transport->queue(self::REGISTER, [
            'client_id'     => 'newly-registered',
            'client_secret' => 'newly-issued-secret',
            'client_name'   => 'Fleetbase-v2',
        ], 201);

        $client = $this->client(['clientID' => null])->register(['redirectUri' => self::REDIRECT_URI]);

        $this->assertSame('newly-registered', $client->getClientID());
        $this->assertSame('newly-issued-secret', $client->getClientSecret());

        $body = (array) ((array) $this->transport->lastRequestTo(self::REGISTER))['body'];

        $this->assertSame([self::REDIRECT_URI], $body['redirect_uris']);
        $this->assertSame('openid webid offline_access', $body['scope']);
        // Declared so the provider does not default to authorization_code only,
        // which would make the requested offline_access scope unusable.
        $this->assertSame(['authorization_code', 'refresh_token'], $body['grant_types']);
        $this->assertSame(['code'], $body['response_types']);
    }

    public function testItSavesAndRestoresClientCredentialsWhenAsked(): void
    {
        $this->transport->queue(self::REGISTER, ['client_id' => 'saved-client', 'client_secret' => 'saved-secret'], 201);

        $this->client()->register(['redirectUri' => self::REDIRECT_URI, 'saveCredentials' => true]);

        $restored = $this->client(['clientID' => null])->restoreClientCredentials();

        $this->assertSame('saved-client', $restored->getClientID());
        $this->assertSame('saved-secret', $restored->getClientSecret());
    }

    public function testARegistrationIsStoredWithoutAnExpiryByDefault(): void
    {
        // It has to outlive any single sign-in.
        $this->transport->queue(self::REGISTER, ['client_id' => 'saved-client'], 201);

        $this->client()->register(['redirectUri' => self::REDIRECT_URI, 'saveCredentials' => true]);

        $this->assertSame(0, $this->stateStore->ttl('client_credentials:fleetbase-v2'));
    }

    public function testItSurfacesARejectedRegistration(): void
    {
        $this->transport->queue(self::REGISTER, ['error' => 'invalid_redirect_uri'], 400);

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/refused client registration/');

        $this->client()->register(['redirectUri' => self::REDIRECT_URI]);
    }

    public function testItRejectsARegistrationResponseWithNoClientId(): void
    {
        $this->transport->queue(self::REGISTER, ['client_name' => 'Fleetbase-v2'], 201);

        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/no client_id/');

        $this->client()->register(['redirectUri' => self::REDIRECT_URI]);
    }

    public function testItWillNotRegisterWithoutARedirectUri(): void
    {
        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/redirect URI is required/');

        $this->client()->setRedirectURL(null)->register();
    }

    public function testItRestoresNothingWhenNoCredentialsWereSaved(): void
    {
        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/No saved Solid client credentials/');

        $this->client()->restoreClientCredentials();
    }

    /*
    |--------------------------------------------------------------------------
    | Claims and unverified decoding
    |--------------------------------------------------------------------------
    */

    public function testItReadsTheWebidFromVerifiedClaimsAfterASuccessfulHandshake(): void
    {
        $client = $this->client();
        $state  = $this->startAuthorization($client);
        $this->queueTokenResponse();

        $tokenResponse = $client->exchangeCodeForTokens('an-auth-code', $state);

        $this->assertSame(
            'https://pod.example.test/alice/profile/card#me',
            $client->getWebIdFromIdToken((string) $tokenResponse->id_token)
        );
    }

    public function testItFallsBackToTheSubjectWhenThereIsNoWebidClaim(): void
    {
        $claims = TestSigner::claims();
        unset($claims['webid']);

        $this->assertSame($claims['sub'], $this->client()->getWebIdFromIdToken(TestSigner::sign($claims)));
    }

    public function testItReturnsNoWebidForATokenItCannotDecode(): void
    {
        $this->assertNull($this->client()->getWebIdFromIdToken('not-a-jwt'));
        $this->assertNull($this->client()->getWebIdFromIdToken(''));
    }

    public function testSettingAnIdTokenDiscardsClaimsVerifiedForAnotherOne(): void
    {
        $client = $this->client();
        $state  = $this->startAuthorization($client);
        $this->queueTokenResponse();
        $client->exchangeCodeForTokens('an-auth-code', $state);

        $this->assertNotEmpty($client->getVerifiedClaims());

        $client->setIdToken(TestSigner::sign(TestSigner::claims(['sub' => 'https://elsewhere.test/#me'])));

        $this->assertSame([], $client->getVerifiedClaims());
    }

    public function testAddScopeDoesNotDuplicateExistingScopes(): void
    {
        $client = $this->client()->addScope(['openid', 'webid', 'profile']);

        $this->assertSame(['openid', 'webid', 'offline_access', 'profile'], $client->getScopes());
    }

    public function testAMissingProviderEndpointIsAnErrorRatherThanNull(): void
    {
        $this->expectException(OpenIDConnectClientException::class);
        $this->expectExceptionMessageMatches('/does not advertise "end_session_endpoint"/');

        $this->client()->getProviderConfigValue('end_session_endpoint');
    }

    public function testProviderConfigOverridesTakePrecedenceOverDiscovery(): void
    {
        $client = $this->client()->providerConfigParam(['token_endpoint' => 'https://override.example.test/token']);

        $this->assertSame('https://override.example.test/token', $client->getProviderConfigValue('token_endpoint'));
    }
}
