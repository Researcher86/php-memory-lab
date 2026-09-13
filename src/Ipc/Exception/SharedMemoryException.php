<?php

declare(strict_types=1);

namespace App\Ipc\Exception;

use RuntimeException;

/**
 * A shared-memory segment refused an operation: it could not be attached, it
 * has no room for the value, or the handle has already been detached.
 */
final class SharedMemoryException extends RuntimeException
{
}
