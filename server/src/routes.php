<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::prefix(config('solid.api.routing.prefix', 'solid'))->namespace('Fleetbase\Solid\Http\Controllers')->group(
    function ($router) {
        /*
        |--------------------------------------------------------------------------
        | Solid Extension API Routes
        |--------------------------------------------------------------------------
        |
        | Primary internal routes for console.
        */
        $router->prefix(config('solid.api.routing.internal_prefix', 'int'))->group(
            function ($router) {
                $router->get('test', 'SolidController@play');
                $router->group(
                    ['prefix' => 'v1'],
                    function ($router) {
                        $router->group(['middleware' => ['fleetbase.protected']], function ($router) {
                        });

                        $router->group(['prefix' => 'oidc'], function ($router) {
                            $router->any('complete-registration', 'OIDCController@completeRegistration');
                        });
                    }
                );
            }
        );
    }
);
