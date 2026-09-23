<?php

namespace Fleetbase\Solid\Exceptions;

/**
 * Raised for every failure in the Solid-OIDC handshake.
 *
 * Replaces `Jumbojett\OpenIDConnectClientException`, which Solid used before the
 * client was brought in-tree. The name is kept so that any caller which already
 * catches "the OIDC exception" keeps compiling, and so the class hierarchy
 * (`\Exception`) is unchanged.
 *
 * Messages are written for an operator reading the log, never for the end user,
 * and must never carry a token, an authorization code, a client secret or a
 * private key.
 */
class OpenIDConnectClientException extends \Exception
{
}
