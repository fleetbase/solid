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

const CLIENT_NAME     = 'Fleetbase';
final class OpenIDConnectClient extends BaseOpenIDConnectClient
{
    private ?SolidClient $solid;
    private ?SolidIdentity $identity;
    private ?\stdClass $openIdConfig;
    private string $code;
    private static $dpopKeyPair;

    public function __construct(array $options = [])
    {
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
        $openIdConfig = $client->getOpenIdConfiguration();
        $client->setProviderURL($openIdConfig->issuer);
        $client->setIssuer($openIdConfig->issuer);
        $client->providerConfigParam((array) $openIdConfig);

        return $client;
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
        $registrationResponse = $this->solid->post($registrationUrl, ['client_name' => $clientName, 'redirect_uris' => [$redirectUri], ...$requestParams], $requestOptions);
        if ($registrationResponse->successful()) {
            $clientCredentials = (object) $registrationResponse->json();
            $this->setClientCredentials($clientName, $clientCredentials, $saveCredentials, $withCredentials);
        } else {
            throw new OpenIDConnectClientException('Error registering: Please contact the OpenID Connect provider and obtain a Client ID and Secret directly from them');
        }

        return $this;
    }

    public function authenticate(): bool
    {
        $this->setCodeChallengeMethod('S256');
        $this->addScope(['openid', 'profile', 'webid', 'offline_access', 'solid']);

        return parent::authenticate();
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
    public static function createDPoP(string $method, string $url, ?string $accessToken = null): string
    {
        try {
            Log::info('[CREATING DPOP TOKEN]', [
                'method'           => strtolower($method),
                'url'              => $url,
                'has_access_token' => $accessToken !== null,
            ]);

            // Load (or generate) keypair
            $keyPair = self::loadDPoPKeyPair();
            if (!$keyPair) {
                $keyPair = self::generateDPoPKeyPair();
                if ($keyPair) {
                    self::saveDPoPKeyPair($keyPair);
                }
            }
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

            return $jwt;
        } catch (\Throwable $e) {
            Log::error('[DPOP ERROR]', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Generate or load DPoP key pair.
     */
    private static function getDPoPKeyPair(): ?array
    {
        if (self::$dpopKeyPair !== null) {
            return self::$dpopKeyPair;
        }

        try {
            // Try to load existing key pair
            $keyPair = self::loadDPoPKeyPair();

            if (!$keyPair) {
                // Generate new key pair
                $keyPair = self::generateDPoPKeyPair();

                if ($keyPair) {
                    self::saveDPoPKeyPair($keyPair);
                }
            }

            self::$dpopKeyPair = $keyPair;

            Log::info('[DPOP KEY PAIR LOADED]', [
                'has_private_key' => isset($keyPair['private_key']),
                'has_public_jwk'  => isset($keyPair['public_jwk']),
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
    private static function loadDPoPKeyPair(): ?array
    {
        try {
            if (!Storage::exists('solid/dpop_keys.json')) {
                return null;
            }

            $keyData = json_decode(Storage::get('solid/dpop_keys.json'), true);

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
    private static function saveDPoPKeyPair(array $keyPair): void
    {
        try {
            Storage::put('solid/dpop_keys.json', json_encode([
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
     * Clear client credentials and DPoP keys.
     */
    public function clearClientCredentials(): void
    {
        try {
            // Clear stored DPoP keys
            if (Storage::exists('solid/dpop_keys.json')) {
                Storage::delete('solid/dpop_keys.json');
            }

            self::$dpopKeyPair = null;

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

            // --- DPoP proof for the token endpoint (no 'ath' here) ---
            $tokenEndpoint = $this->getProviderConfigValue('token_endpoint');
            $dpop          = self::createDPoP('POST', $tokenEndpoint, null);

            // Important: pass the header through to requestTokens
            $tokenResponse = $this->requestTokens($code, [
                'DPoP: ' . $dpop,
                // Be explicit
                'Content-Type: application/x-www-form-urlencoded',
            ]);

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
