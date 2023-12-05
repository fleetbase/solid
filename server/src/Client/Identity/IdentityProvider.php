<?php

namespace Fleetbase\Solid\Client\Identity;

use EasyRdf\Graph;
use Fleetbase\Solid\Client\SolidClient;
use Fleetbase\Solid\Client\OIDCClient;
use Illuminate\Support\Facades\Http;

class IdentityProvider
{
    /**
     * The Solid client instance.
     *
     * @var SolidClient
     */
    private SolidClient $solidClient;

    /**
     * The OIDC client instance for handling OpenID Connect authentication.
     *
     * @var OIDCClient
     */
    private OIDCClient $oidcClient;

    /**
     * Default MIME type for RDF data.
     */
    private const DEFAULT_MIME_TYPE = 'text/turtle';

    /**
     * RDF type for LDP Basic Containers.
     */
    private const LDP_BASIC_CONTAINER = 'http://www.w3.org/ns/ldp#BasicContainer';

    /**
     * RDF type for LDP Resources.
     */
    private const LDP_RESOURCE = 'http://www.w3.org/ns/ldp#Resource';

    /**
     * RDF property for OIDC issuer.
     */
    private const OIDC_ISSUER = 'http://www.w3.org/ns/solid/terms#oidcIssuer';

    /**
     * Constructs a new IdentityProvider instance.
     *
     * @param SolidClient $solidClient The Solid client instance.
     */
    public function __construct(SolidClient $solidClient)
    {
        $this->solidClient = $solidClient;
        $this->oidcClient = OIDCClient::create($solidClient);
    }

    /**
     * Gets the OIDC client instance.
     *
     * @return OIDCClient The OIDC client instance.
     */
    public function getOidcClient(): OIDCClient
    {
        if (!$this->oidcClient) {
            return OIDCClient::create($this->solidClient);
        }

        return $this->oidcClient;
    }

    /**
     * Magic method to delegate method calls to either IdentityProvider or OIDCClient.
     *
     * @param string $name The name of the method being called.
     * @param array $arguments The arguments passed to the method.
     * @return mixed The result of the method call.
     */
    public function __call(string $name, $arguments)
    {
        if (method_exists($this, $name)) {
            return $this->{$name}(...$arguments);
        }

        if (method_exists($this->oidcClient, $name)) {
            return $this->oidcClient->{$name}(...$arguments);
        }

        throw new \BadMethodCallException("Method {$name} does not exist.");
    }

    public function registerClient(string $clientName)
    {
        $oidcConfig = $this->solidClient->getOpenIdConfiguration();
        $registrationUrl = data_get($oidcConfig, 'registration_endpoint');

        $response = $this->solidClient->post($registrationUrl, ['client_name' => $clientName]);

        if ($response->successful()) {
            $clientCredentials = $response->json();
            dd($clientCredentials);
            $clientId = $clientCredentials['client_id'];
            $clientSecret = $clientCredentials['client_secret'];
        }
    }

    /**
     * Retrieves the WebID profile of a user as an RDF graph.
     *
     * @param string $webId The WebID of the user.
     * @param array $options Additional options for the request.
     * @return Graph The user's WebID profile as an RDF graph.
     */
    public function getWebIdProfile(string $webId, array $options = []): Graph
    {
        $response = $this->solidClient->get($webId, $options);
        $format = $response->header('Content-Type');

        if ($format) {
            // strip parameters (such as charset) if any
            $format = explode(';', $format, 2)[0];
        }

        return new Graph($webId, $response->getContent(), $format);
    }

    /**
     * Retrieves the OIDC issuer URL from a WebID profile.
     *
     * @param string $webId The WebID of the user.
     * @param array $options Additional options for the request.
     * @return string The OIDC issuer URL.
     * @throws \Exception If the OIDC issuer cannot be found.
     */
    public function getOidcIssuer(string $webId, array $options = []): string
    {
        $graph = $this->getWebIdProfile($webId, $options);
        $issuer = $graph->get($webId, sprintf('<%s>', self::OIDC_ISSUER))->getUri();

        if (!\is_string($issuer)) {
            throw new \Exception('Unable to find the OIDC issuer associated with this WebID', 1);
        }

        return $issuer;
    }
}
