<?php

declare(strict_types=1);

namespace Resm;

use RuntimeException;

/**
 * The server is configured wrongly, in a way the administrator can fix.
 *
 * Separate from every other failure because it is the one class of error worth
 * showing on screen. An administrator with no shell cannot tail a log to find
 * out why a blank page appeared, and "it must be the config" is a bad place to
 * start an afternoon.
 *
 * Messages must therefore be safe to display: name the file and the problem,
 * never a credential.
 */
final class ConfigurationError extends RuntimeException
{
}
