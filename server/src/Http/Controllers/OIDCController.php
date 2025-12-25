<?php

namespace Fleetbase\Solid\Http\Controllers;

use Fleetbase\Http\Controllers\Controller as BaseController;
use Fleetbase\Solid\Client\SolidClient;
use Fleetbase\Solid\Models\SolidIdentity;
use Fleetbase\Support\Utils;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OIDCController extends BaseController
{
    public function completeRegistration(string $identifier, Request $request)
    {
        Log::info('[OIDC COMPLETE REGISTRATION]', [
            'identifier' => $identifier,
            'request'    => $request->all(),
            'has_code'   => $request->filled('code'),
            'has_state'  => $request->filled('state'),
        ]);

        if ($request->filled('code') && $identifier) {
            try {
                $identity = SolidIdentity::where('identifier', $identifier)->first();

                if (!$identity) {
                    throw new \Exception('Identity not found for identifier: ' . $identifier);
                }

                Log::info('[OIDC IDENTITY FOUND]', ['identity_id' => $identity->id]);

                $solid = SolidClient::create(['identity' => $identity, 'restore' => true]);

                // Get the authorization code
                $code = $request->input('code');

                // CRITICAL: Use requestTokens() instead of authenticate()
                $tokenResponse = $solid->oidc->exchangeCodeForTokens($code);

                if (isset($tokenResponse->error)) {
                    throw new \Exception($tokenResponse->error_description ?? $tokenResponse->error);
                }

                Log::info('[OIDC TOKEN EXCHANGE SUCCESS]', ['has_access_token' => isset($tokenResponse->access_token)]);

                // Save the token response
                $identity->update(['token_response' => (array) $tokenResponse]);

                // Redirect to success page
                return redirect(Utils::consoleUrl('solid-protocol', ['success' => 'authenticated']));
            } catch (\Throwable $e) {
                Log::error('[OIDC ERROR]', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);

                return redirect(Utils::consoleUrl('solid-protocol', ['error' => $e->getMessage()]));
            }
        }

        Log::warning('[OIDC MISSING PARAMS]', ['has_code' => $request->filled('code'), 'identifier' => $identifier]);

        return redirect(Utils::consoleUrl('solid-protocol', ['error' => 'Missing authorization code or identifier']));
    }
}
