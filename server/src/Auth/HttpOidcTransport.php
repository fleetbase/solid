<?php

namespace Fleetbase\Solid\Auth;

use Fleetbase\Solid\Auth\Contracts\OidcResponse;
use Fleetbase\Solid\Auth\Contracts\OidcTransport;
use Fleetbase\Solid\Exceptions\OpenIDConnectClientException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * The real HTTP transport for the OIDC handshake.
 *
 * Replaces the raw `curl_*` override Solid carried on top of Jumbojett's
 * `fetchURL()`. That version followed redirects (which can leak an
 * `Authorization: Basic` header carrying the client secret to another host),
 * applied no timeout, and turned peer verification off whenever the app
 * environment was `local` or `development`.
 *
 * Here: redirects are not followed, timeouts are explicit, and TLS verification
 * is a single configured flag (`solid.oidc.verify_tls`) rather than something
 * inferred from the environment — so it cannot silently be off in staging.
 */
class HttpOidcTransport implements OidcTransport
{
    public function __construct(
        private readonly bool $verifyTls = true,
        private readonly int $timeout = 15,
        private readonly int $connectTimeout = 5,
    ) {
    }

    public function get(string $url, array $headers = []): OidcResponse
    {
        return $this->send('GET', $url, fn (PendingRequest $request) => $request->get($url), $headers);
    }

    public function postJson(string $url, array $body, array $headers = []): OidcResponse
    {
        return $this->send('POST', $url, fn (PendingRequest $request) => $request->asJson()->post($url, $body), $headers);
    }

    public function postForm(string $url, array $fields, array $headers = []): OidcResponse
    {
        return $this->send('POST', $url, fn (PendingRequest $request) => $request->asForm()->post($url, $fields), $headers);
    }

    /**
     * @param array<string, string>                                      $headers
     * @param \Closure(PendingRequest): \Illuminate\Http\Client\Response $dispatch
     *
     * @throws OpenIDConnectClientException
     */
    private function send(string $method, string $url, \Closure $dispatch, array $headers): OidcResponse
    {
        $request = Http::withHeaders($headers)
            ->withOptions([
                // A 3xx from a token or registration endpoint is a protocol error,
                // not something to chase: following it would replay the request —
                // and its credentials — against whatever host the Location names.
                'allow_redirects' => false,
                'verify'          => $this->verifyTls,
            ])
            ->timeout($this->timeout)
            ->connectTimeout($this->connectTimeout);

        try {
            $response = $dispatch($request);
        } catch (\Throwable $e) {
            throw new OpenIDConnectClientException(sprintf('%s %s failed: %s', $method, self::redact($url), $e->getMessage()), 0, $e);
        }

        return new OidcResponse($response->status(), $response->body(), $response->headers());
    }

    /**
     * Endpoint URLs are safe to log, but a query string on one is not worth the
     * risk of carrying a code or token into an exception message.
     */
    private static function redact(string $url): string
    {
        return explode('?', $url, 2)[0];
    }
}
