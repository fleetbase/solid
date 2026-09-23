<?php

namespace Fleetbase\Solid\Auth\Contracts;

/**
 * The HTTP calls the OIDC handshake makes: discovery, dynamic client
 * registration, the token endpoint and the JWKS document.
 *
 * This exists so that TLS policy and timeouts are decided in exactly one place,
 * and so the handshake can be exercised in tests without a network. It replaces
 * the bare `curl_*` block Solid previously carried as an override of
 * `Jumbojett\OpenIDConnectClient::fetchURL()`, which silently ignored the
 * library's own peer-verification and timeout settings.
 */
interface OidcTransport
{
    /**
     * @param array<string, string> $headers
     *
     * @throws \Fleetbase\Solid\Exceptions\OpenIDConnectClientException on a transport failure
     */
    public function get(string $url, array $headers = []): OidcResponse;

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $headers
     *
     * @throws \Fleetbase\Solid\Exceptions\OpenIDConnectClientException on a transport failure
     */
    public function postJson(string $url, array $body, array $headers = []): OidcResponse;

    /**
     * An `application/x-www-form-urlencoded` POST, as the token endpoint requires.
     *
     * @param array<string, string> $fields
     * @param array<string, string> $headers
     *
     * @throws \Fleetbase\Solid\Exceptions\OpenIDConnectClientException on a transport failure
     */
    public function postForm(string $url, array $fields, array $headers = []): OidcResponse;
}
