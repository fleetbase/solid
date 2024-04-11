<?php

namespace Fleetbase\Solid\Client;

use Fleetbase\Solid\Models\SolidIdentity;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Jumbojett\OpenIDConnectClient as BaseOpenIDConnectClient;
use Jumbojett\OpenIDConnectClientException;

const CLIENT_NAME     = 'Fleetbase';
final class OpenIDConnectClient extends BaseOpenIDConnectClient
{
    private ?SolidClient $solid;
    private ?SolidIdentity $identity;
    private ?\stdClass $openIdConfig;

    public function __construct(array $options = [])
    {
        $this->solid    = data_get($options, 'solid');
        $this->identity = data_get($options, 'identity');
        if ($this->identity instanceof SolidIdentity) {
            $this->setRedirectURL($this->identity->getRedirectUri());
        }
        $this->setCodeChallengeMethod('S256');
        $this->setClientName(data_get($options, 'clientName', CLIENT_NAME));
        $this->setClientID(data_get($options, 'clientID'));
        $this->setClientSecret(data_get($options, 'clientSecret'));

        // Restore client credentials
        if (isset($options['restore'])) {
            $this->restoreClientCredentials();
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
        $this->addScope(['openid', 'webid', 'offline_access']);

        return parent::authenticate();
    }

    private function setClientCredentials(string $clientName = CLIENT_NAME, $clientCredentials, bool $save = false, \Closure $callback = null): OpenIDConnectClient
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

    public function getOpenIdConfiguration(string $key = null)
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

    protected function getSessionKey($key)
    {
        if (Redis::exists('oidc:session' . Str::slug($key))) {
            return $this->retrieve('oidc:session' . Str::slug($key));
        }

        return false;
    }

    protected function setSessionKey($key, $value)
    {
        $this->save('oidc:session' . Str::slug($key), $value);
    }

    protected function unsetSessionKey($key)
    {
        Redis::del('oidc:session' . Str::slug($key));
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

    // public static function createDPoP(string $method, string $url, string $accessToken = null): string
    // {
    // }
}
