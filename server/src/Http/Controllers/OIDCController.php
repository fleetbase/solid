<?php

namespace Fleetbase\Solid\Http\Controllers;

use Fleetbase\Http\Controllers\Controller as BaseController;
use Fleetbase\Solid\Client\SolidClient;
use Fleetbase\Solid\Models\SolidIdentity;
use Fleetbase\Support\Utils;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OIDCController extends BaseController
{
    /**
     * The Solid identity provider's redirect back from the authorization endpoint.
     *
     * The `state` is now forwarded to the client and checked there, as is the
     * `id_token`'s signature and claims. Neither used to happen: the callback
     * simply exchanged whatever code it was handed.
     */
    public function completeRegistration(string $identifier, Request $request): RedirectResponse
    {
        Log::info('[Solid OIDC] Authorization callback received.', [
            'identifier' => $identifier,
            'has_code'   => $request->filled('code'),
            'has_state'  => $request->filled('state'),
        ]);

        try {
            // The provider reports a refused or failed authorization here rather
            // than with a code. Surfacing it is what turns "nothing happened" into
            // an actionable message.
            if ($request->filled('error')) {
                throw new \Exception(self::stringInput($request, 'error_description') ?? self::stringInput($request, 'error') ?? 'The identity provider refused the authorization request.');
            }

            if (!$request->filled('code')) {
                throw new \Exception('The authorization callback carried no code.');
            }

            $identity = SolidIdentity::where('identifier', $identifier)->first();

            if (!$identity) {
                throw new \Exception('No Solid identity matches this authorization request.');
            }

            $solid = SolidClient::create(['identity' => $identity, 'restore' => true]);

            $tokenResponse = $solid->oidc->exchangeCodeForTokens(
                (string) self::stringInput($request, 'code'),
                self::stringInput($request, 'state')
            );

            // The verified claims are persisted alongside the tokens so that later
            // reads of the WebID do not have to trust an unverified copy of an
            // id_token that has since expired.
            $identity->storeTokenResponse($tokenResponse, $solid->oidc->getVerifiedClaims());

            return redirect(Utils::consoleUrl('solid-protocol', ['success' => 'authenticated']));
        } catch (\Throwable $e) {
            Log::error('[Solid OIDC] Authorization callback failed.', [
                'identifier' => $identifier,
                'error'      => $e->getMessage(),
            ]);

            return redirect(Utils::consoleUrl('solid-protocol', ['error' => $e->getMessage()]));
        }
    }

    /**
     * A request parameter, or null unless it arrived as a non-empty string.
     *
     * The callback query is attacker-influenced, so a parameter sent as an array
     * must not be stringified into the handshake.
     */
    private static function stringInput(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
