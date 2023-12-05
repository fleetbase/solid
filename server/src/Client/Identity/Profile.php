<?php

namespace Fleetbase\Solid\Client\Identity;

use EasyRdf\Graph;

class Profile
{
    private Graph $graph;
    public string $webId;
    public string $name;
    public string $email;

    public function __construct(Graph $graph)
    {
        $this->graph = $graph;
    }

}