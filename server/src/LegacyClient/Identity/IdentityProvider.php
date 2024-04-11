<?php

namespace Fleetbase\Solid\LegacyClient\Identity;

use EasyRdf\Graph;
use Fleetbase\Solid\Client\OIDCClient;
use Fleetbase\Solid\Client\SolidClient;
use Fleetbase\Support\Utils;
use Jumbojett\OpenIDConnectClientException;

class IdentityProvider
{
    /**
     * The Solid client instance.
     */
    private SolidClient $solidClient;

    /**
     * The OIDC client instance for handling OpenID Connect authentication.
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
     * @param SolidClient $solidClient the Solid client instance
     */
    public function __construct(SolidClient $solidClient)
    {
        $this->solidClient = $solidClient;
        $this->oidcClient  = OIDCClient::create($solidClient);
    }

    /**
     * Gets the OIDC client instance.
     *
     * @return OIDCClient the OIDC client instance
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
     * @param string $name      the name of the method being called
     * @param array  $arguments the arguments passed to the method
     *
     * @return mixed the result of the method call
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

    public function registerClient(array $options = []): self
    {
        // Get registration options
        $clientName     = data_get($options, 'clientName', $this->oidcClient::CLIENT_NAME);
        $requestParams  = data_get($options, 'requestParams', []);
        $requestOptions = data_get($options, 'requestOptions', []);
        $redirectUri    = data_get($options, 'redirectUri');
        if (!$redirectUri) {
            $requestCode = data_get($options, 'requestCode');
            $redirectUri = Utils::apiUrl('solid/int/v1/oidc/complete-registration' . $requestCode ? '/' . $requestCode : '', [], 8000);
        }
        $withResponse   = data_get($options, 'withResponse');

        // Get OIDC Config and Registration URL
        $oidcConfig      = $this->solidClient->getOpenIdConfiguration();
        $registrationUrl = data_get($oidcConfig, 'registration_endpoint');

        // Request registration for Client which should handle authentication
        $response = $this->solidClient->post($registrationUrl, ['client_name' => $clientName, 'redirect_uris' => [$redirectUri], ...$requestParams], ['withoutAuth' => true, ...$requestOptions]);
        if ($response->successful()) {
            $clientCredentials = $response->json();
            $this->setClientCredentials($clientName, $clientCredentials);
            if (is_callable($withResponse)) {
                $withResponse($clientCredentials);
            }
        } else {
            throw new OpenIDConnectClientException('Error registering: Please contact the OpenID Connect provider and obtain a Client ID and Secret directly from them');
        }

        return $this;
    }

    public function getClient(array $options = [])
    {
        $clientName     = data_get($options, 'clientName', $this->oidcClient::CLIENT_NAME);
        $restoredClient = $this->restoreClientCredentials($clientName);
        if ($restoredClient === null) {
            return $this->registerClient($options);
        }

        return $restoredClient;
    }

    /**
     * Retrieves the WebID profile of a user as an RDF graph.
     *
     * @param string $webId   the WebID of the user
     * @param array  $options additional options for the request
     *
     * @return Graph the user's WebID profile as an RDF graph
     */
    public function getWebIdProfile(string $webId, array $options = []): Graph
    {
        $response = $this->solidClient->get($webId, $options);
        $format   = $response->header('Content-Type');

        if ($format) {
            // strip parameters (such as charset) if any
            $format = explode(';', $format, 2)[0];
        }

        return new Graph($webId, $response->getContent(), $format);
    }

    /**
     * Retrieves the OIDC issuer URL from a WebID profile.
     *
     * @param string $webId   the WebID of the user
     * @param array  $options additional options for the request
     *
     * @return string the OIDC issuer URL
     *
     * @throws \Exception if the OIDC issuer cannot be found
     */
    public function getOidcIssuer(string $webId, array $options = []): string
    {
        $graph  = $this->getWebIdProfile($webId, $options);
        $issuer = $graph->get($webId, sprintf('<%s>', self::OIDC_ISSUER))->getUri();

        if (!\is_string($issuer)) {
            throw new \Exception('Unable to find the OIDC issuer associated with this WebID', 1);
        }

        return $issuer;
    }
}
