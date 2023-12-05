<?php

namespace Fleetbase\Solid\Client;

use Fleetbase\Solid\Client\Identity\IdentityProvider;

class Solid
{
    private string $host = 'localhost';
    private int $port = 3000;
    private bool $secure = true;
    protected IdentityProvider $identity;

    public function __construct(array $options = [])
    {
        $this->host = config('solid.server.host',  data_get($options, 'host'));
        $this->port = (int) config('solid.server.port',  data_get($options, 'port'));
        $this->secure = (bool) config('solid.server.secure',  data_get($options, 'secure'));
        $this->identity = new IdentityProvider($this);
    }

    protected function getServerUrl(): string 
    {
        $protocol = $this->secure ? 'https' : 'http';
        return "{$protocol}://{$this->host}:{$this->port}";
    }

    public static function create(array $options = [])
    {
        return new static($options);
    }
}