<?php

declare(strict_types=1);

namespace App\Ipc\Exception;

use RuntimeException;

/**
 * A semaphore operation failed. Rare in practice and never ignorable: an
 * acquire that did not happen means the critical section is running
 * unprotected.
 */
final class SemaphoreException extends RuntimeException
{
}
