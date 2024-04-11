<?php

namespace Fleetbase\Solid\Http\Controllers;

use Fleetbase\Http\Controllers\Controller as BaseController;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Models\Setting;
use Fleetbase\Solid\Client\SolidClient;
use Fleetbase\Solid\Models\SolidIdentity;
use Fleetbase\Support\Utils;
use Illuminate\Http\Request;

class SolidController extends BaseController
{
    /**
     * Welcome message only.
     */
    public function hello()
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
        $identity = SolidIdentity::initialize();
        $oidc     = SolidClient::create(['identity' => $identity])->oidc->register(['saveCredentials' => true]);

        return $oidc->authenticate();
    }

    public function getAccountIndex()
    {
        $solidIdentity   = SolidIdentity::current();
        $accountResponse = $solidIdentity->request('get', '.account');
        dd($accountResponse->json());
    }

    public function play(Request $request)
    {
        $action      = $request->input('action');
        $identity    = SolidIdentity::first();
        $solid       = new SolidClient(['identity' => $identity]);

        if ($action === 'register_client') {
            $registeredClient = $solid->oidc->register();
        }

        if ($action === 'restore') {
            $solid->identity->restoreClientCredentials();
        }

        if ($action === 'login') {
            $loginResponse = $solid->post(
                '.account/login/password',
                [
                    'email'    => 'ron@fleetbase.io',
                    'password' => 'Zerina30662!',
                    'remember' => true,
                ],
                [
                'withoutAuth' => true,
                'headers'     => [
                    'Cookie' => '_interaction=TDQMh2DWuC8wZvkEB2n_G; _interaction.sig=3HHA_FUVo7Cw9up2keCJ7IaQJws; _session.legacy=jdxTxnTGmvWx2ECaiwYeP; _session.legacy.sig=EUGYX6DAKtBNQqZN5PGcbIJ-5ac',
                    ],
                ]
            );
            dump($loginResponse->json());
        }

        if ($action === 'test') {
            // $solidClient->identity->restoreTokens();
            dump('AccessToken: ' . $solid->identity->getAccessToken());
            dump('IdToken: ' . $solid->identity->getIdToken());

            $indexResponse = $solid->get('.account', [], ['withoutAuth' => true]);
            dump($indexResponse->json());

            // $createPodResponse = $solidClient->post('rondon/blog');
            // dump($createPodResponse->json());

            // $createFileResponse = $solidClient->put('test.txt', ['test'], ['content-type' => 'text/plain']);
            // dump($createFileResponse->json());
        }
    }
}
