<?php

namespace Fleetbase\Solid\Http\Controllers;

use Fleetbase\Http\Controllers\Controller as BaseController;
use Fleetbase\Solid\Client\SolidClient;
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

    public function play(Request $request)
    {
        $solidClient = new SolidClient();
        $solidClient->identity->registerClient('Fleetbase');
        // $authenticateResponse = $solidClient->identity->authenticate();
        // dd($authenticateResponse);

        // // cgeck for access token
        // dump($solidClient->identity->_getClientCredentials('Fleetbase'));
        
        // // create a pod
        // $response = $solidClient->post('pods', ['username' => 'ron', 'password' => '12345']);
        // dd($response->json());
    }
}
