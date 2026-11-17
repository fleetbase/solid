<?php

namespace Fleetbase\Solid\Tests\Unit;

use Fleetbase\Expansions\Request as RequestExpansion;
use Fleetbase\Http\Requests\AdminRequest;
use Fleetbase\Models\Setting;
use Fleetbase\Solid\Http\Controllers\SolidController;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;

// This package's dev install has the illuminate/* components core-api needs, but not
// laravel/framework, so core-api's base controller and form requests would not load.
// These stand-ins only apply when the real classes are missing.
foreach ([
    'Illuminate\Foundation\Auth\Access\AuthorizesRequests',
    'Illuminate\Foundation\Bus\DispatchesJobs',
    'Illuminate\Foundation\Validation\ValidatesRequests',
] as $trait) {
    if (!trait_exists($trait)) {
        $namespace = substr($trait, 0, strrpos($trait, '\\'));
        $name      = substr($trait, strrpos($trait, '\\') + 1);
        eval("namespace {$namespace}; trait {$name} {}");
    }
}
if (!class_exists('Illuminate\Foundation\Http\FormRequest')) {
    eval('namespace Illuminate\Foundation\Http; class FormRequest extends \Illuminate\Http\Request {}');
}
if (!function_exists('config')) {
    eval('function config($key = null, $default = null) { $config = \Illuminate\Container\Container::getInstance()->make("config"); return $key === null ? $config : $config->get($key, $default); }');
}
if (!function_exists('response')) {
    eval('function response() { return \Illuminate\Container\Container::getInstance()->make(\Illuminate\Contracts\Routing\ResponseFactory::class); }');
}

/**
 * Saving the Solid server settings from the admin console.
 *
 * The request's `server` input is read with core-api's `inputArray()` macro, because
 * Laravel 11+ ships a native `Request::array()` that would shadow a macro of that name.
 * The saved value is the defaults with the request's values on top.
 */
class SolidControllerServerConfigTest extends TestCase
{
    private Capsule $capsule;

    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        Container::setInstance($container);
        Facade::setFacadeApplication($container);

        $container->instance('config', new Repository([
            'solid' => ['server' => ['host' => 'http://solid', 'port' => 3000, 'secure' => false]],
        ]));
        $container->instance(ResponseFactory::class, new class {
            public function json($data = [], $status = 200, array $headers = [], $options = 0): JsonResponse
            {
                return new JsonResponse($data, $status, $headers, $options);
            }
        });

        // Settings write to the `mysql` connection by default; here that is in-memory SQLite.
        $this->capsule = new Capsule($container);
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''], 'mysql');
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
        $container->instance('db', $this->capsule->getDatabaseManager());
        $this->capsule->schema('mysql')->create('settings', function ($table) {
            $table->increments('id');
            $table->string('key')->unique();
            $table->text('value')->nullable();
        });

        Request::macro('inputArray', (new RequestExpansion())->inputArray());
    }

    protected function tearDown(): void
    {
        Model::unsetConnectionResolver();
        Request::flushMacros();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);

        parent::tearDown();
    }

    public function testSavingTheServerConfigMergesTheRequestOverTheDefaultsAndStoresIt(): void
    {
        $response = $this->controller()->saveServerConfig($this->request(['server' => ['host' => 'https://pods.example', 'secure' => true]]));

        $expected = ['host' => 'https://pods.example', 'port' => 3000, 'secure' => true];
        $this->assertSame($expected, $response->getData(true));
        // Setting's value is cast from JSON, so the stored row reads back as the array.
        $this->assertSame($expected, Setting::where('key', 'system.solid.server')->value('value'));
    }

    public function testARequestWithoutServerSettingsStoresTheDefaults(): void
    {
        $response = $this->controller()->saveServerConfig($this->request([]));

        $this->assertSame(['host' => 'http://solid', 'port' => 3000, 'secure' => false], $response->getData(true));
        $this->assertSame(1, Setting::where('key', 'system.solid.server')->count());
    }

    private function controller(): SolidController
    {
        // The pod service isn't used to save settings, and building it needs a Solid server.
        return (new \ReflectionClass(SolidController::class))->newInstanceWithoutConstructor();
    }

    private function request(array $input): AdminRequest
    {
        return AdminRequest::create('/solid/int/v1/server-config', 'POST', $input);
    }
}
