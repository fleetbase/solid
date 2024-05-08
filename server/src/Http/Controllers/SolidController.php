<?php

namespace Fleetbase\Solid\Http\Controllers;

use Fleetbase\Http\Controllers\Controller as BaseController;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Models\Setting;
use Fleetbase\Solid\Client\SolidClient;
use Fleetbase\Solid\Models\SolidIdentity;
use Fleetbase\Solid\Support\Utils;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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

    public function getPods(Request $request)
    {
        // Retrieve search and sort parameters from the request
        $query = $request->searchQuery();
        $sort  = $request->input('sort', '-created_at');
        $id    = $request->input('id');
        $slug  = $request->input('slug');

        // Collection of pods data
        $pods = json_decode(file_get_contents(base_path('vendor/fleetbase/solid-api/server/data/pods.json')));

        // Get single content from pod via slug
        if ($slug) {
            $result = Utils::searchPods($pods, 'slug', $slug);

            return response()->json($result);
        }

        // Get a single item via ID
        if ($id && is_array($pods)) {
            $result = Utils::searchPods($pods, 'id', $id);
            if ($result && $query) {
                $result->contents = array_values(
                    array_filter(
                        data_get($result, 'contents', []),
                        function ($content) use ($query) {
                            return Str::contains(strtolower(data_get($content, 'name')), strtolower($query));
                        }
                    )
                );
            }

            return response()->json($result);
        }

        // Filtering by search query
        if ($query) {
            $pods = array_filter($pods, function ($pod) use ($query) {
                return Str::contains(strtolower(data_get($pod, 'name')), strtolower($query));
            });
        }

        // Determine sorting direction and key
        $sortDesc = substr($sort, 0, 1) === '-';
        $sortKey  = ltrim($sort, '-');

        // Sorting by specified field
        $pods = collect($pods)->sortBy($sortKey, SORT_REGULAR, $sortDesc);

        return response()->json($pods->values());
    }
}
