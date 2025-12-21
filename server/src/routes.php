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
                        $router->get('authenticate/{identifier}', 'SolidController@authenticate');
                        $router->group(['middleware' => ['fleetbase.protected']], function ($router) {
                            // Authentication status and management
                            $router->get('account', 'SolidController@getAccountIndex');
                            $router->get('request-authentication', 'SolidController@requestAuthentication');
                            $router->get('authentication-status', 'SolidController@getAuthenticationStatus');
                            $router->post('logout', 'SolidController@logout');

                            // Account and profile
                            $router->get('account', 'SolidController@getAccountIndex');
                            $router->get('profile', 'SolidController@getProfileData');

                            // Pod management routes
                            $router->get('pods', 'PodController@index');
                            $router->post('pods', 'PodController@create');
                            $router->get('pods/{podId}', 'PodController@show');
                            $router->delete('pods/{podId}', 'PodController@destroy');
                            $router->post('import-resources', 'PodController@importResources');
                            
                            // Container management
                            $router->get('containers', 'ContainerController@index');
                            $router->post('containers', 'ContainerController@create');
                            $router->delete('containers/{containerName}', 'ContainerController@destroy');

                            // // Vehicle sync routes
                            // $router->get('pods/vehicles-for-sync', 'PodController@getVehiclesForSync');
                            // $router->post('pods/sync-vehicles', 'PodController@syncVehicles');
                            // $router->get('pods/{podId}/sync-status', 'PodController@getSyncStatus');

                            // Resource sync endpoints
                            $router->get('sync-status', 'SolidController@getSyncStatus');
                            $router->post('sync-vehicles', 'SolidController@syncVehicles');
                            $router->post('sync-drivers', 'SolidController@syncDrivers');
                            $router->post('sync-orders', 'SolidController@syncOrders');
                            $router->post('sync-all', 'SolidController@syncAll');

                            // Server configuration
                            $router->get('server-config', 'SolidController@getServerConfig');
                            $router->post('server-config', 'SolidController@saveServerConfig');

                            // CSS Account Management
                            $router->get('css-credentials/check', 'CssAccountController@checkCredentials');
                            $router->post('css-credentials/setup', 'CssAccountController@setupCredentials');
                            $router->delete('css-credentials', 'CssAccountController@clearCredentials');
                        });

                        $router->group(['prefix' => 'oidc'], function ($router) {
                            $router->any('complete-registration/{identifier}', 'OIDCController@completeRegistration');
                        });
                    }
                );
            }
        );
    }
);
