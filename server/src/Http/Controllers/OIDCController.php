<?php

namespace Fleetbase\Solid\Http\Controllers;

use Fleetbase\Http\Controllers\Controller as BaseController;
use Fleetbase\Solid\Client\SolidClient;
use Fleetbase\Solid\Models\SolidIdentity;
use Fleetbase\Support\Utils;
use Illuminate\Http\Request;

class OIDCController extends BaseController
{
    public function completeRegistration(string $identifier, Request $request)
    {
        if ($request->has('code') && $identifier) {
            try {
                $identity = SolidIdentity::where('identifier', $identifier)->first();
                if ($identity) {
                    $solid = SolidClient::create(['identity' => $identity, 'restore' => true]);
                    $solid->oidc->authenticate();
                    $identity->update(['token_response' => $solid->oidc->getTokenResponse()]);
                }
            } catch (\Throwable $e) {
                return redirect(Utils::consoleUrl('solid-protocol', ['error' => $e->getMessage()]));
            }

            return redirect(Utils::consoleUrl('solid-protocol', $request->all()));
        }

        return redirect(Utils::consoleUrl('solid-protocol', ['error' => 'Unable to authenticate with provider']));
    }
}
