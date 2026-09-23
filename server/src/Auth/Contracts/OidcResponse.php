<?php

namespace Fleetbase\Solid\Auth\Contracts;

use Fleetbase\Solid\Exceptions\OpenIDConnectClientException;

/**
 * A transport-agnostic HTTP response.
 *
 * Deliberately tiny: the handshake only needs a status, a body and the occasional
 * header. Keeping it free of `Illuminate\Http\Client\Response` is what lets the
 * flow be exercised without a container.
 */
final class OidcResponse
{
    /**
     * @var array<string, string> header names lower-cased for lookup
     */
    private array $headers;

    /**
     * @param array<string, string|array<int, string>> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        array $headers = [],
    ) {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower($name)] = is_array($value) ? (string) ($value[0] ?? '') : $value;
        }

        $this->headers = $normalized;
    }

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * A response header, matched case-insensitively as HTTP requires.
     */
    public function header(string $name): ?string
    {
        $value = $this->headers[strtolower($name)] ?? null;

        return $value === '' ? null : $value;
    }

    /**
     * The body decoded as a JSON object.
     *
     * @throws OpenIDConnectClientException when the body is not a JSON object
     */
    public function object(string $context): \stdClass
    {
        $decoded = json_decode($this->body, false);

        if (!$decoded instanceof \stdClass) {
            throw new OpenIDConnectClientException('Expected a JSON object from ' . $context . '.');
        }

        return $decoded;
    }

    /**
     * The body decoded as a JSON object, as an array.
     *
     * @return array<string, mixed>
     *
     * @throws OpenIDConnectClientException when the body is not a JSON object
     */
    public function array(string $context): array
    {
        $decoded = json_decode($this->body, true);

        if (!is_array($decoded)) {
            throw new OpenIDConnectClientException('Expected a JSON object from ' . $context . '.');
        }

        return $decoded;
    }
}
