<?php

namespace Fleetbase\Solid\Client\Identity;

use Fleetbase\Solid\Client\Solid;

class IdentityProvider {
    private Solid $client;

    public function __construct(Solid $client)
    {
        $this->client = $client;
    }

    public function registerAccount(array $params = [])
    {
        return $this->client->post('.account/password/register', $params);
    }
}