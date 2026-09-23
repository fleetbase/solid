<?php

namespace Fleetbase\Solid\Client;

use Fleetbase\Models\Setting;
use Fleetbase\Solid\Models\SolidIdentity;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SolidClient
{
    private string $host = 'localhost';
    private int $port    = 3000;
    private bool $secure = true;

    /**
     * Nullable because a client can legitimately be built without one — discovery
     * and the unauthenticated requests do not need an identity. It was typed
     * non-nullable while being assigned from an absent option, so `new SolidClient([])`
     * raised a TypeError.
     */
    public ?SolidIdentity $identity = null;

    public OpenIDConnectClient $oidc;
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
        $identity       = data_get($options, 'identity');
        $this->identity = $identity instanceof SolidIdentity ? $identity : null;

        $server         = static::resolveServerConfig();
        $this->host     = (string) (data_get($options, 'host') ?? $server['host']);
        $this->port     = (int) (data_get($options, 'port') ?? $server['port']);
        $this->secure   = (bool) (data_get($options, 'secure') ?? $server['secure']);

        $this->oidc     = OpenIDConnectClient::create(['solid' => $this, ...$options]);
    }

    /**
     * The Solid server to talk to: the administrator's saved setting first, then
     * the env-backed config defaults.
     *
     * `SolidController::saveServerConfig()` writes `system.solid.server` and
     * `getServerConfig()` reads it back, but nothing ever fed it to this client —
     * so changing the host or port in the console had no effect on any request.
     *
     * Note the ordering against the constructor: an explicit option wins over the
     * saved setting, which wins over config. The previous code read
     * `config('solid.server.host', data_get($options, 'host'))`, where the second
     * argument is `config()`'s *default* — and since the key is always defined, a
     * caller-supplied host was silently discarded.
     *
     * @return array{host: string, port: int, secure: bool}
     */
    protected static function resolveServerConfig(): array
    {
        $defaults = (array) config('solid.server', []);
        $saved    = [];

        try {
            $saved = (array) (Setting::system('solid.server') ?? []);
        } catch (\Throwable $e) {
            // Settings live in the database; a request that runs before migrations
            // (or with the table missing) must fall back to config rather than fail.
            Log::warning('[Solid] Unable to read the saved server configuration.', ['error' => $e->getMessage()]);
        }

        $server = array_merge($defaults, array_filter($saved, static fn ($value): bool => $value !== null && $value !== ''));

        return [
            'host'   => (string) ($server['host'] ?? 'localhost'),
            'port'   => (int) ($server['port'] ?? 3000),
            'secure' => (bool) ($server['secure'] ?? false),
        ];
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
     * Make a request with a specific identity.
     */
    public function requestWithIdentity(SolidIdentity $identity, string $method, string $uri, string|array $data = [], array $options = []): Response
    {
        $this->identity = $identity;

        // Check if we should skip authentication
        $withoutAuth = data_get($options, 'withoutAuth', false);

        if ($withoutAuth) {
            return $this->request($method, $uri, $data, $options);
        }

        return $this->authenticatedRequest($method, $uri, $data, $options);
    }

    /**
     * Make a HTTP request to the Solid server.
     *
     * @param string $method  The HTTP method (GET, POST, etc.)
     * @param string $uri     The URI to send the request to
     * @param array  $options Options for the request
     */
    protected function request(string $method, string $uri, string|array $data = [], array $options = []): Response
    {
        $url = $this->createRequestUrl($uri);

        // For development: disable SSL verification when using HTTPS
        if ($this->secure && app()->environment('local', 'development')) {
            $options['verify'] = false;
        }

        // Handle different data types
        if (is_string($data)) {
            return Http::withOptions($options)->withBody($data, $options['headers']['Content-Type'] ?? 'text/plain')->send($method, $url);
        }

        return Http::withOptions($options)->{$method}($url, $data);
    }

    /**
     * Send an authenticated request with the current identity.
     */
    public function authenticatedRequest(string $method, string $uri, string|array $data = [], array $options = []): Response
    {
        if (!$this->identity) {
            throw new \Exception('Solid Identity required to make an authenticated request.');
        }

        $url         = $this->createRequestUrl($uri);
        $accessToken = $this->identity->getAccessToken();

        if ($accessToken) {
            $options['headers']                  = isset($options['headers']) && is_array($options['headers']) ? $options['headers'] : [];
            $options['headers']['Authorization'] = 'DPoP ' . $accessToken;
            $options['headers']['DPoP']          = $this->oidc->createDPoP($method, $url, $accessToken);
        }

        // For development: disable SSL verification when using HTTPS
        if ($this->secure && app()->environment('local', 'development')) {
            $options['verify'] = false;
        }

        // Only the header names. The values carry the access token and the DPoP
        // proof, and a log file is not a place either of them belongs.
        Log::debug('[Solid] Sending an authenticated request.', [
            'method'  => $method,
            'url'     => $url,
            'headers' => array_keys(is_array($options['headers'] ?? null) ? $options['headers'] : []),
        ]);

        // Handle different data types
        if (is_string($data)) {
            // For string data, send as raw body
            $contentType = $options['headers']['Content-Type'] ?? 'text/plain';

            Log::info('[SENDING STRING BODY]', [
                'method'       => $method,
                'url'          => $url,
                'content_type' => $contentType,
                'body_length'  => strlen($data),
                'body_preview' => substr($data, 0, 200),
            ]);

            $response = Http::withOptions($options)->withBody($data, $contentType)->send($method, $url);

            // Debug: Log response details
            Log::debug('[SOLID RESPONSE]', [
                'status'  => $response->status(),
                'headers' => $response->headers(),
                'body'    => $response->body(),
            ]);

            return $response;
        }
        // For array data, use the original method
        Log::info('[SENDING ARRAY DATA]', [
            'method' => $method,
            'url'    => $url,
            'data'   => $data,
        ]);

        $response = Http::withOptions($options)->{$method}($url, $data);

        // Debug: Log response details
        Log::debug('[SOLID RESPONSE]', [
            'status'  => $response->status(),
            'headers' => $response->headers(),
            'body'    => $response->body(),
        ]);

        return $response;
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
