<?php

namespace Fleetbase\Solid\Client;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Fleetbase\Solid\Models\SolidIdentity;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Jumbojett\OpenIDConnectClient as BaseOpenIDConnectClient;
use Jumbojett\OpenIDConnectClientException;

const CLIENT_NAME     = 'Fleetbase-v2';  // v2: Added webid scope support
final class OpenIDConnectClient extends BaseOpenIDConnectClient
{
    private ?SolidClient $solid;
    private ?SolidIdentity $identity;
    private ?\stdClass $openIdConfig;
    private string $code;
    private static $dpopKeyPairCache = [];

    public function __construct(array $options = [])
    {
        // Call parent constructor to initialize the OIDC client properly
        // Pass null for provider_url as we'll set it later in create()
        parent::__construct(null, null, null, null);
        
        $this->solid    = data_get($options, 'solid');
        $this->identity = data_get($options, 'identity');
        if ($this->identity instanceof SolidIdentity) {
            $this->setRedirectURL($this->identity->getRedirectUri());
        }
        $this->setCodeChallengeMethod('S256');
        $this->setClientName(data_get($options, 'clientName', CLIENT_NAME));
        
        // Only set client credentials if they are provided
        $clientID = data_get($options, 'clientID');
        if ($clientID !== null) {
            $this->setClientID($clientID);
        }
        
        $clientSecret = data_get($options, 'clientSecret');
        if ($clientSecret !== null) {
            $this->setClientSecret($clientSecret);
        }

        // Restore client credentials if requested or if identity is provided without clientID
        if (isset($options['restore']) || ($this->identity instanceof SolidIdentity && $clientID === null)) {
            try {
                $this->restoreClientCredentials();
            } catch (\Exception $e) {
                // Credentials not yet saved, which is fine during initial registration
                Log::debug('[OIDC] Client credentials not yet available for restoration', [
                    'identity_uuid' => $this->identity?->uuid,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public static function create(array $options = []): OpenIDConnectClient
    {
        $client       = new static($options);
        $solid        = data_get($options, 'solid');
        
        // For OIDC discovery, use the configured OIDC issuer URL
        // This allows using HTTPS for OIDC (through nginx) while using HTTP for API calls
        if ($solid instanceof \Fleetbase\Solid\Client\SolidClient) {
            $oidcIssuer = config('solid.oidc_issuer', $solid->getServerUrl());
            $client->setProviderURL($oidcIssuer);
            Log::debug('[OIDC] Using provider URL for discovery', [
                'provider_url' => $oidcIssuer,
                'config_value' => config('solid.oidc_issuer'),
                'fallback' => $solid->getServerUrl(),
            ]);
        }
        
        Log::debug('[OIDC] About to fetch configuration', [
            'provider_url_before_fetch' => $client->getProviderURL(),
        ]);
        
        $openIdConfig = $client->getOpenIdConfiguration();
        
        Log::debug('[OIDC] Received configuration', [
            'config' => $openIdConfig,
            'has_issuer' => isset($openIdConfig->issuer),
            'issuer' => $openIdConfig->issuer ?? 'NOT SET',
        ]);
        
        if (!isset($openIdConfig->issuer)) {
            throw new \Exception('OIDC configuration does not contain issuer property. Response: ' . json_encode($openIdConfig));
        }
        
        $client->setProviderURL($openIdConfig->issuer);
        $client->setIssuer($openIdConfig->issuer);
        $client->providerConfigParam((array) $openIdConfig);

        return $client;
    }

    protected function fetchURL(string $url, string $post_body = null, array $headers = [])
    {
        Log::debug('[OIDC] fetchURL called', [
            'url' => $url,
            'has_post_body' => $post_body !== null,
            'provider_url' => $this->getProviderURL(),
        ]);
        
        // For development: disable SSL verification when connecting to local Solid server
        // This allows HTTPS connections to work with self-signed certificates
        $ch = curl_init();
        
        if ($post_body !== null) {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_body);
            $content_type = is_object(json_decode($post_body, false)) ? 'application/json' : 'application/x-www-form-urlencoded';
            $headers[] = "Content-Type: $content_type";
        }
        
        if (count($headers) > 0) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        
        // Disable SSL verification for development (self-signed certificates)
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->getUserAgent());
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            Log::error('[OIDC] cURL error', ['error' => $error, 'url' => $url]);
            throw new \Exception("cURL error: $error");
        }
        
        return $response;
    }

    public function register(array $options = []): OpenIDConnectClient
    {
        // Get registration options
        $clientName        = (string) data_get($options, 'clientName', CLIENT_NAME);
        $requestParams     = (array) data_get($options, 'requestParams', []);
        $requestOptions    = (array) data_get($options, 'requestOptions', []);
        $redirectUri       = (string) data_get($options, 'redirectUri', $this->identity ? $this->identity->getRedirectUri() : null);
        $saveCredentials   = (bool) data_get($options, 'saveCredentials', false);
        $withCredentials   = data_get($options, 'withCredentials');

        // Get OIDC Config and Registration URL
        $openIdConfig      = $this->getOpenIdConfiguration();

        // Setup OIDC Client
        $this->setIssuer($openIdConfig->issuer);
        $this->providerConfigParam((array) $openIdConfig);
        $this->setRedirectURL($redirectUri);

        // Get Registration URL
        $registrationUrl = $openIdConfig->registration_endpoint;

        // Request registration for Client which should handle authentication
        // Include scope in registration to ensure client is allowed to request these scopes
        $registrationResponse = $this->solid->post($registrationUrl, [
            'client_name' => $clientName,
            'redirect_uris' => [$redirectUri],
            'scope' => 'openid webid offline_access',
            ...$requestParams
        ], $requestOptions);
        if ($registrationResponse->successful()) {
            $clientCredentials = (object) $registrationResponse->json();
            $this->setClientCredentials($clientName, $clientCredentials, $saveCredentials, $withCredentials);
        } else {
            Log::error('[CLIENT REGISTRATION FAILED]', [
                'status' => $registrationResponse->status(),
                'body' => $registrationResponse->body(),
                'request_data' => [
                    'client_name' => $clientName,
                    'redirect_uris' => [$redirectUri],
                    'scope' => 'openid webid offline_access',
                ],
            ]);
            throw new OpenIDConnectClientException('Error registering: Please contact the OpenID Connect provider and obtain a Client ID and Secret directly from them');
        }

        return $this;
    }

    public function authenticate(): bool
    {
        $this->setCodeChallengeMethod('S256');
        $this->addScope(['openid', 'webid', 'offline_access']);

        Log::info('[AUTHENTICATE]', [
            'scopes' => $this->getScopes(),
            'note' => 'Scopes will be included in authorization redirect',
        ]);

        return parent::authenticate();
    }

    /**
     * Override requestTokens to inject DPoP header for token endpoint.
     * 
     * Note: Per OIDC spec (and Inrupt implementation), scope is sent in the authorization
     * request, NOT in the token request. CSS should remember the granted scope.
     */
    protected function requestTokens(string $code, array $headers = [])
    {
        $tokenEndpoint = $this->getProviderConfigValue('token_endpoint');
        $tokenEndpointAuthMethodsSupported = $this->getProviderConfigValue('token_endpoint_auth_methods_supported', ['client_secret_basic']);
        
        // Create DPoP proof for the token endpoint
        $dpop = $this->createDPoP('POST', $tokenEndpoint, null);
        $headers[] = 'DPoP: ' . $dpop;
        
        // Build token request parameters (scope NOT included per OIDC spec - it's in authorization request)
        $tokenParams = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->getRedirectURL(),
            'client_id' => $this->getClientID(),
            'client_secret' => $this->getClientSecret(),
        ];
        
        Log::info('[REQUEST TOKENS WITH DPOP]', [
            'token_endpoint' => $tokenEndpoint,
            'expected_scope' => implode(' ', $this->getScopes()),
            'dpop_length' => strlen($dpop),
            'note' => 'Scope sent in authorization request, not token request per OIDC spec',
        ]);
        
        // Handle different authentication methods
        $authorizationHeader = null;
        if ($this->supportsAuthMethod('client_secret_basic', $tokenEndpointAuthMethodsSupported)) {
            $authorizationHeader = 'Authorization: Basic ' . base64_encode(urlencode($this->getClientID()) . ':' . urlencode($this->getClientSecret()));
            unset($tokenParams['client_secret'], $tokenParams['client_id']);
        }
        
        // Add PKCE code verifier if using PKCE
        $ccm = $this->getCodeChallengeMethod();
        $cv = $this->getCodeVerifier();
        if (!empty($ccm) && !empty($cv)) {
            $cs = $this->getClientSecret();
            if (empty($cs)) {
                $authorizationHeader = null;
                unset($tokenParams['client_secret']);
            }
            $tokenParams = array_merge($tokenParams, [
                'client_id' => $this->getClientID(),
                'code_verifier' => $this->getCodeVerifier()
            ]);
        }
        
        // Convert token params to string format
        $tokenParams = http_build_query($tokenParams, '', '&', $this->encType);
        
        if (null !== $authorizationHeader) {
            $headers[] = $authorizationHeader;
        }
        
        // Make the token request
        $rawResponse = $this->fetchURL($tokenEndpoint, $tokenParams, $headers);
        $this->tokenResponse = json_decode($rawResponse, false);
        
        Log::info('[TOKEN RESPONSE FROM CSS]', [
            'has_access_token' => isset($this->tokenResponse->access_token),
            'has_id_token' => isset($this->tokenResponse->id_token),
            'has_refresh_token' => isset($this->tokenResponse->refresh_token),
            'token_type' => $this->tokenResponse->token_type ?? null,
            'scope_in_response' => $this->tokenResponse->scope ?? null,
            'raw_response_length' => strlen($rawResponse),
        ]);
        
        return $this->tokenResponse;
    }

    private function setClientCredentials(string $clientName = CLIENT_NAME, $clientCredentials, bool $save = false, ?\Closure $callback = null): OpenIDConnectClient
    {
        $this->setClientID($clientCredentials->client_id);
        $this->setClientName($clientCredentials->client_name);
        $this->setClientSecret($clientCredentials->client_secret);

        // Save the client credentials
        if ($save) {
            $this->saveClientCredentials($clientName, $clientCredentials);
        }

        // Run callback if provided
        if (is_callable($callback)) {
            $callback($clientCredentials);
        }

        return $this;
    }

    private function saveClientCredentials(string $clientName = CLIENT_NAME, $clientCredentials): OpenIDConnectClient
    {
        $key = $this->getClientCredentialsKey($clientName);

        return $this->save($key, $clientCredentials);
    }

    public function restoreClientCredentials(string $clientName = CLIENT_NAME, array $overwrite = []): OpenIDConnectClient
    {
        $key                    = $this->getClientCredentialsKey($clientName);
        $savedClientCredentials = $this->retrieve($key);
        if (!$savedClientCredentials) {
            throw new \Exception('No saved client credentials to restore.');
        }

        $restoredClientCredentials = (object) array_merge((array) $savedClientCredentials, $overwrite);
        $this->setClientCredentials($clientName, $restoredClientCredentials);

        return $this;
    }

    private function getClientCredentialsKey(string $clientName = CLIENT_NAME): string
    {
        if ($this->identity instanceof SolidIdentity) {
            return 'oidc:client_credentials:' . Str::slug($clientName) . ':' . $this->identity->uuid;
        }

        return 'oidc:client_credentials:' . Str::slug($clientName);
    }

    private function save(string $key, $value): OpenIDConnectClient
    {
        if (is_object($value) || is_array($value)) {
            $value = json_encode($value);
        }

        Redis::set($key, $value);

        return $this;
    }

    private function retrieve(string $key)
    {
        $value = Redis::get($key);
        if (Str::isJson($value)) {
            $value = (object) json_decode($value);
        }

        return $value;
    }

    public function getOpenIdConfiguration(?string $key = null)
    {
        $openIdConfigResponse = $this->solid->get('.well-known/openid-configuration');
        if ($openIdConfigResponse instanceof Response) {
            $openIdConfig = (object) $openIdConfigResponse->json();
            if ($key) {
                return $openIdConfig->{$key};
            }

            $this->openIdConfig = $openIdConfig;

            return $openIdConfig;
        }

        return null;
    }

    protected function getSessionKey(string $key)
    {
        $sessionKey = 'oidc:session:' . ($this->identity ? $this->identity->identifier : 'default') . ':' . Str::slug($key);
        if (Redis::exists($sessionKey)) {
            return $this->retrieve($sessionKey);
        }

        return false;
    }

    protected function setSessionKey(string $key, $value)
    {
        $sessionKey = 'oidc:session:' . ($this->identity ? $this->identity->identifier : 'default') . ':' . Str::slug($key);
        $this->save($sessionKey, $value);
    }

    protected function unsetSessionKey(string $key)
    {
        $sessionKey = 'oidc:session:' . ($this->identity ? $this->identity->identifier : 'default') . ':' . Str::slug($key);
        Redis::del($sessionKey);
    }

    protected function getAllSessionKeysWithValues()
    {
        $pattern = 'oidc:session*'; // Pattern to match keys
        $keys    = [];
        $cursor  = 0;

        do {
            // Use the SCAN command to find keys matching the pattern
            [$cursor, $results] = Redis::scan($cursor, 'MATCH', $pattern);

            foreach ($results as $key) {
                // Retrieve each value and add it to the keys array
                $keys[$key] = Redis::get($key);
            }
        } while ($cursor);

        return $keys;
    }

    public function verifyJWTSignature(string $jwt): bool
    {
        $jwks = json_decode($this->fetchURL($this->getProviderConfigValue('jwks_uri')), true);
        if (!is_array($jwks)) {
            throw new OpenIDConnectClientException('Error decoding JSON from jwks_uri');
        }

        try {
            JWT::decode($jwt, JWK::parseKeySet($jwks));
        } catch (\Exception $e) {
            throw new OpenIDConnectClientException('Error decoding JWT: ' . $e->getMessage(), $e->getCode(), $e);
        }

        return true;
    }

    /**
     * Set the ID token.
     */
    public function setIdToken($idToken)
    {
        $this->idToken = $idToken;
    }

    /**
     * Public wrapper for decodeJWT.
     */
    public function decodeJWTPublic(string $jwt, int $section = 0)
    {
        return $this->decodeJWT($jwt, $section);
    }

    // /**
    //  * Get WebID from ID token.
    //  */
    // public function getWebIdFromIdToken(?string $idToken = null): ?string
    // {
    //     $token = $idToken ?? $this->getIdToken();

    //     if (!$token) {
    //         return null;
    //     }

    //     try {
    //         $claims = $this->decodeJWT($token, 1);

    //         return $claims->sub ?? $claims->webid ?? null;
    //     } catch (\Exception $e) {
    //         return null;
    //     }
    // }

    /**
     * Get WebID from ID token with enhanced error handling.
     */
    public function getWebIdFromIdToken(string $idToken): ?string
    {
        $token = $idToken ?? $this->getIdToken();
        if (!$token) {
            return null;
        }

        try {
            Log::info('[EXTRACTING WEBID FROM ID TOKEN]', [
                'token_length' => strlen($idToken),
            ]);

            $payload = $this->decodeJWT($idToken, 1);
            $webId   = $payload->webid ?? $payload->sub ?? null;

            Log::info('[WEBID EXTRACTED]', [
                'webid'        => $webId,
                'payload_keys' => array_keys((array) $payload),
            ]);

            return $webId;
        } catch (\Throwable $e) {
            Log::error('[WEBID EXTRACTION ERROR]', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get all claims from ID token.
     */
    public function getIdTokenClaims(?string $idToken = null): ?object
    {
        $token = $idToken ?? $this->getIdToken();

        if (!$token) {
            return null;
        }

        try {
            return $this->decodeJWT($token, 1);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Create DPoP token with proper signing and debugging.
     */
    public function createDPoP(string $method, string $url, ?string $accessToken = null): string
    {
        try {
            Log::info('[CREATING DPOP TOKEN]', [
                'method'           => strtolower($method),
                'url'              => $url,
                'has_access_token' => $accessToken !== null,
            ]);

            // Load (or generate) keypair
            $keyPair = $this->getDPoPKeyPair();
            
            if (!$keyPair || empty($keyPair['private_key']) || empty($keyPair['public_jwk'])) {
                throw new \Exception('DPoP key pair unavailable');
            }

            $header = [
                'typ' => 'dpop+jwt',
                'alg' => 'RS256',
                'jwk' => $keyPair['public_jwk'],
            ];

            $now   = time();
            $jti   = bin2hex(random_bytes(16));
            $htm   = strtoupper($method);
            $htu   = $url;

            $payload = [
                'jti' => $jti,
                'htm' => $htm,
                'htu' => $htu,
                'iat' => $now,
            ];

            // Only include 'ath' when we are binding a proof *for a resource request* or a refresh that already has an access token.
            if (!empty($accessToken)) {
                $payload['ath'] = self::generateAccessTokenHash($accessToken);
            }

            // Sign (RS256) with our private key
            $privateKey = openssl_pkey_get_private($keyPair['private_key']);
            if (!$privateKey) {
                throw new \Exception('Failed to load DPoP private key');
            }

            $encodedHeader  = rtrim(strtr(base64_encode(json_encode($header)), '+/', '-_'), '=');
            $encodedPayload = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
            $signingInput   = $encodedHeader . '.' . $encodedPayload;

            $signature = '';
            if (!openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
                throw new \Exception('Failed to sign DPoP proof');
            }

            $encodedSignature = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
            $jwt              = $encodedHeader . '.' . $encodedPayload . '.' . $encodedSignature;

            Log::info('[DPOP TOKEN CREATED]', [
                'token_length'   => strlen($jwt),
                'token_preview'  => substr($jwt, 0, 80),
                'has_placeholder'=> false,
            ]);
            
            // Debug: Log full DPoP payload for diagnosis
            Log::debug('[DPOP PAYLOAD]', [
                'jti' => $jti,
                'htm' => $htm,
                'htu' => $htu,
                'iat' => $now,
                'ath' => $payload['ath'] ?? null,
                'has_ath' => isset($payload['ath']),
            ]);

            return $jwt;
        } catch (\Throwable $e) {
            Log::error('[DPOP ERROR]', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Generate or load DPoP key pair.
     */
    private function getDPoPKeyPair(): ?array
    {
        $cacheKey = $this->getDPoPCacheKey();
        
        if (isset(self::$dpopKeyPairCache[$cacheKey])) {
            return self::$dpopKeyPairCache[$cacheKey];
        }

        try {
            // Try to load existing key pair
            $keyPair = $this->loadDPoPKeyPair();

            if (!$keyPair) {
                // Generate new key pair
                $keyPair = self::generateDPoPKeyPair();

                if ($keyPair) {
                    $this->saveDPoPKeyPair($keyPair);
                }
            }

            self::$dpopKeyPairCache[$cacheKey] = $keyPair;

            Log::info('[DPOP KEY PAIR LOADED]', [
                'has_private_key' => isset($keyPair['private_key']),
                'has_public_jwk'  => isset($keyPair['public_jwk']),
                'identity_uuid' => $this->identity?->uuid,
            ]);

            return $keyPair;
        } catch (\Throwable $e) {
            Log::error('[DPOP KEY PAIR ERROR]', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Generate new DPoP key pair.
     */
    private static function generateDPoPKeyPair(): ?array
    {
        try {
            // Generate RSA key pair
            $config = [
                'digest_alg'       => 'sha256',
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ];

            $resource = openssl_pkey_new($config);

            if (!$resource) {
                throw new \Exception('Failed to generate RSA key pair');
            }

            // Export private key
            openssl_pkey_export($resource, $privateKey);

            // Get public key details
            $publicKeyDetails = openssl_pkey_get_details($resource);
            $publicKey        = $publicKeyDetails['key'];

            // Create JWK for public key
            $publicJwk = [
                'kty' => 'RSA',
                'n'   => rtrim(strtr(base64_encode($publicKeyDetails['rsa']['n']), '+/', '-_'), '='),
                'e'   => rtrim(strtr(base64_encode($publicKeyDetails['rsa']['e']), '+/', '-_'), '='),
            ];

            Log::info('[DPOP KEY PAIR GENERATED]', [
                'private_key_length' => strlen($privateKey),
                'public_key_length'  => strlen($publicKey),
                'jwk_keys'           => array_keys($publicJwk),
            ]);

            return [
                'private_key' => $privateKey,
                'public_key'  => $publicKey,
                'public_jwk'  => $publicJwk,
            ];
        } catch (\Throwable $e) {
            Log::error('[DPOP KEY GENERATION ERROR]', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Load DPoP key pair from storage.
     */
    private function loadDPoPKeyPair(): ?array
    {
        try {
            $keyPath = $this->getDPoPKeyPath();
            
            if (!Storage::exists($keyPath)) {
                return null;
            }

            $keyData = json_decode(Storage::get($keyPath), true);

            if (!$keyData || !isset($keyData['private_key'], $keyData['public_jwk'])) {
                return null;
            }

            Log::info('[DPOP KEY PAIR LOADED FROM STORAGE]');

            return $keyData;
        } catch (\Throwable $e) {
            Log::warning('[DPOP KEY LOAD ERROR]', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Save DPoP key pair to storage.
     */
    private function saveDPoPKeyPair(array $keyPair): void
    {
        try {
            $keyPath = $this->getDPoPKeyPath();
            
            Storage::put($keyPath, json_encode([
                'private_key' => $keyPair['private_key'],
                'public_key'  => $keyPair['public_key'],
                'public_jwk'  => $keyPair['public_jwk'],
                'created_at'  => now()->toISOString(),
            ]));

            Log::info('[DPOP KEY PAIR SAVED TO STORAGE]');
        } catch (\Throwable $e) {
            Log::warning('[DPOP KEY SAVE ERROR]', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Generate JTI (JWT ID).
     */
    private static function generateJti(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Generate access token hash for DPoP.
     */
    private static function generateAccessTokenHash(string $accessToken): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $accessToken, true)), '+/', '-_'), '=');
    }

    /**
     * Get DPoP key storage path for this identity.
     */
    private function getDPoPKeyPath(): string
    {
        if ($this->identity instanceof SolidIdentity) {
            return 'solid/dpop_keys_' . $this->identity->uuid . '.json';
        }
        
        // Fallback to global key for non-identity contexts
        return 'solid/dpop_keys.json';
    }
    
    /**
     * Get DPoP cache key for this identity.
     */
    private function getDPoPCacheKey(): string
    {
        if ($this->identity instanceof SolidIdentity) {
            return 'identity_' . $this->identity->uuid;
        }
        
        return 'global';
    }

    /**
     * Clear client credentials and DPoP keys.
     */
    public function clearClientCredentials(): void
    {
        try {
            // Clear stored DPoP keys for this identity
            $keyPath = $this->getDPoPKeyPath();
            if (Storage::exists($keyPath)) {
                Storage::delete($keyPath);
            }

            $cacheKey = $this->getDPoPCacheKey();
            unset(self::$dpopKeyPairCache[$cacheKey]);

            Log::info('[CLIENT CREDENTIALS CLEARED]');
        } catch (\Throwable $e) {
            Log::warning('[CLEAR CREDENTIALS ERROR]', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Exchange authorization code for tokens with enhanced error handling.
     */
    public function exchangeCodeForTokens(string $code, ?string $state = null): \stdClass
    {
        try {
            Log::info('[EXCHANGING CODE FOR TOKENS]', [
                'code_length' => strlen($code),
                'state'       => $state,
            ]);

            $this->setCode($code);
            if ($state !== null) {
                $this->setState($state);
            }

            // Note: Scope is sent in authorization request, not token request (per OIDC spec)
            // CSS should remember the granted scope from the authorization
            $tokenResponse = $this->requestTokens($code);

            Log::info('[TOKEN EXCHANGE SUCCESS]', [
                'has_access_token' => isset($tokenResponse->access_token),
                'has_id_token'     => isset($tokenResponse->id_token),
                'token_type'       => $tokenResponse->token_type ?? 'unknown',
            ]);

            return (object) $tokenResponse;
        } catch (\Throwable $e) {
            Log::error('[TOKEN EXCHANGE ERROR]', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function setCode(string $code): self
    {
        $this->code = $code;

        return $this;
    }

    private function sessionKey(string $key): string
    {
        $prefix = 'oidc:session:' . ($this->identity ? $this->identity->identifier : 'default') . ':';

        return $prefix . Str::slug($key);
    }

    // Full implementations – no placeholders
    protected function loadFromStorage(string $key): ?string
    {
        try {
            $value = Redis::get($this->sessionKey($key));

            return $value === null ? null : (string) $value;
        } catch (\Throwable $e) {
            Log::warning('[OIDC STORAGE LOAD ERROR]', ['key' => $key, 'error' => $e->getMessage()]);

            return null;
        }
    }

    protected function saveToStorage(string $key, string $value): void
    {
        try {
            Redis::set($this->sessionKey($key), $value);
        } catch (\Throwable $e) {
            Log::warning('[OIDC STORAGE SAVE ERROR]', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }
}
