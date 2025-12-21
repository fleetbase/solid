<?php

namespace Fleetbase\Solid\Http\Controllers;

use Fleetbase\Http\Controllers\Controller as BaseController;
use Fleetbase\Solid\Client\SolidClient;
use Fleetbase\Solid\Models\SolidIdentity;
use Fleetbase\Solid\Services\CssAccountService;
use Fleetbase\Support\Utils;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CssAccountController extends BaseController
{
    protected CssAccountService $cssAccountService;

    public function __construct(CssAccountService $cssAccountService)
    {
        $this->cssAccountService = $cssAccountService;
    }

    /**
     * Check if current identity has CSS credentials configured.
     */
    public function checkCredentials(Request $request)
    {
        try {
            $identity = SolidIdentity::current();

            if (!$identity) {
                return response()->json([
                    'has_credentials' => false,
                    'authenticated' => false,
                ]);
            }

            $hasCredentials = $this->cssAccountService->hasCredentials($identity);

            return response()->json([
                'has_credentials' => $hasCredentials,
                'authenticated' => (bool) $identity->getAccessToken(),
                'css_email' => $hasCredentials ? $identity->css_email : null,
            ]);
        } catch (\Throwable $e) {
            Log::error('[CSS CHECK CREDENTIALS ERROR]', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'has_credentials' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Setup CSS credentials for the current identity.
     */
    public function setupCredentials(Request $request)
    {
        try {
            $request->validate([
                'email' => 'required|email',
                'password' => 'required|string',
            ]);

            $identity = SolidIdentity::current();

            if (!$identity) {
                return response()->json([
                    'success' => false,
                    'error' => 'No Solid identity found. Please authenticate with OIDC first.',
                ], 401);
            }

            if (!$identity->getAccessToken()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Not authenticated. Please complete OIDC authentication first.',
                ], 401);
            }

            // Get WebID from ID token
            $tokenResponse = $identity->token_response;
            $idToken = data_get($tokenResponse, 'id_token');
            
            if (!$idToken) {
                return response()->json([
                    'success' => false,
                    'error' => 'No ID token available. Please re-authenticate.',
                ], 400);
            }

            // Create Solid client to extract WebID from ID token
            $solid = SolidClient::create(['identity' => $identity]);
            $webId = $solid->oidc->getWebIdFromIdToken($idToken);

            if (!$webId) {
                return response()->json([
                    'success' => false,
                    'error' => 'WebID not found in ID token.',
                ], 400);
            }

            // Get issuer from WebID
            $parsed = parse_url($webId);
            $issuer = $parsed['scheme'] . '://' . $parsed['host'];
            if (isset($parsed['port'])) {
                $issuer .= ':' . $parsed['port'];
            }

            Log::info('[CSS SETUP START]', [
                'identity_uuid' => $identity->uuid,
                'email' => $request->input('email'),
                'webId' => $webId,
                'issuer' => $issuer,
            ]);

            // Setup credentials
            $success = $this->cssAccountService->setupCredentials(
                $identity,
                $issuer,
                $request->input('email'),
                $request->input('password'),
                $webId
            );

            if (!$success) {
                return response()->json([
                    'success' => false,
                    'error' => 'Failed to setup CSS credentials. Please check your email and password.',
                ], 400);
            }

            return response()->json([
                'success' => true,
                'message' => 'CSS credentials configured successfully',
                'css_email' => $identity->css_email,
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('[CSS SETUP ERROR]', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Clear CSS credentials from the current identity.
     */
    public function clearCredentials(Request $request)
    {
        try {
            $identity = SolidIdentity::current();

            if (!$identity) {
                return response()->json([
                    'success' => false,
                    'error' => 'No Solid identity found.',
                ], 401);
            }

            $identity->update([
                'css_email' => null,
                'css_client_id' => null,
                'css_client_secret' => null,
                'css_client_resource_url' => null,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'CSS credentials cleared successfully',
            ]);
        } catch (\Throwable $e) {
            Log::error('[CSS CLEAR CREDENTIALS ERROR]', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }
}
