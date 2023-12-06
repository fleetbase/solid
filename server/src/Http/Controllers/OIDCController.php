<?php

namespace Fleetbase\Solid\Http\Controllers;

use Fleetbase\Http\Controllers\Controller as BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class OIDCController extends BaseController
{
    public function completeRegistration(Request $request)
    {
        $data = $request->all();
        Log::info('[OIDCController #completeRegistration]' . print_r($data, true));
        return response()->json($data);
    }
}
