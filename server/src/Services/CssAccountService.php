<?php

namespace Fleetbase\Solid\Services;

use Fleetbase\Solid\Client\OpenIDConnectClient;
use Fleetbase\Solid\Models\SolidIdentity;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CssAccountService
{
    /**
     * Login to CSS account management and get authorization token.
     *
     * @param string $issuer The CSS server URL
     * @param string $email CSS account email
     * @param string $password CSS account password
     * @return string|null Authorization token
     */
    public function login(string $issuer, string $email, string $password): ?string
    {
        try {
            // Step 1: Get account API controls
            $indexResponse = Http::get("{$issuer}/.account/");
            
            if (!$indexResponse->successful()) {
                Log::error('[CSS ACCOUNT] Failed to get account index', [
                    'status' => $indexResponse->status(),
                    'body' => $indexResponse->body(),
                ]);
                return null;
            }

            $controls = $indexResponse->json('controls');
            
            if (!isset($controls['password']['login'])) {
                Log::error('[CSS ACCOUNT] Password login endpoint not found in controls');
                return null;
            }

            // Step 2: Login with email/password
            $loginResponse = Http::post($controls['password']['login'], [
                'email' => $email,
                'password' => $password,
            ]);

            if (!$loginResponse->successful()) {
                Log::error('[CSS ACCOUNT] Login failed', [
                    'status' => $loginResponse->status(),
                    'body' => $loginResponse->body(),
                ]);
                return null;
            }

            $authorization = $loginResponse->json('authorization');
            
            Log::info('[CSS ACCOUNT] Login successful');
            
            return $authorization;
        } catch (\Exception $e) {
            Log::error('[CSS ACCOUNT] Login exception', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Create a client credentials token for the authenticated account.
     *
     * @param string $issuer The CSS server URL
     * @param string $authorization CSS-Account-Token from login
     * @param string $webId The WebID to associate with the token
     * @param string $tokenName Name for the client credentials token
     * @return array|null Token data with id, secret, and resource URL
     */
    public function createClientCredentials(string $issuer, string $authorization, string $webId, string $tokenName = 'fleetbase-client'): ?array
    {
        try {
            // Step 1: Get updated controls with authorization
            $indexResponse = Http::withHeaders([
                'Authorization' => "CSS-Account-Token {$authorization}",
            ])->get("{$issuer}/.account/");

            if (!$indexResponse->successful()) {
                Log::error('[CSS ACCOUNT] Failed to get authenticated account index', [
                    'status' => $indexResponse->status(),
                    'body' => $indexResponse->body(),
                ]);
                return null;
            }

            $controls = $indexResponse->json('controls');
            
            if (!isset($controls['account']['clientCredentials'])) {
                Log::error('[CSS ACCOUNT] Client credentials endpoint not found in controls');
                return null;
            }

            // Step 2: Create client credentials token
            $tokenResponse = Http::withHeaders([
                'Authorization' => "CSS-Account-Token {$authorization}",
                'Content-Type' => 'application/json',
            ])->post($controls['account']['clientCredentials'], [
                'name' => $tokenName,
                'webId' => $webId,
            ]);

            if (!$tokenResponse->successful()) {
                Log::error('[CSS ACCOUNT] Failed to create client credentials', [
                    'status' => $tokenResponse->status(),
                    'body' => $tokenResponse->body(),
                ]);
                return null;
            }

            $tokenData = $tokenResponse->json();
            
            Log::info('[CSS ACCOUNT] Client credentials created successfully', [
                'id' => $tokenData['id'] ?? null,
                'resource' => $tokenData['resource'] ?? null,
            ]);

            return $tokenData;
        } catch (\Exception $e) {
            Log::error('[CSS ACCOUNT] Create client credentials exception', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Get an access token using client credentials.
     *
     * @param string $issuer The CSS server URL
     * @param string $clientId Client credentials ID
     * @param string $clientSecret Client credentials secret
     * @param OpenIDConnectClient $oidcClient OIDC client for DPoP
     * @return string|null Access token
     */
    public function getAccessToken(string $issuer, string $clientId, string $clientSecret, OpenIDConnectClient $oidcClient): ?string
    {
        try {
            // Get token endpoint from OIDC discovery
            $issuerUrl = rtrim($issuer, '/');
            $discoveryResponse = Http::get("{$issuerUrl}/.well-known/openid-configuration");
            
            if (!$discoveryResponse->successful()) {
                Log::error('[CSS ACCOUNT] Failed to get OIDC configuration');
                return null;
            }

            $tokenEndpoint = $discoveryResponse->json('token_endpoint');
            
            if (!$tokenEndpoint) {
                Log::error('[CSS ACCOUNT] Token endpoint not found in OIDC configuration');
                return null;
            }

            // Create DPoP proof for token request
            $dpop = $oidcClient->createDPoP('POST', $tokenEndpoint);

            // Encode credentials
            $authString = urlencode($clientId) . ':' . urlencode($clientSecret);
            $authHeader = 'Basic ' . base64_encode($authString);

            // Request access token
            $tokenResponse = Http::withHeaders([
                'Authorization' => $authHeader,
                'Content-Type' => 'application/x-www-form-urlencoded',
                'DPoP' => $dpop,
            ])->asForm()->post($tokenEndpoint, [
                'grant_type' => 'client_credentials',
                'scope' => 'openid webid',
            ]);

            if (!$tokenResponse->successful()) {
                Log::error('[CSS ACCOUNT] Failed to get access token', [
                    'status' => $tokenResponse->status(),
                    'body' => $tokenResponse->body(),
                ]);
                return null;
            }

            $accessToken = $tokenResponse->json('access_token');
            
            Log::info('[CSS ACCOUNT] Access token obtained successfully');
            
            return $accessToken;
        } catch (\Exception $e) {
            Log::error('[CSS ACCOUNT] Get access token exception', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Setup CSS credentials for a Solid identity.
     * This is the main method that orchestrates the entire flow.
     *
     * @param SolidIdentity $identity The Solid identity
     * @param string $issuer The CSS server URL
     * @param string $email CSS account email
     * @param string $password CSS account password
     * @param string $webId The WebID
     * @return bool Success status
     */
    public function setupCredentials(SolidIdentity $identity, string $issuer, string $email, string $password, string $webId): bool
    {
        try {
            Log::info('[CSS ACCOUNT] Starting credentials setup', [
                'identity_uuid' => $identity->uuid,
                'email' => $email,
                'webId' => $webId,
            ]);

            // Step 1: Login to account management
            $authorization = $this->login($issuer, $email, $password);
            
            if (!$authorization) {
                Log::error('[CSS ACCOUNT] Failed to login');
                return false;
            }

            // Step 2: Create client credentials
            $tokenData = $this->createClientCredentials($issuer, $authorization, $webId);
            
            if (!$tokenData || !isset($tokenData['id']) || !isset($tokenData['secret'])) {
                Log::error('[CSS ACCOUNT] Failed to create client credentials');
                return false;
            }

            // Step 3: Store credentials in identity
            $identity->update([
                'css_email' => $email,
                'css_password' => encrypt($password), // Encrypt and store the password
                'css_client_id' => $tokenData['id'],
                'css_client_secret' => encrypt($tokenData['secret']), // Encrypt the secret
                'css_client_resource_url' => $tokenData['resource'] ?? null,
            ]);

            Log::info('[CSS ACCOUNT] Credentials setup completed successfully', [
                'identity_uuid' => $identity->uuid,
            ]);

            return true;
        } catch (\Exception $e) {
            Log::error('[CSS ACCOUNT] Setup credentials exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Check if identity has CSS credentials configured.
     *
     * @param SolidIdentity $identity
     * @return bool
     */
    public function hasCredentials(SolidIdentity $identity): bool
    {
        return !empty($identity->css_client_id) && !empty($identity->css_client_secret);
    }
}
