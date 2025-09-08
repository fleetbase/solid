<?php

namespace Fleetbase\Solid\Http\Controllers;

use Fleetbase\Http\Controllers\Controller as BaseController;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Models\Setting;
use Fleetbase\Solid\Client\SolidClient;
use Fleetbase\Solid\Models\SolidIdentity;
use Fleetbase\Solid\Services\PodService;
use Fleetbase\Solid\Support\Utils;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SolidController extends BaseController
{
    protected PodService $podService;

    public function __construct(PodService $podService)
    {
        $this->podService = $podService;
    }

    /**
     * Welcome message only.
     */
    public function hello(Request $request)
    {
        return response()->json(
            [
                'message' => 'Fleetbase Solid Extension',
                'version' => config('solid.api.version'),
            ]
        );
    }

    public function getServerConfig(AdminRequest $request)
    {
        $defaultConfig = config('solid.server');
        $savedConfig   = Setting::system('solid.server');
        $config        = array_merge($defaultConfig, $savedConfig);

        return response()->json($config);
    }

    public function saveServerConfig(AdminRequest $request)
    {
        $incomingConfig = $request->array('server');
        $defaultConfig  = config('solid.server');
        $config         = array_merge($defaultConfig, $incomingConfig);
        Setting::configure('system.solid.server', $config);

        return response()->json($config);
    }

    public function requestAuthentication()
    {
        $solidIdentity     = SolidIdentity::initialize();
        $authenticationUrl = Utils::apiUrl('solid/int/v1/authenticate', [], 8000);

        return response()->json(['authenticationUrl' => $authenticationUrl, 'identifier' => $solidIdentity->identifier]);
    }

    public function authenticate(string $identifier)
    {
        try {
            Log::info('[SOLID AUTH START]', ['identifier' => $identifier]);

            $identity = SolidIdentity::where('identifier', $identifier)->first();

            if (!$identity) {
                throw new \Exception('Identity not found for identifier: ' . $identifier);
            }

            Log::info('[SOLID IDENTITY FOUND]', [
                'identity_id'  => $identity->id,
                'redirect_uri' => $identity->getRedirectUri(),
            ]);

            $oidc = SolidClient::create(['identity' => $identity])->oidc->register(['saveCredentials' => true]);

            Log::info('[SOLID OIDC REGISTERED]', ['client_id' => $oidc->getClientID()]);

            // This will redirect to the authorization server
            return $oidc->authenticate();
        } catch (\Throwable $e) {
            Log::error('[SOLID AUTH ERROR]', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Redirect back to frontend with error
            return redirect(Utils::consoleUrl('solid-protocol', ['error' => $e->getMessage()]));
        }
    }

    /**
     * Get authentication status and account details.
     */
    public function getAuthenticationStatus(Request $request)
    {
        try {
            $identity = SolidIdentity::current();

            if (!$identity || !$identity->getAccessToken()) {
                return response()->json([
                    'authenticated' => false,
                    'identity'      => null,
                    'profile'       => null,
                ]);
            }

            // Get WebID and profile information
            $profile = $this->podService->getProfileData($identity);

            return response()->json([
                'authenticated' => true,
                'identity'      => [
                    'id'               => $identity->id,
                    'identifier'       => $identity->identifier,
                    'created_at'       => $identity->created_at,
                    'has_access_token' => (bool) $identity->getAccessToken(),
                ],
                'profile' => $profile,
            ]);
        } catch (\Throwable $e) {
            Log::error('[SOLID AUTH STATUS ERROR]', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'authenticated' => false,
                'identity'      => null,
                'profile'       => null,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    /**
     * Logout user by clearing identity tokens.
     */
    public function logout(Request $request)
    {
        try {
            $identity = SolidIdentity::current();

            if ($identity) {
                // Clear token response
                $identity->update(['token_response' => null]);

                // Clear any cached client credentials
                $solid = SolidClient::create(['identity' => $identity]);
                $solid->oidc->clearClientCredentials();
            }

            return response()->json([
                'success' => true,
                'message' => 'Logged out successfully',
            ]);
        } catch (\Throwable $e) {
            Log::error('[SOLID LOGOUT ERROR]', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get real pods from Solid server.
     */
    public function getPods(Request $request)
    {
        try {
            $identity = SolidIdentity::current();

            if (!$identity || !$identity->getAccessToken()) {
                return response()->json([
                    'error' => 'Not authenticated',
                ], 401);
            }

            $profile          = $this->podService->getProfileData($identity);
            $storageLocations = data_get($profile, 'parsed_profile.storage_locations', []);

            $pods = [];
            foreach ($storageLocations as $storageUrl) {
                try {
                    $podResponse = $identity->request('get', $storageUrl);
                    if ($podResponse->successful()) {
                        $pods[] = [
                            'url'        => $storageUrl,
                            'name'       => $this->extractPodName($storageUrl),
                            'content'    => $podResponse->body(),
                            'status'     => $podResponse->status(),
                            'containers' => $this->parseContainers($podResponse->body()),
                        ];
                    }
                } catch (\Throwable $e) {
                    Log::warning('[SOLID POD FETCH ERROR]', [
                        'storage_url' => $storageUrl,
                        'error'       => $e->getMessage(),
                    ]);
                }
            }

            return response()->json([
                'pods'              => $pods,
                'storage_locations' => $storageLocations,
            ]);
        } catch (\Throwable $e) {
            Log::error('[SOLID PODS ERROR]', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Extract pod name from URL.
     */
    private function extractPodName(string $url): string
    {
        $parts = explode('/', rtrim($url, '/'));

        return end($parts) ?: 'Root Pod';
    }

    /**
     * Parse containers from pod content.
     */
    private function parseContainers(string $content): array
    {
        $containers = [];

        // Parse LDP containers from RDF
        if (preg_match_all('/<([^>]+)>\s+a\s+ldp:Container/', $content, $matches)) {
            foreach ($matches[1] as $containerUrl) {
                $containers[] = [
                    'url'  => $containerUrl,
                    'name' => $this->extractPodName($containerUrl),
                    'type' => 'container',
                ];
            }
        }

        return $containers;
    }

    public function getAccountIndex()
    {
        $solidIdentity   = SolidIdentity::current();
        $accountResponse = $solidIdentity->request('get', '.account');
        dd($accountResponse->json());
    }
}
