<?php

namespace Fleetbase\Solid\Client;

use Fleetbase\Solid\Auth\CachedJwksResolver;
use Fleetbase\Solid\Auth\Contracts\OidcResponse;
use Fleetbase\Solid\Auth\Contracts\OidcStateStore;
use Fleetbase\Solid\Auth\Contracts\OidcTransport;
use Fleetbase\Solid\Auth\DPoPKeyPair;
use Fleetbase\Solid\Auth\HttpOidcTransport;
use Fleetbase\Solid\Auth\RedisStateStore;
use Fleetbase\Solid\Auth\SolidIdTokenVerifier;
use Fleetbase\Solid\Exceptions\OpenIDConnectClientException;
use Fleetbase\Solid\Models\SolidIdentity;
use Illuminate\Container\Container;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The name Fleetbase registers itself under with a Solid identity provider.
 *
 * Part of the Redis key that caches the resulting client credentials, so changing
 * it forces every identity to register a fresh client on its next sign-in.
 * v2 added the `webid` scope.
 */
const CLIENT_NAME = 'Fleetbase-v2';

/**
 * A Solid-OIDC relying-party client.
 *
 * ## Why this is not a library
 *
 * This class used to extend `Jumbojett\OpenIDConnectClient`, which pinned
 * `phpseclib/phpseclib ^3.0.7` and so could not be installed beside Core API's
 * `laravel/socialite ^5.31` (phpseclib ^4.0). The pin bought nothing: Jumbojett
 * touches phpseclib in exactly one method, `verifyRSAJWTSignature()`, which this
 * class already overrode away — and the callback path never reached Jumbojett's
 * verification at all. Meanwhile almost everything else was overridden too
 * (transport, token request, registration, session storage, discovery), so about
 * 150 lines of a 2,100-line dependency were actually in use.
 *
 * Solid-OIDC also needs three things no general-purpose OAuth client offers, and
 * that Socialite in particular does not: RFC 7591 dynamic client registration,
 * RFC 9449 DPoP-bound tokens, and a per-tenant issuer discovered at runtime. All
 * three were already hand-written here. So the library was removed rather than
 * replaced, and what remains reuses Core API's *verification* conventions
 * (see `Fleetbase\Solid\Auth\SolidIdTokenVerifier`) rather than its transport.
 *
 * ## What changed for callers
 *
 *   - `authenticate()` returns a `RedirectResponse` instead of calling
 *     `header()` + `exit`, so the redirect now goes through Laravel.
 *   - `exchangeCodeForTokens()` requires the `state` from the callback and
 *     verifies the returned `id_token`. Both were previously skipped entirely.
 *
 * Every collaborator is injectable through the options array, which is what makes
 * the handshake testable without a network or a Redis server.
 */
final class OpenIDConnectClient
{
    /**
     * Scopes requested at registration and at authorization.
     *
     * `webid` is what makes the provider put the user's WebID in the ID token;
     * `offline_access` is what makes it issue a refresh token.
     */
    private const SCOPES = ['openid', 'webid', 'offline_access'];

    /**
     * PKCE is mandatory for Solid-OIDC, and S256 is the only method accepted.
     */
    private const CODE_CHALLENGE_METHOD = 'S256';

    private ?SolidClient $solid;
    private ?SolidIdentity $identity;
    private OidcTransport $transport;
    private OidcStateStore $stateStore;
    private SolidIdTokenVerifier $verifier;

    /**
     * The discovery document, once fetched.
     */
    private ?\stdClass $openIdConfig = null;

    /**
     * Endpoint overrides merged on top of discovery, for `providerConfigParam()`.
     *
     * @var array<string, mixed>
     */
    private array $providerConfig = [];

    private ?string $providerUrl        = null;
    private ?string $issuer             = null;
    private string $clientName          = CLIENT_NAME;
    private ?string $clientId           = null;
    private ?string $clientSecret       = null;
    private ?string $redirectUri        = null;
    private ?string $code               = null;
    private string $codeChallengeMethod = self::CODE_CHALLENGE_METHOD;

    /**
     * @var array<int, string>
     */
    private array $scopes = self::SCOPES;

    private ?DPoPKeyPair $dpopKeyPair = null;

    private ?\stdClass $tokenResponse = null;
    private ?string $idToken          = null;
    private ?string $accessToken      = null;
    private ?string $refreshToken     = null;

    /**
     * Claims from the last ID token this client verified. Empty until a token has
     * actually been verified — never populated from an unverified decode.
     *
     * @var array<string, mixed>
     */
    private array $verifiedClaims = [];

    /**
     * Key pairs are cached per identity for the life of the request so that a
     * single Solid operation signing several DPoP proofs does not re-read storage
     * for each one.
     *
     * @var array<string, DPoPKeyPair>
     */
    private static array $dpopKeyPairCache = [];

    /**
     * Discovery documents already fetched in this process, keyed by URL.
     *
     * @var array<string, \stdClass>
     */
    private static array $discoveryCache = [];

    /**
     * Drop the process-level discovery and DPoP key memos.
     *
     * Needed when the Solid server configuration changes inside a long-lived
     * worker — Octane keeps the process alive between requests — and by tests
     * that script a different provider.
     */
    public static function flushProviderCaches(): void
    {
        self::$discoveryCache   = [];
        self::$dpopKeyPairCache = [];
    }

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options = [])
    {
        $solid       = $options['solid'] ?? null;
        $this->solid = $solid instanceof SolidClient ? $solid : null;

        $identity       = $options['identity'] ?? null;
        $this->identity = $identity instanceof SolidIdentity ? $identity : null;

        $transport       = $options['transport'] ?? null;
        $this->transport = $transport instanceof OidcTransport
            ? $transport
            : new HttpOidcTransport(self::shouldVerifyTls(), self::timeout());

        $stateStore       = $options['stateStore'] ?? null;
        $this->stateStore = $stateStore instanceof OidcStateStore
            ? $stateStore
            // Keyed on the identity uuid, not its `identifier`: the identifier is
            // regenerated by SolidIdentity::initialize(), so state written under it
            // would be unreachable after any concurrent re-initialisation.
            : new RedisStateStore('oidc:' . ($this->identity->uuid ?? 'default') . ':');

        $verifier       = $options['verifier'] ?? null;
        $this->verifier = $verifier instanceof SolidIdTokenVerifier
            ? $verifier
            : new SolidIdTokenVerifier(
                new CachedJwksResolver($this->transport, self::intConfig('solid.oidc.jwks_cache_ttl', CachedJwksResolver::DEFAULT_TTL_SECONDS)),
                SolidIdTokenVerifier::DEFAULT_ALGORITHMS,
                self::intConfig('solid.oidc.leeway', 60),
                (bool) self::config('solid.oidc.require_nonce', true)
            );

        $dpopKeyPair = $options['dpopKeyPair'] ?? null;
        if ($dpopKeyPair instanceof DPoPKeyPair) {
            $this->dpopKeyPair = $dpopKeyPair;
        }

        if (isset($options['providerUrl']) && is_string($options['providerUrl'])) {
            $this->providerUrl = $options['providerUrl'];
        }

        if ($this->identity instanceof SolidIdentity) {
            $this->redirectUri = $this->identity->getRedirectUri();
        }

        $this->clientName   = self::stringOption($options, 'clientName') ?? CLIENT_NAME;
        $this->clientId     = self::stringOption($options, 'clientID');
        $this->clientSecret = self::stringOption($options, 'clientSecret');

        // Restore on request, and also whenever we have an identity but no explicit
        // client id — that is the ordinary "resume an existing registration" case.
        if (isset($options['restore']) || ($this->identity instanceof SolidIdentity && $this->clientId === null)) {
            try {
                $this->restoreClientCredentials($this->clientName);
            } catch (\Throwable $e) {
                // Nothing saved yet, which is normal before the first registration.
                Log::debug('[Solid OIDC] No saved client credentials to restore.', [
                    'identity_uuid' => $this->identity?->uuid,
                ]);
            }
        }
    }

    /**
     * Build a client and load the provider's discovery document.
     *
     * @param array<string, mixed> $options
     *
     * @throws OpenIDConnectClientException
     */
    public static function create(array $options = []): self
    {
        $client = new self($options);

        $client->providerUrl = $client->resolveDiscoveryBase();

        $openIdConfig = $client->getOpenIdConfiguration();

        $issuer = $openIdConfig->issuer ?? null;

        if (!is_string($issuer) || $issuer === '') {
            throw new OpenIDConnectClientException('The Solid provider discovery document carries no issuer.');
        }

        // From here on the issuer is whatever the provider says it is, and every
        // ID token must name exactly that.
        $client->providerUrl = $issuer;
        $client->issuer      = $issuer;

        return $client;
    }

    /**
     * Register Fleetbase as a client with the provider (RFC 7591).
     *
     * @param array<string, mixed> $options
     *
     * @throws OpenIDConnectClientException
     */
    public function register(array $options = []): self
    {
        $clientName      = self::stringOption($options, 'clientName') ?? $this->clientName;
        $requestParams   = is_array($options['requestParams'] ?? null) ? $options['requestParams'] : [];
        $redirectUri     = self::stringOption($options, 'redirectUri') ?? $this->redirectUri ?? '';
        $saveCredentials = (bool) ($options['saveCredentials'] ?? false);
        $withCredentials = $options['withCredentials'] ?? null;

        if ($redirectUri === '') {
            throw new OpenIDConnectClientException('A redirect URI is required to register a Solid client.');
        }

        $openIdConfig = $this->getOpenIdConfiguration();

        $issuer = $openIdConfig->issuer ?? null;

        if (is_string($issuer) && $issuer !== '') {
            $this->issuer = $issuer;
        }

        $this->clientName  = $clientName;
        $this->redirectUri = $redirectUri;

        $registrationEndpoint = $this->stringProviderConfigValue('registration_endpoint');

        $response = $this->transport->postJson($registrationEndpoint, array_merge([
            'client_name'   => $clientName,
            'redirect_uris' => [$redirectUri],
            'scope'         => implode(' ', $this->scopes),
            // Declared explicitly because a provider that defaults `grant_types` to
            // `authorization_code` alone will refuse the refresh grant later, which
            // silently makes the requested `offline_access` scope useless.
            'grant_types'    => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
        ], $requestParams));

        if (!$response->successful()) {
            Log::error('[Solid OIDC] Dynamic client registration was rejected.', [
                'status'   => $response->status,
                'endpoint' => $registrationEndpoint,
            ]);

            throw new OpenIDConnectClientException('The Solid provider refused client registration (HTTP ' . $response->status . ').');
        }

        $credentials = $response->object('the client registration endpoint');

        if (!isset($credentials->client_id) || !is_string($credentials->client_id)) {
            throw new OpenIDConnectClientException('The client registration response carried no client_id.');
        }

        $this->applyClientCredentials($credentials);

        if ($saveCredentials) {
            $this->saveClientCredentials($clientName, $credentials);
        }

        if (is_callable($withCredentials)) {
            $withCredentials($credentials);
        }

        return $this;
    }

    /**
     * Begin authorization: mint `state`, `nonce` and a PKCE verifier, then send the
     * browser to the provider.
     *
     * Returns a redirect rather than performing one. Jumbojett's `authenticate()`
     * ended in `header()` + `exit`, which bypassed Laravel's response pipeline and
     * made the call untestable; the only in-tree caller already returned its result.
     *
     * @throws OpenIDConnectClientException
     */
    public function authenticate(): RedirectResponse
    {
        return new RedirectResponse($this->getAuthorizationUrl());
    }

    /**
     * The URL the browser must be sent to in order to start authorization.
     *
     * Has the side effect of storing the single-use `state`, `nonce` and PKCE
     * verifier that the callback will be checked against.
     *
     * @throws OpenIDConnectClientException
     */
    public function getAuthorizationUrl(): string
    {
        if ($this->clientId === null || $this->clientId === '') {
            throw new OpenIDConnectClientException('A registered client id is required before authorization.');
        }

        if ($this->redirectUri === null || $this->redirectUri === '') {
            throw new OpenIDConnectClientException('A redirect URI is required before authorization.');
        }

        $endpoint = $this->stringProviderConfigValue('authorization_endpoint');

        $this->assertPkceSupported();

        $ttl = self::intConfig('solid.oidc.state_ttl', 600);

        $state        = self::randomToken();
        $nonce        = self::randomToken();
        $codeVerifier = self::randomToken(48);

        $this->stateStore->put('state', $state, $ttl);
        $this->stateStore->put('nonce', $nonce, $ttl);
        $this->stateStore->put('code_verifier', $codeVerifier, $ttl);

        $params = [
            'response_type'         => 'code',
            'client_id'             => $this->clientId,
            'redirect_uri'          => $this->redirectUri,
            'scope'                 => implode(' ', $this->scopes),
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => self::codeChallenge($codeVerifier),
            'code_challenge_method' => $this->codeChallengeMethod,
        ];

        Log::info('[Solid OIDC] Redirecting to the provider for authorization.', [
            'identity_uuid' => $this->identity?->uuid,
            'scopes'        => $this->scopes,
        ]);

        return $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Complete the handshake: validate `state`, exchange the code, then verify the
     * ID token before anything is persisted.
     *
     * `$state` is nominally optional so the signature stays source-compatible with
     * the previous version, but a missing one is now an error: without it there is
     * no CSRF protection on the callback at all.
     *
     * @throws OpenIDConnectClientException
     */
    public function exchangeCodeForTokens(string $code, ?string $state = null): \stdClass
    {
        if ($code === '') {
            throw new OpenIDConnectClientException('The callback carried no authorization code.');
        }

        $this->assertState($state);

        $codeVerifier = $this->stateStore->get('code_verifier');

        if (!is_string($codeVerifier) || $codeVerifier === '') {
            throw new OpenIDConnectClientException('The PKCE code verifier for this authorization request has expired.');
        }

        $expectedNonce = $this->stateStore->get('nonce');

        // Always sent by getAuthorizationUrl(), so its absence must fail rather
        // than fall through to the verifier, which reads a null expected nonce as
        // "this token did not come from an authorization request".
        if (!is_string($expectedNonce) || $expectedNonce === '') {
            throw new OpenIDConnectClientException('The nonce for this authorization request has expired.');
        }

        $this->code = $code;

        $tokenResponse = $this->requestTokens([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => (string) $this->redirectUri,
            'code_verifier' => $codeVerifier,
        ]);

        // Single use, whatever happens next.
        $this->stateStore->forget('code_verifier');
        $this->stateStore->forget('nonce');

        $idToken = $tokenResponse->id_token ?? null;

        if (!is_string($idToken) || $idToken === '') {
            throw new OpenIDConnectClientException('The token response carried no id_token; the openid scope was not granted.');
        }

        $this->verifiedClaims = $this->verifyIdToken($idToken, $expectedNonce);

        $this->captureTokens($tokenResponse, $idToken);
        $this->assertTokenBoundToDPoPKey($this->accessToken);

        Log::info('[Solid OIDC] Authorization code exchanged and id_token verified.', [
            'identity_uuid'      => $this->identity?->uuid,
            'has_refresh_token'  => $this->refreshToken !== null,
        ]);

        return $tokenResponse;
    }

    /**
     * Exchange a refresh token for a new access token.
     *
     * Kept under Jumbojett's method name so callers that used it keep working.
     * There is no `nonce` to check here: an ID token returned from a refresh does
     * not correspond to a fresh authorization request.
     *
     * @throws OpenIDConnectClientException
     */
    public function refreshToken(string $refreshToken): \stdClass
    {
        if ($refreshToken === '') {
            throw new OpenIDConnectClientException('A refresh token is required.');
        }

        $tokenResponse = $this->requestTokens([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
            'scope'         => implode(' ', $this->scopes),
        ]);

        $idToken = $tokenResponse->id_token ?? null;

        if (is_string($idToken) && $idToken !== '') {
            $this->verifiedClaims = $this->verifyIdToken($idToken, null);
        }

        $this->captureTokens($tokenResponse, is_string($idToken) ? $idToken : null);
        $this->assertTokenBoundToDPoPKey($this->accessToken);

        return $tokenResponse;
    }

    /**
     * POST to the token endpoint with client authentication and a DPoP proof.
     *
     * Per RFC 9449 §8 a provider may reject the first attempt and hand back a
     * nonce it wants included; that is retried once, and only once.
     *
     * @param array<string, string> $fields
     *
     * @throws OpenIDConnectClientException
     */
    private function requestTokens(array $fields): \stdClass
    {
        $endpoint = $this->stringProviderConfigValue('token_endpoint');

        $response = $this->sendTokenRequest($endpoint, $fields, null);

        $dpopNonce = $response->header('DPoP-Nonce');

        if (!$response->successful() && $dpopNonce !== null && self::isDPoPNonceError($response)) {
            $response = $this->sendTokenRequest($endpoint, $fields, $dpopNonce);
        }

        $tokenResponse = $response->object('the token endpoint');

        if (isset($tokenResponse->error)) {
            $description = $tokenResponse->error_description ?? $tokenResponse->error;

            throw new OpenIDConnectClientException('The Solid provider rejected the token request: ' . (is_string($description) ? $description : 'unknown error'));
        }

        if (!$response->successful()) {
            throw new OpenIDConnectClientException('The token endpoint returned HTTP ' . $response->status . '.');
        }

        return $tokenResponse;
    }

    /**
     * @param array<string, string> $fields
     *
     * @throws OpenIDConnectClientException
     */
    private function sendTokenRequest(string $endpoint, array $fields, ?string $dpopNonce): OidcResponse
    {
        $headers = ['DPoP' => $this->dpopKeyPair()->proof('POST', $endpoint, null, $dpopNonce)];

        $advertised  = $this->getProviderConfigValue('token_endpoint_auth_methods_supported', ['client_secret_basic']);
        $authMethods = array_values(array_filter(is_array($advertised) ? $advertised : [], 'is_string'));

        $clientId     = (string) $this->clientId;
        $clientSecret = (string) $this->clientSecret;

        if ($clientSecret !== '' && $this->supportsAuthMethod('client_secret_basic', $authMethods)) {
            // RFC 6749 §2.3.1: both halves are form-urlencoded before the colon.
            $headers['Authorization'] = 'Basic ' . base64_encode(urlencode($clientId) . ':' . urlencode($clientSecret));
        } else {
            $fields['client_id'] = $clientId;

            if ($clientSecret !== '') {
                $fields['client_secret'] = $clientSecret;
            }
        }

        // client_id is always sent alongside PKCE so a public client is identifiable.
        if (!isset($fields['client_id']) && isset($fields['code_verifier'])) {
            $fields['client_id'] = $clientId;
        }

        return $this->transport->postForm($endpoint, $fields, $headers);
    }

    private static function isDPoPNonceError(OidcResponse $response): bool
    {
        $decoded = json_decode($response->body, false);

        return $decoded instanceof \stdClass && ($decoded->error ?? null) === 'use_dpop_nonce';
    }

    /**
     * @return array<string, mixed>
     *
     * @throws OpenIDConnectClientException
     */
    private function verifyIdToken(string $idToken, ?string $expectedNonce): array
    {
        return $this->verifier->verify(
            $idToken,
            $this->stringProviderConfigValue('jwks_uri'),
            (string) $this->issuer,
            (string) $this->clientId,
            $expectedNonce
        );
    }

    /**
     * Validate the callback `state` against the value stored when the
     * authorization request was built, then consume it.
     *
     * @throws OpenIDConnectClientException
     */
    private function assertState(?string $state): void
    {
        if ($state === null || $state === '') {
            throw new OpenIDConnectClientException('The callback carried no state parameter.');
        }

        $expected = $this->stateStore->get('state');

        if (!is_string($expected) || $expected === '') {
            throw new OpenIDConnectClientException('There is no pending authorization request for this identity, or it has expired.');
        }

        // Consumed before the comparison so that a wrong state cannot be retried
        // against the same pending request.
        $this->stateStore->forget('state');

        if (!hash_equals($expected, $state)) {
            // The whole request is now void, so the nonce and verifier go too.
            $this->stateStore->forget('nonce');
            $this->stateStore->forget('code_verifier');

            throw new OpenIDConnectClientException('The callback state does not match the pending authorization request.');
        }
    }

    /**
     * Confirm the provider bound the access token to the DPoP key we hold.
     *
     * A Solid access token is a JWT carrying `cnf.jkt`, the thumbprint of the key
     * it is bound to. If that is not our key, every resource request made with the
     * token will be refused — better to fail here, with a reason, than to store a
     * token that cannot be used.
     *
     * Opaque access tokens are permitted by OAuth, so a token that is not a JWT is
     * skipped rather than rejected.
     *
     * @throws OpenIDConnectClientException
     */
    private function assertTokenBoundToDPoPKey(?string $accessToken): void
    {
        if (!is_string($accessToken) || $accessToken === '') {
            return;
        }

        $claims = self::decodeJwtSection($accessToken, 1);

        if (!is_array($claims)) {
            return;
        }

        $confirmation = $claims['cnf'] ?? null;
        $thumbprint   = is_array($confirmation) ? ($confirmation['jkt'] ?? null) : null;

        if (!is_string($thumbprint) || $thumbprint === '') {
            Log::warning('[Solid OIDC] The access token is not DPoP-bound.', [
                'identity_uuid' => $this->identity?->uuid,
            ]);

            return;
        }

        if (!hash_equals($this->dpopKeyPair()->thumbprint(), $thumbprint)) {
            throw new OpenIDConnectClientException('The access token is bound to a different DPoP key than the one held for this identity.');
        }
    }

    /**
     * @throws OpenIDConnectClientException
     */
    private function captureTokens(\stdClass $tokenResponse, ?string $idToken): void
    {
        $this->tokenResponse = $tokenResponse;

        if (is_string($idToken)) {
            $this->idToken = $idToken;
        }

        $accessToken = $tokenResponse->access_token ?? null;

        if (!is_string($accessToken) || $accessToken === '') {
            throw new OpenIDConnectClientException('The token response carried no access_token.');
        }

        $this->accessToken = $accessToken;

        $refreshToken = $tokenResponse->refresh_token ?? null;

        if (is_string($refreshToken) && $refreshToken !== '') {
            $this->refreshToken = $refreshToken;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Discovery and provider configuration
    |--------------------------------------------------------------------------
    */

    /**
     * The provider's discovery document, or one value from it.
     *
     * Cached for the life of the client. It is also cached across requests when
     * `solid.oidc.discovery_cache_ttl` is positive — without that, every
     * `SolidClient` construction re-fetched it, which meant one extra HTTP round
     * trip for every authenticated Solid request.
     *
     * @return ($key is null ? \stdClass : mixed)
     *
     * @throws OpenIDConnectClientException
     */
    public function getOpenIdConfiguration(?string $key = null)
    {
        if ($this->openIdConfig === null) {
            $this->openIdConfig = $this->fetchOpenIdConfiguration();
        }

        if ($key !== null) {
            if (!property_exists($this->openIdConfig, $key)) {
                throw new OpenIDConnectClientException('The Solid provider discovery document has no "' . $key . '".');
            }

            return $this->openIdConfig->{$key};
        }

        return $this->openIdConfig;
    }

    /**
     * @throws OpenIDConnectClientException
     */
    private function fetchOpenIdConfiguration(): \stdClass
    {
        $url = rtrim($this->resolveDiscoveryBase(), '/') . '/.well-known/openid-configuration';

        // Memoised per process as well as per client. `SolidIdentity::request()`
        // builds a fresh SolidClient — and therefore a fresh OIDC client — for every
        // Solid call, so without this a single controller action that touches four
        // resources performs four discovery round trips.
        if (isset(self::$discoveryCache[$url])) {
            return self::$discoveryCache[$url];
        }

        $decoded = self::cacheRemember(
            'solid:oidc:discovery:' . sha1($url),
            self::intConfig('solid.oidc.discovery_cache_ttl', 300),
            function () use ($url): array {
                $response = $this->transport->get($url);

                if (!$response->successful()) {
                    throw new OpenIDConnectClientException('Solid provider discovery failed with HTTP ' . $response->status . '.');
                }

                return $response->array('the provider discovery endpoint');
            }
        );

        return self::$discoveryCache[$url] = (object) $decoded;
    }

    /**
     * Where the `.well-known/openid-configuration` document is fetched from.
     *
     * Defaults to the Solid server URL, which is how this has always behaved. The
     * config override exists for deployments that reach OIDC over a different host
     * than the data API — for instance HTTPS through a proxy for the handshake
     * while API calls stay on the internal HTTP address.
     *
     * @throws OpenIDConnectClientException
     */
    private function resolveDiscoveryBase(): string
    {
        $configured = self::config('solid.oidc.issuer') ?? self::config('solid.oidc_issuer');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if ($this->providerUrl !== null && $this->providerUrl !== '') {
            return $this->providerUrl;
        }

        if ($this->solid instanceof SolidClient) {
            return $this->solid->getServerUrl();
        }

        throw new OpenIDConnectClientException('No Solid provider URL is configured.');
    }

    /**
     * Merge explicit endpoint values over the discovery document.
     *
     * @param array<string, mixed> $config
     */
    public function providerConfigParam(array $config): self
    {
        $this->providerConfig = array_merge($this->providerConfig, $config);

        return $this;
    }

    /**
     * @throws OpenIDConnectClientException
     */
    public function getProviderConfigValue(string $param, mixed $default = null): mixed
    {
        if (array_key_exists($param, $this->providerConfig)) {
            return $this->providerConfig[$param];
        }

        $config = $this->getOpenIdConfiguration();

        if (property_exists($config, $param)) {
            return $config->{$param};
        }

        if ($default !== null) {
            return $default;
        }

        throw new OpenIDConnectClientException('The Solid provider does not advertise "' . $param . '".');
    }

    /**
     * Fail loudly rather than silently dropping PKCE.
     *
     * Solid-OIDC requires the extension, so a provider that advertises its
     * supported methods and does not list S256 cannot be used. A provider that
     * advertises nothing is given the benefit of the doubt: `code_challenge` is
     * simply ignored by a server that does not implement PKCE.
     *
     * @throws OpenIDConnectClientException
     */
    private function assertPkceSupported(): void
    {
        $supported = $this->getProviderConfigValue('code_challenge_methods_supported', []);

        if (is_array($supported) && $supported !== [] && !in_array($this->codeChallengeMethod, $supported, true)) {
            throw new OpenIDConnectClientException('The Solid provider does not support the ' . $this->codeChallengeMethod . ' PKCE method.');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Client credentials
    |--------------------------------------------------------------------------
    */

    private function applyClientCredentials(\stdClass $credentials): void
    {
        $this->clientId = (string) $credentials->client_id;

        if (isset($credentials->client_name) && is_string($credentials->client_name)) {
            $this->clientName = $credentials->client_name;
        }

        $this->clientSecret = isset($credentials->client_secret) && is_string($credentials->client_secret)
            ? $credentials->client_secret
            : null;
    }

    private function saveClientCredentials(string $clientName, \stdClass $credentials): void
    {
        // TTL 0 means "no expiry": a registration must outlive any single sign-in,
        // and re-registering on every login would leak clients on the provider.
        $this->stateStore->put(
            $this->clientCredentialsKey($clientName),
            (string) json_encode($credentials),
            self::intConfig('solid.oidc.client_credentials_ttl', 0)
        );
    }

    /**
     * @param array<string, mixed> $overwrite
     *
     * @throws OpenIDConnectClientException
     */
    public function restoreClientCredentials(string $clientName = CLIENT_NAME, array $overwrite = []): self
    {
        $saved = $this->stateStore->get($this->clientCredentialsKey($clientName));

        if (!is_string($saved) || $saved === '') {
            throw new OpenIDConnectClientException('No saved Solid client credentials to restore.');
        }

        $decoded = json_decode($saved, true);

        if (!is_array($decoded) || !isset($decoded['client_id'])) {
            throw new OpenIDConnectClientException('The saved Solid client credentials are unreadable.');
        }

        $this->applyClientCredentials((object) array_merge($decoded, $overwrite));

        return $this;
    }

    private function clientCredentialsKey(string $clientName): string
    {
        return 'client_credentials:' . Str::slug($clientName);
    }

    /**
     * Discard the registration and the DPoP key for this identity.
     *
     * Both are dropped together on purpose: the access tokens the caller is
     * abandoning are bound to that key, so keeping it serves no purpose, and
     * keeping the registration without the key would produce tokens we could not
     * prove possession for.
     */
    public function clearClientCredentials(): void
    {
        $this->stateStore->forget($this->clientCredentialsKey($this->clientName));
        $this->stateStore->forget('state');
        $this->stateStore->forget('nonce');
        $this->stateStore->forget('code_verifier');

        $this->clientId     = null;
        $this->clientSecret = null;

        try {
            $path = $this->dpopKeyPath();
            $disk = $this->dpopKeyDisk();

            if ($disk->exists($path)) {
                $disk->delete($path);
            }

            // Also any copy left on the default disk by an earlier version.
            if (Storage::exists($path)) {
                Storage::delete($path);
            }
        } catch (\Throwable $e) {
            Log::warning('[Solid OIDC] Unable to delete the stored DPoP key.', ['error' => $e->getMessage()]);
        }

        unset(self::$dpopKeyPairCache[$this->dpopCacheKey()]);
        $this->dpopKeyPair = null;

        Log::info('[Solid OIDC] Client credentials and DPoP key cleared.', [
            'identity_uuid' => $this->identity?->uuid,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | DPoP
    |--------------------------------------------------------------------------
    */

    /**
     * A DPoP proof for one request (RFC 9449).
     *
     * @throws OpenIDConnectClientException
     */
    public function createDPoP(string $method, string $url, ?string $accessToken = null, ?string $nonce = null): string
    {
        return $this->dpopKeyPair()->proof($method, $url, $accessToken, $nonce);
    }

    /**
     * The identity's DPoP key, loaded from storage or generated and persisted.
     *
     * @throws OpenIDConnectClientException
     */
    private function dpopKeyPair(): DPoPKeyPair
    {
        if ($this->dpopKeyPair instanceof DPoPKeyPair) {
            return $this->dpopKeyPair;
        }

        $cacheKey = $this->dpopCacheKey();

        if (isset(self::$dpopKeyPairCache[$cacheKey])) {
            return $this->dpopKeyPair = self::$dpopKeyPairCache[$cacheKey];
        }

        $keyPair = $this->loadDPoPKeyPair();

        if (!$keyPair instanceof DPoPKeyPair) {
            $keyPair = DPoPKeyPair::generate();
            $this->saveDPoPKeyPair($keyPair);
        }

        self::$dpopKeyPairCache[$cacheKey] = $keyPair;

        return $this->dpopKeyPair = $keyPair;
    }

    private function loadDPoPKeyPair(): ?DPoPKeyPair
    {
        try {
            $path = $this->dpopKeyPath();
            $disk = $this->dpopKeyDisk();

            if ($disk->exists($path)) {
                $decoded = json_decode((string) $disk->get($path), true);

                return DPoPKeyPair::fromArray(is_array($decoded) ? $decoded : null);
            }

            return $this->migrateLegacyDPoPKeyPair($path);
        } catch (\Throwable $e) {
            Log::warning('[Solid OIDC] Unable to read the stored DPoP key.', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Move a key written by an earlier version off the application's default disk.
     *
     * This used to be stored with a bare `Storage::put()`. In a Fleetbase install
     * the default disk is `public` — rooted at `storage/app/public`, symlinked to
     * `public/storage` and served over HTTP — or a cloud bucket, so the RSA
     * *private key* was written somewhere readable from outside the application.
     *
     * Rather than abandon it, which would invalidate every access token bound to
     * it and sign every Solid user out, the key is copied to the private disk and
     * the exposed copy is deleted.
     */
    private function migrateLegacyDPoPKeyPair(string $path): ?DPoPKeyPair
    {
        if (!Storage::exists($path)) {
            return null;
        }

        $decoded = json_decode((string) Storage::get($path), true);
        $keyPair = DPoPKeyPair::fromArray(is_array($decoded) ? $decoded : null);

        if (!$keyPair instanceof DPoPKeyPair) {
            return null;
        }

        $this->saveDPoPKeyPair($keyPair);

        try {
            Storage::delete($path);
        } catch (\Throwable $e) {
            Log::error('[Solid OIDC] A DPoP key remains on the default storage disk and must be deleted by hand.', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);
        }

        Log::info('[Solid OIDC] Moved a DPoP key off the default storage disk.', [
            'identity_uuid' => $this->identity?->uuid,
        ]);

        return $keyPair;
    }

    private function saveDPoPKeyPair(DPoPKeyPair $keyPair): void
    {
        try {
            $this->dpopKeyDisk()->put($this->dpopKeyPath(), (string) json_encode($keyPair->toArray() + [
                'created_at' => gmdate('c'),
            ]));
        } catch (\Throwable $e) {
            // Not fatal: the in-request key still works, it just will not be reused,
            // which invalidates the tokens bound to it on the next request.
            Log::error('[Solid OIDC] Unable to persist the DPoP key.', ['error' => $e->getMessage()]);
        }
    }

    /**
     * The disk DPoP private keys live on.
     *
     * Named explicitly rather than taken from `filesystems.default`, which in a
     * Fleetbase install is the web-served `public` disk or a cloud bucket. A
     * private signing key must not be reachable over HTTP.
     */
    private function dpopKeyDisk(): Filesystem
    {
        $disk = self::config('solid.oidc.key_disk', 'local');

        return Storage::disk(is_string($disk) && $disk !== '' ? $disk : 'local');
    }

    private function dpopKeyPath(): string
    {
        return $this->identity instanceof SolidIdentity
            ? 'solid/dpop_keys_' . $this->identity->uuid . '.json'
            : 'solid/dpop_keys.json';
    }

    private function dpopCacheKey(): string
    {
        return $this->identity instanceof SolidIdentity
            ? 'identity_' . $this->identity->uuid
            : 'global';
    }

    /*
    |--------------------------------------------------------------------------
    | Tokens and claims
    |--------------------------------------------------------------------------
    */

    /**
     * Verify a JWT's signature, issuer and audience against this provider.
     *
     * Kept for callers that used the previous method of the same name. It throws
     * on failure and only ever returns true, which is how the old override behaved.
     *
     * @throws OpenIDConnectClientException
     */
    public function verifyJWTSignature(string $jwt): bool
    {
        $this->verifyIdToken($jwt, null);

        return true;
    }

    /**
     * Decode one section of a JWT **without verifying it**.
     *
     * Section 0 is the header, 1 is the payload. Nothing security-relevant may be
     * decided from the result; use the claims returned by `getVerifiedClaims()`.
     */
    public function decodeJWTPublic(string $jwt, int $section = 0): ?object
    {
        $decoded = self::decodeJwtSection($jwt, $section);

        return is_array($decoded) ? (object) $decoded : null;
    }

    /**
     * The WebID for an ID token.
     *
     * When the token is the one this client just verified, the WebID comes from the
     * verified claims. Otherwise the token is decoded **without verification** —
     * that path exists because `PodService` reads ID tokens back out of the
     * database long after they have expired, so they can no longer be verified.
     * Prefer `SolidIdentity::getWebId()`, which reads the claims that were verified
     * at sign-in.
     */
    public function getWebIdFromIdToken(string $idToken): ?string
    {
        if ($idToken === '') {
            return null;
        }

        $claims = $this->idToken !== null && hash_equals($this->idToken, $idToken) && $this->verifiedClaims !== []
            ? $this->verifiedClaims
            : self::decodeJwtSection($idToken, 1);

        if (!is_array($claims)) {
            return null;
        }

        $webId = $claims['webid'] ?? $claims['sub'] ?? null;

        return is_string($webId) && $webId !== '' ? $webId : null;
    }

    /**
     * All claims from an ID token. Unverified unless the token is the one this
     * client verified — see `getWebIdFromIdToken()`.
     */
    public function getIdTokenClaims(?string $idToken = null): ?object
    {
        $token = $idToken ?? $this->idToken;

        if (!is_string($token) || $token === '') {
            return null;
        }

        if ($this->idToken !== null && hash_equals($this->idToken, $token) && $this->verifiedClaims !== []) {
            return (object) $this->verifiedClaims;
        }

        return $this->decodeJWTPublic($token, 1);
    }

    /**
     * The claims of the last ID token this client actually verified.
     *
     * @return array<string, mixed>
     */
    public function getVerifiedClaims(): array
    {
        return $this->verifiedClaims;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeJwtSection(string $jwt, int $section): ?array
    {
        $segments = explode('.', $jwt);

        if (!isset($segments[$section])) {
            return null;
        }

        $decoded = json_decode((string) base64_decode(strtr($segments[$section], '-_', '+/'), true), true);

        return is_array($decoded) ? $decoded : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    public function setProviderURL(string $providerUrl): self
    {
        $this->providerUrl = $providerUrl;

        return $this;
    }

    public function getProviderURL(): ?string
    {
        return $this->providerUrl;
    }

    public function setIssuer(string $issuer): self
    {
        $this->issuer = $issuer;

        return $this;
    }

    public function getIssuer(): ?string
    {
        return $this->issuer;
    }

    public function setRedirectURL(?string $redirectUri): self
    {
        $this->redirectUri = $redirectUri;

        return $this;
    }

    public function getRedirectURL(): ?string
    {
        return $this->redirectUri;
    }

    public function setClientID(string $clientId): self
    {
        $this->clientId = $clientId;

        return $this;
    }

    public function getClientID(): ?string
    {
        return $this->clientId;
    }

    public function setClientSecret(string $clientSecret): self
    {
        $this->clientSecret = $clientSecret;

        return $this;
    }

    public function getClientSecret(): ?string
    {
        return $this->clientSecret;
    }

    public function setClientName(string $clientName): self
    {
        $this->clientName = $clientName;

        return $this;
    }

    public function getClientName(): string
    {
        return $this->clientName;
    }

    /**
     * @param array<int, string> $scopes
     */
    public function addScope(array $scopes): self
    {
        // Deduplicated: the previous version appended to a list that already held
        // the same values, producing `openid webid offline_access openid`.
        $this->scopes = array_values(array_unique(array_merge($this->scopes, $scopes)));

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function setCodeChallengeMethod(string $method): self
    {
        $this->codeChallengeMethod = $method;

        return $this;
    }

    public function getCodeChallengeMethod(): string
    {
        return $this->codeChallengeMethod;
    }

    /**
     * The PKCE verifier for the pending authorization request, if any.
     */
    public function getCodeVerifier(): ?string
    {
        return $this->stateStore->get('code_verifier');
    }

    public function setCode(string $code): self
    {
        $this->code = $code;

        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setIdToken(?string $idToken): self
    {
        $this->idToken = $idToken;

        // Claims verified for a different token must not be attributed to this one.
        $this->verifiedClaims = [];

        return $this;
    }

    public function getIdToken(): ?string
    {
        return $this->idToken;
    }

    public function getAccessToken(): ?string
    {
        return $this->accessToken;
    }

    public function getRefreshToken(): ?string
    {
        return $this->refreshToken;
    }

    public function getTokenResponse(): ?\stdClass
    {
        return $this->tokenResponse;
    }

    /**
     * @param array<int, string> $supported
     */
    public function supportsAuthMethod(string $method, array $supported): bool
    {
        return in_array($method, $supported, true);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * A provider endpoint, asserted to be a string.
     *
     * A discovery document is remote input: an endpoint that arrives as a number,
     * an array or null must fail here rather than be stringified into a request.
     *
     * @throws OpenIDConnectClientException
     */
    private function stringProviderConfigValue(string $param): string
    {
        $value = $this->getProviderConfigValue($param);

        if (!is_string($value) || $value === '') {
            throw new OpenIDConnectClientException('The Solid provider advertises an unusable "' . $param . '".');
        }

        return $value;
    }

    /**
     * A string option, or null when it is absent or of the wrong type.
     *
     * @param array<string, mixed> $options
     */
    private static function stringOption(array $options, string $key): ?string
    {
        $value = $options[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * RFC 7636 S256: base64url(sha256(verifier)), unpadded.
     *
     * Matches `Fleetbase\Auth\OAuth\Socialite\Concerns\ServerSidePkce::serverCodeChallenge()`.
     */
    private static function codeChallenge(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }

    /**
     * @throws OpenIDConnectClientException
     */
    private static function randomToken(int $bytes = 16): string
    {
        try {
            return bin2hex(random_bytes(max(1, $bytes)));
        } catch (\Throwable $e) {
            throw new OpenIDConnectClientException('No cryptographically secure randomness is available.', 0, $e);
        }
    }

    /**
     * Whether TLS peer verification is on for the handshake.
     *
     * A single flag rather than a check on the app environment. The previous
     * implementation turned verification off whenever the environment was `local`
     * or `development`, which is the pattern Core API's `IdTokenVerifier`
     * deliberately does not repeat — it also disables it in any environment an
     * operator happens to name that way.
     */
    public static function shouldVerifyTls(): bool
    {
        return (bool) self::config('solid.oidc.verify_tls', true);
    }

    private static function timeout(): int
    {
        return self::intConfig('solid.oidc.timeout', 15);
    }

    private static function intConfig(string $key, int $default): int
    {
        $value = self::config($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * `Cache::remember()` when a cache is available and a positive TTL is
     * configured, and a plain call otherwise — so a unit test with no container,
     * or an operator who sets the TTL to 0, simply skips the cache.
     *
     * @template TValue
     *
     * @param \Closure(): TValue $resolve
     *
     * @return TValue
     */
    private static function cacheRemember(string $key, int $ttl, \Closure $resolve): mixed
    {
        if ($ttl <= 0 || !self::containerHas('cache')) {
            return $resolve();
        }

        return Cache::remember($key, $ttl, $resolve);
    }

    /**
     * Reads config without requiring a booted container, so the handshake can be
     * exercised in a plain unit test.
     */
    private static function config(string $key, mixed $default = null): mixed
    {
        if (!self::containerHas('config') || !function_exists('config')) {
            return $default;
        }

        return config($key, $default);
    }

    /**
     * Whether a service is bound on the container.
     *
     * `Container::getInstance()` creates an empty container rather than failing
     * when the framework has not booted, so nothing here needs a try/catch.
     */
    private static function containerHas(string $abstract): bool
    {
        return Container::getInstance()->bound($abstract);
    }
}
