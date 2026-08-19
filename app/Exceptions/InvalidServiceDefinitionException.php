<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by ServiceDefinition when an imported JSON payload is not a
 * well-formed, current vpnforge service export. The message is already
 * translated at the throw site so it can be surfaced straight to the operator
 * in a Filament notification.
 */
class InvalidServiceDefinitionException extends RuntimeException
{
}
