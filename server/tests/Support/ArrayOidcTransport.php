<?php

namespace Fleetbase\Solid\Tests\Support;

use Fleetbase\Solid\Auth\Contracts\OidcResponse;
use Fleetbase\Solid\Auth\Contracts\OidcTransport;
use Fleetbase\Solid\Exceptions\OpenIDConnectClientException;

/**
 * A scripted {@see OidcTransport} for tests.
 *
 * Responses are queued per URL so a single URL can answer differently on
 * successive calls — which is how the RFC 9449 §8 `use_dpop_nonce` retry and the
 * JWKS key-rotation refetch are exercised. Every request is recorded so a test
 * can assert on the fields and headers that were actually sent.
 */
final class ArrayOidcTransport implements OidcTransport
{
    /**
     * @var array<string, array<int, OidcResponse>>
     */
    private array $queues = [];

    /**
     * @var array<int, array{method: string, url: string, body: mixed, headers: array<string, string>}>
     */
    public array $requests = [];

    /**
     * Queue a response for a URL. Call it more than once to script a sequence.
     *
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     */
    public function queue(string $url, array $body, int $status = 200, array $headers = []): self
    {
        $this->queues[$url][] = new OidcResponse($status, (string) json_encode($body), $headers);

        return $this;
    }

    /**
     * Queue a response with a literal body, for the cases where the body is not
     * valid JSON.
     *
     * @param array<string, string> $headers
     */
    public function queueRaw(string $url, string $body, int $status = 200, array $headers = []): self
    {
        $this->queues[$url][] = new OidcResponse($status, $body, $headers);

        return $this;
    }

    public function get(string $url, array $headers = []): OidcResponse
    {
        return $this->record('GET', $url, null, $headers);
    }

    public function postJson(string $url, array $body, array $headers = []): OidcResponse
    {
        return $this->record('POST', $url, $body, $headers);
    }

    public function postForm(string $url, array $fields, array $headers = []): OidcResponse
    {
        return $this->record('POST', $url, $fields, $headers);
    }

    /**
     * The last request sent to a URL, or null if there was none.
     *
     * @return array{method: string, url: string, body: mixed, headers: array<string, string>}|null
     */
    public function lastRequestTo(string $url): ?array
    {
        foreach (array_reverse($this->requests) as $request) {
            if ($request['url'] === $url) {
                return $request;
            }
        }

        return null;
    }

    public function countRequestsTo(string $url): int
    {
        return count(array_filter($this->requests, static fn (array $request): bool => $request['url'] === $url));
    }

    /**
     * @param array<string, string> $headers
     */
    private function record(string $method, string $url, mixed $body, array $headers): OidcResponse
    {
        $this->requests[] = compact('method', 'url', 'body', 'headers');

        if (empty($this->queues[$url])) {
            throw new OpenIDConnectClientException('No response was queued for ' . $method . ' ' . $url . '.');
        }

        // The final queued response is reused, so a test only has to script the
        // calls whose answers actually change.
        return count($this->queues[$url]) === 1
            ? $this->queues[$url][0]
            : array_shift($this->queues[$url]);
    }
}
