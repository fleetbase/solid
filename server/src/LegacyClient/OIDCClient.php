<?php

namespace Fleetbase\Solid\LegacyClient;

use Fleetbase\Support\Utils;
use Illuminate\Support\Facades\Redis;
use IlluminateAgnostic\Str\Support\Str;
use Jose\Component\Checker\AlgorithmChecker;
use Jose\Component\Checker\HeaderCheckerManager;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Core\JWKSet;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\ES384;
use Jose\Component\Signature\Algorithm\ES512;
use Jose\Component\Signature\Algorithm\HS256;
use Jose\Component\Signature\Algorithm\HS384;
use Jose\Component\Signature\Algorithm\HS512;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\Algorithm\RS384;
use Jose\Component\Signature\Algorithm\RS512;
use Jose\Component\Signature\JWS;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\JWSTokenSupport;
use Jose\Component\Signature\JWSVerifier;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Jose\Component\Signature\Serializer\JWSSerializerManager;
use Jumbojett\OpenIDConnectClient;
use Jumbojett\OpenIDConnectClientException;

final class OIDCClient extends OpenIDConnectClient
{
    public const CLIENT_NAME     = 'Fleetbase';
    private ?JWK $dpopPrivateKey = null;
    private ?string $redirectURL = null;
    protected $refreshToken;
    protected $idToken;
    protected $tokenResponse;

    /**
     * @param      $provider_url  string optional
     * @param      $client_id     string optional
     * @param      $client_secret string optional
     * @param null $issuer
     */
    public function __construct($provider_url = null, $client_id = null, $client_secret = null, $issuer = null)
    {
        $this->setProviderURL($provider_url);
        if ($issuer === null) {
            $this->setIssuer($provider_url);
        } else {
            $this->setIssuer($issuer);
        }

        // $this->setDefaultRedirectURL();
        $this->setCodeChallengeMethod('S256');
        $this->setClientID($client_id);
        $this->setClientSecret($client_secret);
    }

    public static function create(SolidClient $solidClient): self
    {
        $oidcConfig = $solidClient->getOpenIdConfiguration();
        $oidcClient = new self(data_get($oidcConfig, 'issuer'));
        $oidcClient->providerConfigParam($oidcConfig);

        return $oidcClient;
    }

    public function authenticate(): bool
    {
        $this->setCodeChallengeMethod('S256');
        $this->addScope(['openid', 'webid', 'offline_access']);

        return parent::authenticate();
    }

    public function setClientCredentials(string $clientName = self::CLIENT_NAME, $clientCredentials): ?self
    {
        $clientId     = data_get($clientCredentials, 'client_id');
        $clientSecret = data_get($clientCredentials, 'client_secret');

        $this->setClientName($clientName);
        $this->setClientID($clientId);
        $this->setClientSecret($clientSecret);
        $this->storeClientCredentials($clientName, $clientCredentials);

        return $this;
    }

    private function storeClientCredentials(?string $clientName = self::CLIENT_NAME, $clientCredentials): void
    {
        Redis::set('oidc:client:' . Str::slug($clientName), json_encode($clientCredentials));
    }

    public function getClientCredentials(string $clientName = self::CLIENT_NAME)
    {
        $clientCredentialsString = Redis::get('oidc:client:' . Str::slug($clientName));
        if (Utils::isJson($clientCredentialsString)) {
            return json_decode($clientCredentialsString, false);
        }

        return null;
    }

    public function restoreClientCredentials(string $clientName = self::CLIENT_NAME): ?self
    {
        $clientCredentials = $this->getClientCredentials($clientName);
        if ($clientCredentials) {
            return $this->setClientCredentials($clientName, $clientCredentials);
        }

        return null;
    }

    public function verifyJWTsignature($jwt): bool
    {
        $this->decodeToken($jwt);

        return true;
    }

    public function requestTokens($code, $headers = [])
    {
        $headers[] = 'DPoP: ' . $this->createDPoP('POST', $this->getProviderConfigValue('token_endpoint'), false);

        return parent::requestTokens($code, $headers);
    }

    // https://base64.guru/developers/php/examples/base64url
    private function base64urlEncode(string $data): string
    {
        // First of all you should encode $data to Base64 string
        $b64 = base64_encode($data);

        // Convert Base64 to Base64URL by replacing “+” with “-” and “/” with “_”
        $url = strtr($b64, '+/', '-_');

        // Remove padding character from the end of line and return the Base64URL result
        return rtrim($url, '=');
    }

    public function createDPoP(string $method, string $url, bool $includeAth = true, string $accessToken = null): string
    {
        if (null === $this->dpopPrivateKey) {
            $this->dpopPrivateKey = JWKFactory::createECKey('P-256', ['use' => 'sig', 'kid' => base64_encode(random_bytes(20))]);
        }

        $jwsBuilder = new JWSBuilder(new AlgorithmManager([new ES256()]));

        $arrayPayload = [
            'htu' => strtok($url, '?'),
            'htm' => $method,
            'jti' => base64_encode(random_bytes(20)),
            'iat' => time(),
        ];
        if ($includeAth) {
            if (!$accessToken) {
                $accessToken = $this->getAccessToken();
            }
            $arrayPayload['ath'] = $this->base64urlEncode(hash('sha256', $accessToken));
        }

        $payload = json_encode($arrayPayload, \JSON_THROW_ON_ERROR);

        $jws = $jwsBuilder
            ->create()
            ->withPayload($payload)
            ->addSignature(
                $this->dpopPrivateKey,
                [
                    'alg' => 'ES256',
                    'typ' => 'dpop+jwt',
                    'jwk' => $this->dpopPrivateKey->toPublic()->jsonSerialize(),
                ]
            )
            ->build();

        return (new CompactSerializer())->serialize($jws, 0);
    }

    public function decodeToken(string $jwt): JWS
    {
        try {
            $jwks = JWKSet::createFromJson($this->fetchURL($this->getProviderConfigValue('jwks_uri')));
        } catch (\Exception $e) {
            throw new OpenIDConnectClientException('Invalid JWKS: ' . $e->getMessage(), $e->getCode(), $e);
        }

        $headerCheckerManager = new HeaderCheckerManager(
            [new AlgorithmChecker(['RS256', 'RS384', 'R512', 'HS256', 'HS384', 'HS512', 'ES256', 'ES384', 'ES512'])], // TODO: read this from the provider config
            [new JWSTokenSupport()],
        );

        $algorithmManager = new AlgorithmManager([
            new RS256(),
            new RS384(),
            new RS512(),
            new HS256(),
            new HS384(),
            new HS512(),
            new ES256(),
            new ES384(),
            new ES512(),
        ]);

        $serializerManager = new JWSSerializerManager([new CompactSerializer()]);
        $jws               = $serializerManager->unserialize($jwt);

        try {
            $headerCheckerManager->check($jws, 0);
        } catch (\Exception $e) {
            throw new OpenIDConnectClientException('Invalid JWT header: ' . $e->getMessage(), $e->getCode(), $e);
        }

        $jwsVerifier = new JWSVerifier($algorithmManager);
        if (!$jwsVerifier->verifyWithKeySet($jws, $jwks, 0)) {
            throw new OpenIDConnectClientException('Invalid JWT signature.');
        }

        return $jws;
    }

    public function getRedirectURL()
    {
        // If the redirect URL has been set then return it.
        if (property_exists($this, 'redirectURL') && $this->redirectURL) {
            return $this->redirectURL;
        }

        // // Get current solid identity
        // $solidIdentity = SolidIdentity::current();
        // return Utils::apiUrl('solid/int/v1/oidc/complete-registration/' . $solidIdentity->request_code, [], 8000);
    }

    public function setDefaultRedirectURL(string $redirectURL)
    {
        $this->setRedirectURL($redirectURL);
    }

    public function setAccessToken($accessToken)
    {
        $this->accessToken = $accessToken;
        Redis::set('oidc:client:access_token', $accessToken);
    }

    public function setIdToken($idToken)
    {
        $this->idToken = $idToken;
        Redis::set('oidc:client:id_token', $idToken);
    }

    public function setRefreshToken($refreshToken)
    {
        $this->refreshToken = $refreshToken;
        Redis::set('oidc:client:refresh_token', $refreshToken);
    }

    public function setTokenResponse($tokenResponse)
    {
        $this->tokenResponse = $tokenResponse;
        Redis::set('oidc:client:token_response', json_encode($tokenResponse));
    }

    public function storeTokens()
    {
        $idToken = $this->getIdToken();
        if ($idToken) {
            Redis::set('oidc:client:id_token', $idToken);
        }

        $accessToken = $this->getAccessToken();
        if ($accessToken) {
            Redis::set('oidc:client:access_token', $accessToken);
        }

        $refreshToken = $this->getRefreshToken();
        if ($refreshToken) {
            Redis::set('oidc:client:refresh_token', $refreshToken);
        }
        $tokenResponse = $this->getTokenResponse();
        if ($tokenResponse) {
            Redis::set('oidc:client:token_response', json_encode($tokenResponse));
        }

        return $this;
    }

    public function getAccessToken(): ?string
    {
        $accessToken = parent::getAccessToken();
        if (!$accessToken) {
            $accessToken = Redis::get('oidc:client:access_token');
        }

        return $accessToken;
    }

    public function getRefreshToken(): ?string
    {
        $refreshToken = parent::getRefreshToken();
        if (!$refreshToken) {
            $refreshToken = Redis::get('oidc:client:refresh_token');
        }

        return $refreshToken;
    }

    public function getIdToken()
    {
        $idToken = parent::getIdToken();
        if (!$idToken) {
            $idToken = Redis::get('oidc:client:id_token');
        }

        return $idToken;
    }

    public function getTokenResponse()
    {
        $tokenResponse = parent::getTokenResponse();
        if (!$tokenResponse) {
            $tokenResponse = Redis::get('oidc:client:token_response');
            if (is_string($tokenResponse)) {
                $tokenResponse = json_decode($tokenResponse);
            }
        }

        return $tokenResponse;
    }

    public function restoreTokens()
    {
        $idToken       = Redis::get('oidc:client:id_token');
        $accessToken   = Redis::get('oidc:client:access_token');
        $refreshToken  = Redis::get('oidc:client:refresh_token');
        $tokenResponse = Redis::get('oidc:client:token_response');

        $this->setIdToken($idToken);
        $this->setAccessToken($accessToken);
        $this->setRefreshToken($refreshToken);
        $this->setTokenResponse($tokenResponse);
    }

    public function clearStoredClient(?string $clientName = self::CLIENT_NAME)
    {
        Redis::del('oidc:client:' . Str::slug($clientName));
    }
}
