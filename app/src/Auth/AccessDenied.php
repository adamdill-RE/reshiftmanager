<?php

declare(strict_types=1);

namespace Resm\Auth;

use RuntimeException;

/**
 * Thrown when a request reaches a handler it is not permitted to run. The
 * front controller turns it into a 403, and the message never names what the
 * user was not allowed to see.
 */
final class AccessDenied extends RuntimeException
{
}
