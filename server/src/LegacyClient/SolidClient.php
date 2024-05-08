<?php

namespace Fleetbase\Solid\LegacyClient;

use EasyRdf\Graph;
use Fleetbase\Solid\Client\Identity\IdentityProvider;
use Fleetbase\Solid\Models\SolidIdentity;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SolidClient
{
    /**
     * The host of the Solid server.
     */
    private string $host = 'localhost';

    /**
     * The port on which the Solid server is running.
     */
    private int $port = 3000;

    /**
     * Indicates whether the connection to the Solid server should be secure (HTTPS).
     */
    private bool $secure = true;

    /**
     * The identity provider for authentication with the Solid server.
     */
    public IdentityProvider $identity;

    public bool $identityProviderInitialized = false;

    private const DEFAULT_MIME_TYPE   = 'text/turtle';
    private const LDP_BASIC_CONTAINER = 'http://www.w3.org/ns/ldp#BasicContainer';
    private const LDP_RESOURCE        = 'http://www.w3.org/ns/ldp#Resource';
    private const OIDC_ISSUER         = 'http://www.w3.org/ns/solid/terms#oidcIssuer';

    /**
     * Constructor for the SolidClient.
     *
     * Initializes the client with the provided options or defaults.
     *
     * @param array $options configuration options for the client
     */
    public function __construct(array $options = [])
    {
        $this->host   = config('solid.server.host', data_get($options, 'host'));
        $this->port   = (int) config('solid.server.port', data_get($options, 'port'));
        $this->secure = (bool) config('solid.server.secure', data_get($options, 'secure'));
        $this->initializeIdentityProvider($options);
    }

    private function initializeIdentityProvider(array $options = []): IdentityProvider
    {
        $this->identity = new IdentityProvider($this);
        if (isset($options['restore']) && is_string($options['restore'])) {
            $this->identity->restoreClientCredentials($options['restore']);
        }

        return $this->identity;
    }

    /**
     * Factory method to create a new instance of the SolidClient.
     *
     * @param array $options configuration options for the client
     *
     * @return static a new instance of SolidClient
     */
    public static function create(array $options = []): self
    {
        return new static($options);
    }

    /**
     * Constructs the URL to the Solid server based on the configured host, port, and security.
     *
     * @return string the fully constructed URL
     */
    public function getServerUrl(): string
    {
        $protocol = $this->secure ? 'https' : 'http';
        $host     =  preg_replace('#^.*://#', '', $this->host);

        return "{$protocol}://{$host}:{$this->port}";
    }

    /**
     * Creates a full request URL based on the server URL and the provided URI.
     *
     * This function constructs a complete URL by appending the given URI to the base server URL.
     * It ensures that there is exactly one slash between the base URL and the URI.
     *
     * @param string|null $uri The URI to append to the server URL. If null, only the server URL is returned.
     *
     * @return string the fully constructed URL
     */
    private function createRequestUrl(?string $uri = null): string
    {
        $url = $this->getServerUrl();

        if (is_string($uri)) {
            $uri = '/' . ltrim($uri, '/');
            $url .= $uri;
        }

        return $url;
    }

    /**
     * Sets the necessary authentication headers for the request.
     *
     * This function adds authentication headers to the provided options array.
     * It includes an Authorization header with a DPoP token if an access token is available.
     * It also generates a DPoP header based on the request method and URL.
     *
     * @param array  &$options The array of options for the HTTP request, passed by reference
     * @param string $method   The HTTP method of the request (e.g., 'GET', 'POST').
     * @param string $url      the full URL of the request
     *
     * @return array the modified options array with added authentication headers
     */
    private function setAuthenticationHeaders(array &$options, string $method, string $url)
    {
        $withoutAuth = data_get($options, 'withoutAuth', false);
        if ($withoutAuth) {
            return $options;
        }

        $useCssAuth  = data_get($options, 'useCssAuth', false);
        $useDpopAuth = data_get($options, 'useDpopAuth', true);
        $headers     = data_get($options, 'headers', []);
        $accessToken = isset($this->identity) ? $this->identity->getAccessToken() : null;
        if ($accessToken) {
            if ($useDpopAuth) {
                $headers['Authorization'] = 'DPoP ' . $accessToken;
                $headers['DPoP']          = $this->identity->createDPoP($method, $url, true);
            }

            if ($useCssAuth) {
                $headers['Authorization'] = 'CSS-Account-Token ' . $accessToken;
            }
        }

        $options['headers'] = $headers;

        return $options;
    }

    /**
     * Make a HTTP request to the Solid server.
     *
     * @param string $method  The HTTP method (GET, POST, etc.)
     * @param string $uri     The URI to send the request to
     * @param array  $options Options for the request
     */
    protected function request(string $method, string $uri, array $data = [], array $options = []): Response
    {
        if (Str::startsWith($uri, 'http')) {
            $url = $uri;
        } else {
            $url = $this->createRequestUrl($uri);
        }
        $this->setAuthenticationHeaders($options, $method, $url);

        return Http::withOptions($options)->{$method}($url, $data);
    }

    public function requestWithIdentity(SolidIdentity $solidIdentity, string $method, string $uri, array $data = [], array $options = [])
    {
        if (Str::startsWith($uri, 'http')) {
            $url = $uri;
        } else {
            $url = $this->createRequestUrl($uri);
        }

        // prepare headers
        if (!isset($options['headers']) || !is_array($options['headers'])) {
            $options['headers'] = [];
        }

        $accessToken = $solidIdentity->getAccessToken();
        if ($accessToken) {
            $options['headers']['Authorization'] = 'DPoP ' . $accessToken;
            $options['headers']['DPoP']          = $this->identity->createDPoP($method, $url, true, $accessToken);
        }

        // dump($options);
        return Http::withOptions($options)->{$method}($url, $data);
    }

    /**
     * Send a GET request to the Solid server.
     *
     * @param string $uri     The URI to send the request to
     * @param array  $options Options for the request
     */
    public function get(string $uri, array $data = [], array $options = []): Response
    {
        return $this->request('get', $uri, $data, $options);
    }

    /**
     * Send a POST request to the Solid server.
     *
     * @param string $uri  The URI to send the request to
     * @param array  $data Data to be sent in the request body
     */
    public function post(string $uri, array $data = [], array $options = []): Response
    {
        return $this->request('post', $uri, $data, $options);
    }

    /**
     * Send a PUT request to the Solid server.
     *
     * @param string $uri  The URI to send the request to
     * @param array  $data Data to be sent in the request body
     */
    public function put(string $uri, array $data = [], array $options = []): Response
    {
        return $this->request('put', $uri, $data, $options);
    }

    /**
     * Send a PATCH request to the Solid server.
     *
     * @param string $uri  The URI to send the request to
     * @param array  $data Data to be sent in the request body
     */
    public function patch(string $uri, array $data = [], array $options = []): Response
    {
        return $this->request('patch', $uri, $data, $options);
    }

    /**
     * Send a DELETE request to the Solid server.
     *
     * @param string $uri     The URI to send the request to
     * @param array  $options Options for the request
     */
    public function delete(string $uri, array $data = [], array $options = []): Response
    {
        return $this->request('delete', $uri, $data, $options);
    }

    public function getProfile(string $webId, array $options = []): Graph
    {
        $response = $this->get($webId, $options);
        if (null !== $format = $response->getHeaders()['content-type'][0] ?? null) {
            // strip parameters (such as charset) if any
            $format = explode(';', $format, 2)[0];
        }

        return new Graph($webId, $response->getContent(), $format);
    }

    public function getOpenIdConfiguration()
    {
        $response = $this->get('.well-known/openid-configuration', [], ['withoutAuth' => true]);

        if ($response->successful()) {
            return $response->json();
        }

        throw $response->toException();
    }
}
