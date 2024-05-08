<?php

namespace Fleetbase\Solid\Client;

use Fleetbase\Solid\Models\SolidIdentity;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class SolidClient
{
    private string $host = 'localhost';
    private int $port    = 3000;
    private bool $secure = true;
    public ?SolidIdentity $solidIdentity;
    public ?OpenIDConnectClient $oidc;
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
        $this->identity = data_get($options, 'identity');
        $this->host     = config('solid.server.host', data_get($options, 'host'));
        $this->port     = (int) config('solid.server.port', data_get($options, 'port'));
        $this->secure   = (bool) config('solid.server.secure', data_get($options, 'secure'));
        $this->oidc     = OpenIDConnectClient::create(['solid' => $this, ...$options]);
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
        if (Str::startsWith($uri, 'http')) {
            return $uri;
        }

        $url = $this->getServerUrl();
        if (is_string($uri)) {
            $uri = '/' . ltrim($uri, '/');
            $url .= $uri;
        }

        return $url;
    }

    /**
     * Set the identity to use for authenticated request.
     */
    public function withIdentity(SolidIdentity $identity): SolidClient
    {
        $this->identity = $identity;

        return $this;
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
        $url = $this->createRequestUrl($uri);

        return Http::withOptions($options)->{$method}($url, $data);
    }

    /**
     * Send an authenticated request with the current identity.
     */
    public function authenticatedRequest(string $method, string $uri, array $data = [], array $options = []): Response
    {
        if (!$this->identity) {
            throw new \Exception('Solid Identity required to make an authenticated request.');
        }

        $url         = $this->createRequestUrl($uri);
        $accessToken = $this->identity->getAccessToken();
        if ($accessToken) {
            $options['headers']                  = is_array($options['headers']) ? $options['headers'] : [];
            $options['headers']['Authorization'] = 'DPoP ' . $accessToken;
            $options['headers']['DPoP']          = OpenIDConnectClient::createDPoP($method, $url, $accessToken);
        }

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
}
