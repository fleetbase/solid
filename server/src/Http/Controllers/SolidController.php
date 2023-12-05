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
        dd($solidClient->identity);
        // $oidc = $solidClient->identity->registerClient('Fleetbase');
    }
}
